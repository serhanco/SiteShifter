<?php

namespace SiteShifter\Package;

use SiteShifter\Database\Connection;
use SiteShifter\Database\Verifier;

/**
 * Facts about the source site, stored as siteshifter.json in the package.
 * The installer uses them for pre-flight checks, the URL/path replacement
 * and the row-count comparison after import.
 *
 * Built from the database and the file system only, so it works with or
 * without WordPress loaded.
 */
class Manifest
{
    public const FORMAT = 1;
    public const FILE = 'siteshifter.json';
    public const SQL = 'database.sql';

    public static function build(Connection $db, string $prefix, string $abspath, array $tables, array $extra = []): array
    {
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/') . '/';
        $option = static function (string $name) use ($db, $prefix): ?string {
            $value = $db->value('SELECT option_value FROM ' . Connection::ident($prefix . 'options') . ' WHERE option_name = ' . $db->quote($name) . ' LIMIT 1');

            return $value === null ? null : (string) $value;
        };

        $active = @unserialize((string) $option('active_plugins'), ['allowed_classes' => false]);
        $active = is_array($active) ? array_values(array_filter($active, 'is_string')) : [];
        $contentDir = $abspath . 'wp-content';
        $themes = array_values(array_unique(array_filter([$option('stylesheet'), $option('template')])));

        $requires = '0';
        $plugins = [];
        foreach ($active as $basename) {
            $headers = self::headers($contentDir . '/plugins/' . $basename);
            $plugins[] = ['file' => $basename, 'name' => $headers['Plugin Name'] ?? $basename, 'version' => $headers['Version'] ?? null, 'requires_php' => $headers['Requires PHP'] ?? null, 'requires_wp' => $headers['Requires at least'] ?? null];
            if (!empty($headers['Requires PHP']) && version_compare($headers['Requires PHP'], $requires, '>')) {
                $requires = $headers['Requires PHP'];
            }
        }
        foreach ($themes as $theme) {
            $headers = self::headers($contentDir . '/themes/' . $theme . '/style.css');
            if (!empty($headers['Requires PHP']) && version_compare($headers['Requires PHP'], $requires, '>')) {
                $requires = $headers['Requires PHP'];
            }
        }

        $collations = [];
        foreach ($db->rows('SHOW TABLE STATUS') as $status) {
            if (in_array($status['Name'], $tables, true) && !empty($status['Collation'])) {
                $collations[$status['Collation']] = true;
            }
        }
        foreach ($tables as $table) {
            foreach ($db->rows('SHOW FULL COLUMNS FROM ' . Connection::ident($table)) as $col) {
                if (!empty($col['Collation'])) {
                    $collations[$col['Collation']] = true;
                }
            }
        }

        $all = $db->tables();
        $foreign = array_values(array_filter($all, static function ($t) use ($prefix) {
            return strpos($t, $prefix) !== 0;
        }));

        return array_replace_recursive([
            'format' => self::FORMAT,
            'generator' => 'SiteShifter',
            'created' => gmdate('c'),
            'site' => [
                'url' => $option('siteurl'),
                'home' => $option('home'),
                'abspath' => $abspath,
                'table_prefix' => $prefix,
                'wp_version' => self::wpVersion($abspath),
                'multisite' => in_array($prefix . 'blogs', $all, true) && in_array($prefix . 'site', $all, true),
            ],
            'php' => ['version' => PHP_VERSION, 'requires' => $requires],
            'db' => [
                'server' => $db->serverVersion(),
                'tables' => (new Verifier($db))->rowCounts($tables),
                'foreign_tables' => $foreign,
                'collations' => array_keys($collations),
                'largest_row' => 0,
                'db_version' => $option('db_version'),
            ],
            'plugins' => ['active' => $active, 'details' => $plugins],
            'themes' => $themes,
            'files' => ['count' => 0, 'bytes' => 0],
        ], $extra);
    }

    public static function wpVersion(string $abspath): ?string
    {
        return self::versionFile($abspath)['wp_version'];
    }

    /**
     * Reads wp-includes/version.php without executing it.
     *
     * @return array{wp_version:?string,wp_db_version:?int}
     */
    public static function versionFile(string $abspath): array
    {
        $file = rtrim($abspath, '/') . '/wp-includes/version.php';
        $code = is_readable($file) ? (string) file_get_contents($file) : '';

        return self::parseVersionFile($code);
    }

    /** @return array{wp_version:?string,wp_db_version:?int} */
    public static function parseVersionFile(string $code): array
    {
        $version = preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $m) ? $m[1] : null;
        $db = preg_match('/\$wp_db_version\s*=\s*(\d+)/', $code, $m) ? (int) $m[1] : null;

        return ['wp_version' => $version, 'wp_db_version' => $db];
    }

    /** Reads WordPress-style file headers ("Plugin Name:", "Requires PHP:") from the first 8 KB. */
    public static function headers(string $file): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $fh = fopen($file, 'rb');
        $head = (string) fread($fh, 8192);
        fclose($fh);
        $out = [];
        foreach (['Plugin Name', 'Theme Name', 'Version', 'Requires PHP', 'Requires at least'] as $key) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($key, '/') . ':(.*)$/mi', $head, $m)) {
                $out[$key] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
            }
        }

        return $out;
    }

    /** The URL/host strings that identify the old site, for the leftover scan. */
    public static function oldHosts(array $manifest): array
    {
        $hosts = [];
        foreach (['url', 'home'] as $key) {
            $url = $manifest['site'][$key] ?? null;
            if ($url && preg_match('#^https?://(.+?)/*$#i', $url, $m)) {
                $hosts[] = strtolower($m[1]);
            }
        }

        return array_values(array_unique($hosts));
    }
}

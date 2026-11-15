<?php

namespace SiteShifter\Installer;

/**
 * Produces wp-config.php for the new server: either the site's own file
 * with the database settings, table prefix and URLs swapped, or a fresh
 * one with new security keys when the export did not include it.
 */
class ConfigWriter
{
    /**
     * @param array $db [host, user, pass, name, charset?]
     */
    public function write(?string $source, array $db, string $prefix, string $newUrl, string $dir): string
    {
        $newUrl = rtrim($newUrl, '/');
        $values = [
            'DB_NAME' => $db['name'],
            'DB_USER' => $db['user'],
            'DB_PASSWORD' => $db['pass'],
            'DB_HOST' => $db['host'],
        ];

        if ($source === null || !preg_match('/DB_NAME/', $source)) {
            return $this->fresh($values, $prefix, $db['charset'] ?? 'utf8mb4');
        }

        $config = $source;
        foreach ($values as $name => $value) {
            $config = self::setDefine($config, $name, $value);
        }
        $config = preg_replace(
            '/^(\s*\$table_prefix\s*=\s*)([\'"]).*?\2\s*;/m',
            '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], self::php($prefix)) . ';',
            $config,
            1
        );
        foreach (['WP_HOME', 'WP_SITEURL'] as $name) {
            if (self::hasDefine($config, $name)) {
                $config = self::setDefine($config, $name, $newUrl);
            }
        }
        // A cookie domain for the old site would log everybody out on the new one.
        $config = preg_replace('/^.*define\(\s*[\'"]COOKIE_DOMAIN[\'"].*$/m', '// COOKIE_DOMAIN removed by SiteShifter', $config);

        return $config;
    }

    public static function hasDefine(string $config, string $name): bool
    {
        return (bool) preg_match('/define\(\s*[\'"]' . $name . '[\'"]\s*,/', $config);
    }

    /** Replaces the value of define('NAME', ...); whatever expression it had. */
    public static function setDefine(string $config, string $name, string $value): string
    {
        $pattern = '/define\(\s*([\'"])' . preg_quote($name, '/') . '\1\s*,\s*(?:[^;]|;(?!\s*$))*?\)\s*;/m';
        $replacement = "define( '" . $name . "', " . self::php($value) . ' );';
        if (preg_match($pattern, $config)) {
            return preg_replace_callback($pattern, static function () use ($replacement) {
                return $replacement;
            }, $config, 1);
        }
        // Not defined: add it before WordPress is loaded.
        $marker = "/* That's all, stop editing!";
        $pos = strpos($config, $marker);
        if ($pos === false) {
            $pos = strpos($config, "require_once");
        }
        if ($pos === false) {
            return $config . "\n" . $replacement . "\n";
        }

        return substr($config, 0, $pos) . $replacement . "\n\n" . substr($config, $pos);
    }

    public static function php(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private function fresh(array $values, string $prefix, string $charset): string
    {
        $lines = ["<?php", '/**', ' * WordPress configuration — written by SiteShifter.', ' */', ''];
        foreach ($values as $name => $value) {
            $lines[] = "define( '{$name}', " . self::php($value) . ' );';
        }
        $lines[] = "define( 'DB_CHARSET', " . self::php($charset) . ' );';
        $lines[] = "define( 'DB_COLLATE', '' );";
        $lines[] = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
            $lines[] = "define( '{$key}', " . self::php(self::salt()) . ' );';
        }
        $lines[] = '';
        $lines[] = '$table_prefix = ' . self::php($prefix) . ';';
        $lines[] = '';
        $lines[] = "define( 'WP_DEBUG', false );";
        $lines[] = '';
        $lines[] = "/* That's all, stop editing! Happy publishing. */";
        $lines[] = '';
        $lines[] = "if ( ! defined( 'ABSPATH' ) ) {";
        $lines[] = "\tdefine( 'ABSPATH', __DIR__ . '/' );";
        $lines[] = '}';
        $lines[] = '';
        $lines[] = "require_once ABSPATH . 'wp-settings.php';";

        return implode("\n", $lines) . "\n";
    }

    private static function salt(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|';
        $out = '';
        for ($i = 0; $i < 64; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }
}

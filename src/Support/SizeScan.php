<?php

namespace SiteShifter\Support;

use SiteShifter\Package\Archiver;

/**
 * Measures how big each part of a site is, in resumable slices, so a huge
 * uploads folder can be measured without hitting a time limit and with a
 * live percentage. Follows the core pattern: run($state, $deadline) -> $state.
 */
class SizeScan
{
    public const AREAS = ['uploads', 'plugins', 'themes', 'content_other', 'core'];

    /** @var string */
    private $abspath;

    /** @var string[] */
    private $excludes;

    /**
     * @param string[] $excludes folders relative to the WordPress root that are never counted
     */
    public function __construct(string $abspath, array $excludes = Archiver::DEFAULT_EXCLUDES)
    {
        $this->abspath = rtrim(str_replace('\\', '/', $abspath), '/');
        $this->excludes = array_map(static function ($e) {
            return trim(str_replace('\\', '/', $e), '/');
        }, $excludes);
    }

    public static function initialState(): array
    {
        return [
            'stack' => [''],
            'bytes' => array_fill_keys(self::AREAS, 0),
            'files' => array_fill_keys(self::AREAS, 0),
            'dirs' => 0,
            'pct' => 0,
            'done' => false,
        ];
    }

    public static function percent(array $state): int
    {
        return $state['done'] ? 100 : (int) ($state['pct'] ?? 0);
    }

    public function run(array $state, float $deadline): array
    {
        $first = true; // always make progress, even when the deadline has already passed
        while ($state['stack'] !== [] && ($first || microtime(true) < $deadline)) {
            $first = false;
            $rel = array_pop($state['stack']);
            $state['dirs']++;
            $names = @scandir($this->abspath . ($rel === '' ? '' : '/' . $rel));
            foreach ((array) $names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $rel === '' ? $name : $rel . '/' . $name;
                if ($this->excluded($path)) {
                    continue;
                }
                $full = $this->abspath . '/' . $path;
                if (is_link($full) && is_dir($full)) {
                    continue; // never follow directory symlinks
                }
                if (is_dir($full)) {
                    $state['stack'][] = $path;
                } elseif (is_file($full)) {
                    $area = self::area($path);
                    $state['bytes'][$area] += (int) @filesize($full);
                    $state['files'][$area]++;
                }
            }
            $left = count($state['stack']);
            $state['pct'] = max($state['pct'], min(95, (int) floor(100 * $state['dirs'] / max(1, $state['dirs'] + $left))));
        }
        if ($state['stack'] === []) {
            $state['done'] = true;
        }

        return $state;
    }

    public function runAll(): array
    {
        return $this->run(self::initialState(), INF);
    }

    public static function area(string $path): string
    {
        if (strpos($path, 'wp-content/uploads/') === 0) {
            return 'uploads';
        }
        if (strpos($path, 'wp-content/plugins/') === 0 || strpos($path, 'wp-content/mu-plugins/') === 0) {
            return 'plugins';
        }
        if (strpos($path, 'wp-content/themes/') === 0) {
            return 'themes';
        }

        return strpos($path, 'wp-content/') === 0 ? 'content_other' : 'core';
    }

    private function excluded(string $path): bool
    {
        foreach ($this->excludes as $e) {
            if ($path === $e || strpos($path, $e . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /** Sum of the sizes of the tables with this prefix, from the server's own statistics (no scanning). */
    public static function databaseBytes(\SiteShifter\Database\Connection $db, array $tables): int
    {
        if ($tables === []) {
            return 0;
        }
        $list = implode(', ', array_map([$db, 'quote'], $tables));

        return (int) $db->value('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $list . ')');
    }
}

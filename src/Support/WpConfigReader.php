<?php

namespace SiteShifter\Support;

/**
 * Reads database settings and the table prefix from wp-config.php
 * without executing it.
 */
class WpConfigReader
{
    /** @return array{host:string,user:string,pass:string,name:string,charset:string,prefix:string} */
    public static function read(string $file): array
    {
        if (!is_readable($file)) {
            throw new \RuntimeException("Cannot read {$file}");
        }

        return self::parse((string) file_get_contents($file));
    }

    public static function parse(string $code): array
    {
        $get = static function (string $name) use ($code): ?string {
            if (preg_match('/define\(\s*[\'"]' . $name . '[\'"]\s*,\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*\)/s', $code, $m)) {
                return $m[1] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $m[2]) : stripcslashes($m[2]);
            }

            return null;
        };
        $prefix = preg_match('/\$table_prefix\s*=\s*([\'"])([^\'"]*)\1/', $code, $m) ? $m[2] : 'wp_';
        $config = [
            'host' => $get('DB_HOST') ?? 'localhost',
            'user' => $get('DB_USER'),
            'pass' => $get('DB_PASSWORD') ?? '',
            'name' => $get('DB_NAME'),
            'charset' => $get('DB_CHARSET') ?: 'utf8mb4',
            'prefix' => $prefix,
        ];
        if ($config['name'] === null || $config['user'] === null) {
            throw new \RuntimeException('DB_NAME / DB_USER could not be read from wp-config.php (are they set with getenv()?). Pass the --db-* options instead.');
        }

        return $config;
    }
}

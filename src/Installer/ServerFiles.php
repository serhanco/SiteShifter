<?php

namespace SiteShifter\Installer;

/**
 * Fixes server files that hard-code the old location and would otherwise
 * crash the new site: PHP settings that prepend a firewall file from the
 * old path (Wordfence), and .htaccess rewrite rules for a different folder.
 */
class ServerFiles
{
    /** @var string */
    private $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    /** @return string[] log lines */
    public function fix(?string $oldPath, string $newUrl): array
    {
        $log = [];
        $oldPath = $oldPath ? rtrim(str_replace('\\', '/', $oldPath), '/') : null;
        foreach (['.user.ini', 'php.ini', '.htaccess', 'wordfence-waf.php'] as $file) {
            $path = $this->dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $original = (string) file_get_contents($path);
            $content = $original;
            if ($oldPath && $oldPath !== $this->dir) {
                $content = str_replace($oldPath, $this->dir, $content);
            }
            $content = $this->disableMissingPrepend($content);
            if ($file === '.htaccess') {
                $content = $this->fixRewriteBase($content, $newUrl);
            }
            if ($content !== $original) {
                file_put_contents($path, $content);
                $log[] = "Updated {$file} for the new server";
            }
        }

        if (!is_file($this->dir . '/.htaccess') && self::isApacheLike()) {
            file_put_contents($this->dir . '/.htaccess', self::defaultHtaccess(self::basePath($newUrl)));
            $log[] = 'Created .htaccess with the WordPress rewrite rules';
        }

        return $log;
    }

    /** Comments out auto_prepend_file lines whose file does not exist here (a guaranteed fatal error). */
    private function disableMissingPrepend(string $content): string
    {
        return preg_replace_callback('/^(\s*)(php_value\s+)?auto_prepend_file\s*=?\s*[\'"]?([^\'"\r\n]+)[\'"]?\s*$/mi', function ($m) {
            $target = trim($m[3]);
            if ($target === '' || strtolower($target) === 'none' || is_file($target)) {
                return $m[0];
            }
            $comment = $m[2] !== '' ? '#' : ';';

            return $m[1] . $comment . ' SiteShifter: disabled, file not found on this server: ' . trim($m[0]);
        }, $content);
    }

    private function fixRewriteBase(string $content, string $newUrl): string
    {
        $base = self::basePath($newUrl);
        if (!preg_match('/# BEGIN WordPress(.*?)# END WordPress/s', $content, $block)) {
            return $content;
        }
        $fixed = preg_replace('/^(\s*RewriteBase\s+)\S+/m', '${1}' . $base, $block[1]);
        $fixed = preg_replace('#^(\s*RewriteRule\s+\.\s+)\S*index\.php#m', '${1}' . $base . 'index.php', $fixed);

        return str_replace($block[1], $fixed, $content);
    }

    public static function basePath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return '/' . ltrim(rtrim($path, '/') . '/', '/');
    }

    public static function defaultHtaccess(string $base): string
    {
        return "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase {$base}\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . {$base}index.php [L]\n</IfModule>\n# END WordPress\n";
    }

    private static function isApacheLike(): bool
    {
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        return strpos($software, 'apache') !== false || strpos($software, 'litespeed') !== false;
    }
}

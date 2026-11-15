<?php
/**
 * Maps SiteShifter\Plugin\* to includes/ and the bundled, prefixed core
 * (SiteShifter\Core\*) to core/. No Composer needed at runtime.
 *
 * @package SiteShifter
 */

namespace SiteShifter\Plugin;

defined('ABSPATH') || exit;

class Autoloader
{
    public static function register($dir)
    {
        $map = [
            'SiteShifter\\Plugin\\' => $dir . 'includes/',
            'SiteShifter\\Core\\' => $dir . 'core/',
        ];
        spl_autoload_register(static function ($class) use ($map) {
            foreach ($map as $prefix => $base) {
                if (strpos($class, $prefix) === 0) {
                    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_readable($file)) {
                        require_once $file;
                    }
                    return;
                }
            }
        });
    }
}

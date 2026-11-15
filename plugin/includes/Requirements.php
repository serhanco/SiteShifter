<?php
/**
 * Runtime requirement checks. Must stay PHP 5.6-parsable-safe enough to run
 * before anything else: no scalar type hints, no core classes.
 *
 * @package SiteShifter
 */

namespace SiteShifter\Plugin;

defined('ABSPATH') || exit;

class Requirements
{
    const MIN_PHP = '7.4';
    const MIN_WP = '5.8';

    /**
     * Human-readable reasons why the plugin cannot run here (empty = fine).
     *
     * @param string|null $php      Override for tests.
     * @param string|null $wp       Override for tests.
     * @param array|null  $loaded   Extension names considered loaded (tests).
     * @return string[]
     */
    public static function problems($php = null, $wp = null, $loaded = null)
    {
        $php = $php === null ? PHP_VERSION : $php;
        if ($wp === null) {
            $wp = isset($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : '0';
        }
        $has = static function ($ext) use ($loaded) {
            return $loaded === null ? extension_loaded($ext) : in_array($ext, $loaded, true);
        };

        $out = [];
        if (version_compare($php, self::MIN_PHP, '<')) {
            /* translators: 1: required PHP version, 2: current PHP version */
            $out[] = sprintf('SiteShifter needs PHP %1$s or newer; this site runs PHP %2$s.', self::MIN_PHP, $php);
        }
        if (version_compare($wp, self::MIN_WP, '<')) {
            /* translators: 1: required WordPress version, 2: current WordPress version */
            $out[] = sprintf('SiteShifter needs WordPress %1$s or newer; this site runs %2$s.', self::MIN_WP, $wp);
        }
        foreach (['mysqli', 'zip'] as $ext) {
            if (!$has($ext)) {
                $out[] = sprintf('The PHP extension "%s" is required but not loaded.', $ext);
            }
        }

        return $out;
    }
}

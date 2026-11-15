<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Runtime;

use SiteShifter\Database\Connection;

defined('ABSPATH') || exit;

/** Facts about the running WordPress site, in the shapes the core expects. */
class Site
{
    /**
     * A separate mysqli connection from the DB_* constants. Not $wpdb: raw
     * queries must not touch $wpdb's caches or other plugins' query hooks.
     */
    public static function connection()
    {
        return new Connection(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, defined('DB_CHARSET') && DB_CHARSET ? DB_CHARSET : 'utf8mb4');
    }

    public static function prefix()
    {
        global $wpdb;

        return $wpdb->base_prefix;
    }

    public static function homeUrl()
    {
        return untrailingslashit((string) get_option('home'));
    }

    /** Context for Preflight::run() for an in-place check. */
    public static function preflightContext($newUrl = '')
    {
        $ctx = [
            'target_dir' => untrailingslashit(ABSPATH),
            'old_url' => self::homeUrl(),
            'manifest' => [
                'site' => [
                    'wp_version' => get_bloginfo('version'),
                    'php_version' => PHP_VERSION,
                    'home' => self::homeUrl(),
                    'url' => untrailingslashit((string) get_option('siteurl')),
                ],
            ],
        ];
        if ($newUrl !== '') {
            $ctx['new_url'] = $newUrl;
        }
        try {
            $ctx['db'] = self::connection();
        } catch (\Throwable $e) {
            $ctx['db'] = null;
            $ctx['db_error'] = $e->getMessage();
        }

        return $ctx;
    }
}

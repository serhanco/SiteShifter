<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin;

defined('ABSPATH') || exit;

class Plugin
{
    public static function boot()
    {
        load_plugin_textdomain('siteshifter', false, dirname(plugin_basename(SITESHIFTER_FILE)) . '/languages');

        if (is_multisite()) {
            // First release: detect and warn only (see plan). No admin screens on multisite.
            add_action('network_admin_notices', [self::class, 'multisiteNotice']);
            add_action('admin_notices', [self::class, 'multisiteNotice']);
            return;
        }

        $store = new Jobs\JobStore(WP_CONTENT_DIR . '/siteshifter-work');
        $runner = new Jobs\Runner($store, [
            'host_test' => static function ($job, $dir) {
                return new Jobs\HostTestTask($dir);
            },
        ]);
        (new Rest\Routes(new Rest\JobApi($runner)))->register();

        if (is_admin()) {
            (new Admin\Menu())->register();
        }
    }

    public static function multisiteNotice()
    {
        if (!current_user_can('manage_network_options') && !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('SiteShifter detected a multisite network. Export, import and address change are switched off on multisite in this version; a network-aware release is planned.', 'siteshifter')
            . '</p></div>';
    }
}

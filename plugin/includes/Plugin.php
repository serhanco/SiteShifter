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
        $connect = ['SiteShifter\\Plugin\\Runtime\\Site', 'connection'];
        $runner = new Jobs\Runner($store, [
            'host_test' => static function ($job, $dir) {
                return new Jobs\HostTestTask($dir);
            },
            'address_change' => static function ($job, $dir) use ($connect) {
                return new Jobs\AddressChangeTask($dir, Runtime\Site::prefix(), $connect);
            },
            'export' => static function ($job, $dir) use ($connect) {
                return new Jobs\ExportTask($dir, static function (array $options) use ($connect, $dir) {
                    return new \SiteShifter\Package\PackageBuilder(call_user_func($connect), untrailingslashit(ABSPATH), Runtime\Site::prefix(), $dir, $dir . '/package.zip', $options);
                });
            },
            'rollback' => static function ($job, $dir) use ($store, $connect) {
                $source = (string) ($job['params']['job'] ?? '');
                $file = Jobs\JobStore::validId($source) ? $store->dir($source) . '/backup.sql' : '';

                return new Jobs\RollbackTask($file, $connect);
            },
        ]);
        $checks = static function ($newUrl) {
            $report = (new \SiteShifter\Preflight\Preflight())->run(Runtime\Site::preflightContext($newUrl));

            return ['blockers' => $report->hasBlockers(), 'results' => $report->toArray()];
        };
        $siteCheck = static function ($url) {
            return \SiteShifter\Support\SiteCheck::check($url);
        };
        (new Rest\Routes(new Rest\JobApi($runner, $checks, $siteCheck)))->register();

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

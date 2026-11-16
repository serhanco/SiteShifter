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
        $incoming = new Jobs\Incoming($store);
        // Cloud connections live in an encrypted file beside the jobs, never in the database (see Vault).
        $vault = static function () use ($store) {
            static $v = null;
            if ($v === null) {
                $store->protect();
                $v = new \SiteShifter\Transfer\Vault($store->base() . '/vault.php', wp_salt('auth') . wp_salt('secure_auth'));
            }

            return $v;
        };
        $google = null;
        $cloud = static function () use ($vault, &$google) {
            if ($google === null) {
                $google = new Cloud\GoogleAccount($vault(), new Cloud\WpHttp(), Cloud\Client::builtIn());
            }

            return $google;
        };
        // Clone keys share the vault: secrets never reach the database or a package.
        $keys = static function () use ($vault) {
            return new Remote\Keys($vault());
        };
        $drive = static function () use ($cloud) {
            return $cloud()->drive();
        };
        $runner = new Jobs\Runner($store, [
            'host_test' => static function ($job, $dir) {
                return new Jobs\HostTestTask($dir);
            },
            'address_change' => static function ($job, $dir) use ($connect) {
                return new Jobs\AddressChangeTask($dir, Runtime\Site::prefix(), $connect);
            },
            'import' => static function ($job, $dir) use ($connect, $incoming) {
                $zip = $incoming->path((string) ($job['params']['package'] ?? ''));
                if ($zip === null) {
                    throw new \InvalidArgumentException('That package was not found.');
                }

                return new Jobs\ImportTask($dir, [
                    'zip' => $zip,
                    'content_dir' => WP_CONTENT_DIR,
                    'abspath' => untrailingslashit(ABSPATH),
                    'home' => Runtime\Site::importUrl(Runtime\Site::homeUrl()),
                    'siteurl' => Runtime\Site::importUrl(get_option('siteurl')),
                    'prefix' => Runtime\Site::prefix(),
                    'plugin_file' => plugin_basename(SITESHIFTER_FILE),
                    'connect' => $connect,
                    'inspect' => static function ($path) {
                        return (new Import\Inspector())->inspect($path, Runtime\Site::importTarget());
                    },
                    'after_swap' => 'wp_cache_flush',
                ]);
            },
            'fetch' => static function ($job, $dir) use ($incoming) {
                return new Jobs\FetchTask($dir, $incoming, static function ($url, array $headers, $file) {
                    $r = wp_safe_remote_get($url, ['headers' => $headers, 'timeout' => 60, 'stream' => true, 'filename' => $file, 'redirection' => 0]);
                    if (is_wp_error($r)) {
                        return ['code' => 0, 'headers' => []];
                    }
                    $h = [];
                    foreach (['content-range', 'x-siteshifter-range', 'x-siteshifter-pieces', 'content-length'] as $k) {
                        $h[$k] = (string) wp_remote_retrieve_header($r, $k);
                    }

                    return ['code' => (int) wp_remote_retrieve_response_code($r), 'headers' => $h];
                });
            },
            'cloud_upload' => static function () use ($store, $drive) {
                return new Jobs\CloudUploadTask($store, $drive, (string) wp_parse_url(home_url(), PHP_URL_HOST));
            },
            'cloud_fetch' => static function ($job, $dir) use ($incoming, $drive) {
                return new Jobs\CloudFetchTask($dir, $incoming, $drive);
            },
            'measure' => static function () use ($connect) {
                return new Jobs\MeasureTask(untrailingslashit(ABSPATH), array_merge(\SiteShifter\Package\Archiver::DEFAULT_EXCLUDES, [Jobs\ExportTask::WORK_FOLDER]), static function () use ($connect) {
                    $db = call_user_func($connect);

                    return [
                        'db' => \SiteShifter\Support\SizeScan::databaseBytes($db, $db->tables(Runtime\Site::prefix())),
                        'free' => function_exists('disk_free_space') ? @disk_free_space(WP_CONTENT_DIR) : false,
                    ];
                });
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
        $library = new Jobs\PackageLibrary($store);
        $retention = new Jobs\Retention($library, static function () {
            return get_option('siteshifter_retention', []);
        }, static function (array $value) {
            update_option('siteshifter_retention', $value, false);
            if (!empty($value['enabled']) && !wp_next_scheduled('siteshifter_prune')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'siteshifter_prune');
            } elseif (empty($value['enabled'])) {
                wp_clear_scheduled_hook('siteshifter_prune');
            }
        });
        add_action('siteshifter_prune', [$retention, 'apply']);
        $checks = static function ($newUrl, $params = []) {
            $report = (new \SiteShifter\Preflight\Preflight())->run(Runtime\Site::preflightContext($newUrl));
            // The plugin never installs over this site from these screens, so the installer's "will be replaced" warning does not apply.
            $results = array_values(array_filter($report->toArray(), static function ($row) {
                return !isset($row['id']) || $row['id'] !== 'existing_site';
            }));
            if (isset($params['profile'])) {
                $results = array_merge($results, Admin\ScopeText::rows(Jobs\ExportTask::scope($params)));
            }

            return ['blockers' => $report->hasBlockers(), 'results' => $results];
        };
        $siteCheck = static function ($url) {
            return \SiteShifter\Support\SiteCheck::check($url);
        };
        $api = new Rest\JobApi($runner, $checks, $siteCheck, $library, $retention);
        $api->withCloud($cloud);
        $api->withClone($keys, static function (array $key) {
            return new \SiteShifter\Remote\CloneClient(new Cloud\WpHttp(), $key);
        }, Runtime\Site::homeUrl());
        $cloneServer = static function () use ($keys, $store, $runner, $connect) {
            return new Remote\Server($keys(), new Remote\Guard($store->base() . '/clone-guard.php'), $store, $runner, static function () use ($connect) {
                $db = call_user_func($connect);
                $prefix = Runtime\Site::prefix();

                return [
                    'plugin' => SITESHIFTER_VERSION,
                    'manifest' => \SiteShifter\Package\Manifest::build($db, $prefix, untrailingslashit(ABSPATH), $db->tables($prefix)),
                    'free' => function_exists('disk_free_space') ? @disk_free_space(WP_CONTENT_DIR) : false,
                ];
            }, static function () {
                return Jobs\Budget::deadline(microtime(true), (int) ini_get('max_execution_time'), isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null);
            });
        };
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('siteshifter-cloud', new Cli\CloudCommand($runner, $cloud, [$library, 'all']), ['shortdesc' => 'Google Drive for SiteShifter packages.']);
        }
        $api->withEstimate(static function (array $measured, array $params) {
            $threshold = isset($params['threshold_mb']) ? max(0, (int) $params['threshold_mb']) * 1048576 : \SiteShifter\Package\Estimate::DEFAULT_THRESHOLD;
            $report = \SiteShifter\Package\Estimate::report((array) $measured['bytes'], (int) $measured['db'], Jobs\ExportTask::scope($params), $measured['free'], $threshold ?: \SiteShifter\Package\Estimate::DEFAULT_THRESHOLD);

            return $report;
        });
        $api->withImport($incoming, new Jobs\ImportActions($store, $connect, 'wp_cache_flush'), static function ($zip) {
            return (new Import\Inspector())->inspect($zip, Runtime\Site::importTarget());
        });
        (new Rest\Routes($api, $cloneServer))->register();

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

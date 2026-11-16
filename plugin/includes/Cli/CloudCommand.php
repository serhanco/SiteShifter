<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Cli;

use SiteShifter\Plugin\Jobs\Runner;

defined('ABSPATH') || exit;

/**
 * Google Drive from the command line: wp siteshifter-cloud <status|connect|list|upload|download|delete>.
 * Uses the same connection and jobs as the Cloud screen. By Serhan Demirel — https://serhandemirel.com
 */
class CloudCommand
{
    /** @var Runner */
    private $runner;

    /** @var callable function (): \SiteShifter\Plugin\Cloud\GoogleAccount */
    private $cloud;

    /** @var callable function (): array packages on this server */
    private $packages;

    public function __construct(Runner $runner, $cloud, $packages)
    {
        $this->runner = $runner;
        $this->cloud = $cloud;
        $this->packages = $packages;
    }

    /**
     * Shows whether Google Drive is connected, and as whom.
     */
    public function status()
    {
        $s = call_user_func($this->cloud)->status();
        if (!$s['configured']) {
            \WP_CLI::warning('No Google Drive client in this copy. Add SITESHIFTER_GOOGLE_CLIENT_ID and SITESHIFTER_GOOGLE_CLIENT_SECRET to wp-config.php.');

            return;
        }
        \WP_CLI::log($s['connected'] ? 'Connected as ' . $s['account'] : 'Not connected. Run: wp siteshifter-cloud connect');
    }

    /**
     * Connects Google Drive: shows a code to enter at google.com/device and waits for approval.
     */
    public function connect()
    {
        $g = call_user_func($this->cloud);
        $p = $g->connect();
        \WP_CLI::log('Open ' . $p['verification_url'] . ' on any device, sign in and enter: ' . $p['user_code']);
        while (true) {
            sleep(max(5, (int) $p['interval']));
            $r = $g->poll();
            if ($r['status'] === 'approved') {
                \WP_CLI::success('Connected as ' . $r['account']);

                return;
            }
            if ($r['status'] !== 'pending') {
                \WP_CLI::error($r['status'] === 'denied' ? 'Access was not allowed.' : 'The code expired. Run the command again.');
            }
        }
    }

    /**
     * Disconnects Google Drive from this site (packages in Drive stay).
     */
    public function disconnect()
    {
        call_user_func($this->cloud)->disconnect();
        \WP_CLI::success('Disconnected.');
    }

    /**
     * Lists SiteShifter packages in Google Drive.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     */
    public function list_($args, $assoc)
    {
        $items = array_map(static function ($i) {
            return ['id' => $i['id'], 'name' => $i['name'], 'size' => size_format($i['size'], 1), 'created' => gmdate('Y-m-d H:i', $i['created']), 'checked' => $i['digest'] ? 'pieces' : 'size'];
        }, call_user_func($this->cloud)->drive()->items());
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $items, ['id', 'name', 'size', 'created', 'checked']);
    }

    /**
     * Uploads a finished export to Google Drive.
     *
     * [<package>]
     * : Package id from the Packages screen. Default: the newest checked package.
     */
    public function upload($args)
    {
        $id = $args[0] ?? '';
        if ($id === '') {
            foreach (call_user_func($this->packages) as $p) {
                if (!empty($p['valid'])) {
                    $id = $p['id'];
                    break;
                }
            }
        }
        if ($id === '') {
            \WP_CLI::error('There is no checked package on this server. Run an export first.');
        }
        $job = $this->drive('cloud_upload', ['job' => $id]);
        \WP_CLI::success('Uploaded as ' . $job['result']['name'] . ' (' . $job['result']['id'] . ')');
    }

    /**
     * Brings a package from Google Drive to this server, ready to import.
     *
     * <item>
     * : The Drive id shown by "list".
     */
    public function download($args)
    {
        $job = $this->drive('cloud_fetch', ['item' => $args[0]]);
        \WP_CLI::success('Ready to import: ' . $job['result']['name'] . ' (' . $job['result']['id'] . ')');
    }

    /**
     * Moves a package in Google Drive to the Drive trash.
     *
     * <item>
     * : The Drive id shown by "list".
     */
    public function delete($args)
    {
        call_user_func($this->cloud)->drive()->delete($args[0]);
        \WP_CLI::success('Moved to the Google Drive trash.');
    }

    /** Runs a job to the end with a live percent line. */
    private function drive($type, array $params)
    {
        $job = $this->runner->start($type, $params);
        $last = -1;
        while ($job['status'] === 'running') {
            $job = $this->runner->step($job['id'], microtime(true) + 20);
            if ($job['percent'] !== $last) {
                $last = $job['percent'];
                \WP_CLI::log($last . '% ' . ($job['detail'] ?? ''));
            }
        }
        if ($job['status'] !== 'done') {
            \WP_CLI::error(($job['error'] ?? 'The job stopped.') . ' Resume it from the screen or run the command again.');
        }

        return $job;
    }
}

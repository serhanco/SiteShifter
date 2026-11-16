<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Cli;

use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Rest\JobApi;

defined('ABSPATH') || exit;

/**
 * Site-to-site cloning from the command line: wp siteshifter-clone <key|keys|revoke|pair|test|forget|run>.
 * Same keys and jobs as the Clone screen. By Serhan Demirel — https://serhandemirel.com
 */
class CloneCommand
{
    /** @var JobApi */
    private $api;

    /** @var Runner */
    private $runner;

    public function __construct(JobApi $api, Runner $runner)
    {
        $this->api = $api;
        $this->runner = $runner;
    }

    /**
     * Creates a clone key that lets one other site copy this site.
     *
     * [--hours=<hours>]
     * : How long the key works. Default: 24.
     */
    public function key($args, $assoc)
    {
        $r = $this->ok($this->api->handle('clone_key_create', ['params' => ['hours' => (int) ($assoc['hours'] ?? 24)]]));
        if ($r['created']['insecure']) {
            \WP_CLI::warning('This site does not use https: the package would travel unencrypted.');
        }
        \WP_CLI::log('Paste this into the other site (wp siteshifter-clone pair <key>). It is shown only once:');
        \WP_CLI::line($r['created']['key']);
    }

    /**
     * Lists the clone keys of this site.
     */
    public function keys()
    {
        $r = $this->ok($this->api->handle('clone_keys', []));
        $rows = array_map(static function ($k) {
            return ['id' => $k['id'], 'created' => gmdate('Y-m-d H:i', $k['created']), 'until' => gmdate('Y-m-d H:i', $k['expires']), 'used' => $k['used'] ? gmdate('Y-m-d H:i', $k['used']) : '-', 'status' => $k['revoked'] ? 'revoked' : ($k['active'] ? 'active' : 'expired')];
        }, $r['keys']);
        \WP_CLI\Utils\format_items('table', $rows, ['id', 'created', 'until', 'used', 'status']);
    }

    /**
     * Revokes a clone key.
     *
     * <id>
     * : The key id shown by "keys".
     */
    public function revoke($args)
    {
        $this->ok($this->api->handle('clone_key_revoke', ['params' => ['key' => $args[0]]]));
        \WP_CLI::success('Revoked.');
    }

    /**
     * Saves a clone key from the source site and tests the connection.
     *
     * <key>
     * : The key created on the source site.
     */
    public function pair($args)
    {
        $r = $this->ok($this->api->handle('clone_remote_set', ['params' => ['key' => $args[0]]]));
        $this->report($r);
    }

    /**
     * Tests the connection to the paired source site.
     */
    public function test()
    {
        $this->report($this->ok($this->api->handle('clone_remote_test', [])));
    }

    /**
     * Forgets the pasted clone key.
     */
    public function forget()
    {
        $this->ok($this->api->handle('clone_remote_forget', []));
        \WP_CLI::success('Forgotten.');
    }

    /**
     * Checks compatibility, then copies the paired source site to this server, ready to import.
     *
     * [--profile=<profile>]
     * : full, structure, settings or content. Default: full.
     *
     * [--scope=<scope>]
     * : root (the whole site) or wp-content. Default: root.
     */
    public function run($args, $assoc)
    {
        $params = ['profile' => (string) ($assoc['profile'] ?? 'full'), 'scope' => (string) ($assoc['scope'] ?? 'root')];
        $check = $this->ok($this->api->handle('clone_check', ['params' => $params]));
        foreach ($check['results'] as $row) {
            if ($row['level'] === 'block' || $row['level'] === 'warn') {
                \WP_CLI::log(strtoupper($row['level']) . ': ' . $row['title'] . ($row['message'] ? ' - ' . $row['message'] : ''));
            }
        }
        if ($check['blockers']) {
            \WP_CLI::error('The copy cannot work until the problems above are fixed.');
        }
        $job = $this->runner->start('clone', $params);
        $last = -1;
        while ($job['status'] === 'running') {
            $job = $this->runner->step($job['id'], microtime(true) + 20);
            if ($job['percent'] !== $last) {
                $last = $job['percent'];
                \WP_CLI::log($last . '% ' . $job['phase'] . ' ' . ($job['detail'] ?? ''));
            }
        }
        if ($job['status'] !== 'done') {
            \WP_CLI::error(($job['error'] ?? 'The job stopped.') . ' Resume it from the screen or run the command again.');
        }
        if (!empty($job['result']['left_on_source'])) {
            \WP_CLI::warning('The package could not be removed from the source site; delete it there.');
        }
        \WP_CLI::success('Copied: ' . $job['result']['name'] . ' (' . $job['result']['id'] . '). Import it from SiteShifter > Import.');
    }

    private function report(array $r)
    {
        if (!$r['test']['ok']) {
            \WP_CLI::error('Paired with ' . $r['remote']['url'] . ', but the test failed: ' . $r['test']['message']);
        }
        $m = $r['test']['source']['manifest'] ?? [];
        \WP_CLI::success('Paired with ' . $r['remote']['url'] . ': WordPress ' . ($m['site']['wp_version'] ?? '?') . ', PHP ' . ($m['php']['version'] ?? '?') . ', SiteShifter ' . ($r['test']['source']['plugin'] ?? '?') . '.');
    }

    private function ok(array $r)
    {
        if (empty($r['ok'])) {
            \WP_CLI::error((string) ($r['message'] ?? 'Failed.'));
        }

        return $r;
    }
}

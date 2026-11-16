<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\Storage;
use SiteShifter\Transfer\Upload;

defined('ABSPATH') || exit;

/** Copies a finished export to cloud storage (Google Drive), resumable, with live percent. */
class CloudUploadTask implements Task
{
    /** @var JobStore */
    private $store;

    /** @var callable function (): Storage */
    private $storage;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $sleep;

    /** @var string */
    private $host;

    /**
     * @param string $host this site's host name, for the package name in the cloud
     */
    public function __construct(JobStore $store, $storage, $host, $clock = null, $sleep = null)
    {
        $this->store = $store;
        $this->host = (string) $host;
        $this->storage = $storage;
        $this->clock = $clock;
        $this->sleep = $sleep;
    }

    public function initialState(array $params)
    {
        $id = (string) ($params['job'] ?? '');
        $job = JobStore::validId($id) ? $this->store->load($id) : null;
        $zip = $job ? $this->store->dir($id) . '/package.zip' : '';
        if (!$job || $job['type'] !== 'export' || $job['status'] !== 'done' || !is_file($zip)) {
            throw new \InvalidArgumentException('That package was not found. Create an export first.');
        }
        $listing = $this->store->dir($id) . '/package.pieces.json';
        $pieces = is_file($listing) ? Pieces::parse((string) file_get_contents($listing)) : null;
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            $name = 'siteshifter-' . ($this->host !== '' ? $this->host : 'site') . '-' . gmdate('Ymd-His', (int) $job['created']) . '.zip';
        }

        return ['source' => $id, 'up' => Upload::initialState($zip, $name, $pieces), 'done' => false, 'result' => null];
    }

    public function run(array $state, $deadline)
    {
        $up = new Upload(call_user_func($this->storage), $this->clock, $this->sleep);
        $state['up'] = $up->run($state['up'], $deadline);
        if ($state['up']['done']) {
            $state['result'] = $state['up']['result'];
            $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        $p = Upload::progress($state['up']);
        if ($state['done']) {
            $p['result'] = $state['result'];
        }

        return $p;
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }
}

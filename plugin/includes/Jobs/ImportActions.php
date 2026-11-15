<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Plugin\Import\DbSwap;
use SiteShifter\Plugin\Import\FileSwap;

defined('ABSPATH') || exit;

/**
 * What can be done with a finished import: undo it (every rename reversed) or
 * discard the backup once the person is happy. Both read swap.json, which the
 * import wrote before it changed anything.
 */
class ImportActions
{
    /** @var JobStore */
    private $store;

    /** @var callable function (): \SiteShifter\Database\Connection */
    private $connect;

    /** @var callable|null runs after the live site changed (flush the object cache) */
    private $after;

    public function __construct(JobStore $store, $connect, $after = null)
    {
        $this->store = $store;
        $this->connect = $connect;
        $this->after = $after;
    }

    /** Puts the site back as it was before the import. */
    public function undo($id)
    {
        $swap = $this->record($id);
        if ($swap['state'] !== 'live') {
            throw new \InvalidArgumentException('This import is not live, so there is nothing to undo.');
        }
        $dir = $this->store->dir($id);
        $db = call_user_func($this->connect);
        DbSwap::undo($db, $swap['pairs']);
        (new FileSwap('', '', ''))->undo($swap['files']);
        DbSwap::drop($db, $db->tables($swap['stage_prefix'])); // the imported tables are no longer needed
        $swap['state'] = 'undone';
        file_put_contents($dir . '/swap.json', json_encode($swap, JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($this->after) {
            call_user_func($this->after);
        }

        return ['undone' => true];
    }

    /** Deletes the backup tables and the moved-aside files: after this the import can no longer be undone. */
    public function discard($id)
    {
        $swap = $this->record($id);
        if (!in_array($swap['state'], ['live', 'undone'], true)) {
            throw new \InvalidArgumentException('Nothing to discard.');
        }
        $dir = $this->store->dir($id);
        if ($swap['state'] === 'live') {
            DbSwap::drop(call_user_func($this->connect), $swap['backups']);
        }
        self::removeTree($dir . '/aside');
        self::removeTree($dir . '/stage');
        $swap['state'] = 'discarded';
        file_put_contents($dir . '/swap.json', json_encode($swap, JSON_UNESCAPED_SLASHES), LOCK_EX);

        return ['discarded' => true];
    }

    /** @return array[] import jobs that were applied, newest first */
    public function all()
    {
        $out = [];
        foreach ($this->store->all() as $job) {
            if ($job['type'] === 'import' && ($st = $this->status($job['id']))) {
                $out[] = ['id' => $job['id'], 'created' => (int) $job['created']] + $st;
            }
        }

        return $out;
    }

    /** @return array|null what the person can still do with an import job */
    public function status($id)
    {
        try {
            $swap = $this->record($id);
        } catch (\Throwable $e) {
            return null;
        }

        return ['state' => $swap['state'], 'can_undo' => $swap['state'] === 'live', 'can_discard' => in_array($swap['state'], ['live', 'undone'], true)];
    }

    private function record($id)
    {
        $file = $this->store->dir($id) . '/swap.json';
        $swap = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($swap)) {
            throw new \InvalidArgumentException('This import has not been applied.');
        }

        return $swap;
    }

    private static function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

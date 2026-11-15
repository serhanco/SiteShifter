<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * The archive of packages this site has made: list, delete, and the keep-limit
 * clean-up. Reads only the job files, never the zips themselves, so listing is cheap.
 */
class PackageLibrary
{
    /** @var JobStore */
    private $store;

    public function __construct(JobStore $store)
    {
        $this->store = $store;
    }

    /**
     * Finished packages, newest first. No paths are returned.
     *
     * @return array[]
     */
    public function all()
    {
        $out = [];
        foreach ($this->store->all() as $job) {
            if ($job['type'] !== 'export' || $job['status'] !== 'done') {
                continue;
            }
            $file = $this->store->dir($job['id']) . '/package.zip';
            if (!is_file($file)) {
                continue;
            }
            $build = $job['state']['build'] ?? [];
            $scope = $build['manifest']['scope'] ?? [];
            $out[] = [
                'id' => $job['id'],
                'created' => (int) $job['created'],
                'bytes' => (int) filesize($file),
                'profile' => (string) ($scope['profile'] ?? 'full'),
                'source' => (string) ($build['manifest']['site']['home'] ?? ($build['manifest']['site']['url'] ?? '')),
                'valid' => !empty($job['state']['check']['ok']),
                'files' => (int) ($job['state']['check']['files'] ?? 0),
                'tables' => (int) ($job['state']['check']['tables'] ?? 0),
                'manual_uploads' => !empty($scope['manual_uploads']),
                'warnings' => array_values((array) ($scope['warnings'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * @param string[] $ids
     *
     * @return string[] ids that were deleted
     */
    public function deleteMany(array $ids)
    {
        $known = array_column($this->all(), 'id');
        $done = [];
        foreach (array_unique($ids) as $id) {
            if (JobStore::validId($id) && in_array($id, $known, true)) {
                $this->store->delete($id);
                $done[] = $id;
            }
        }

        return $done;
    }

    /**
     * Keeps the newest $maxCount packages and none older than $maxDays (0 = no limit for that rule).
     *
     * @return string[] ids that were deleted
     */
    public function prune($maxCount, $maxDays, $now = null)
    {
        $now = $now === null ? time() : $now;
        $remove = [];
        foreach ($this->all() as $i => $p) {
            if (($maxCount > 0 && $i >= $maxCount) || ($maxDays > 0 && $now - $p['created'] > $maxDays * 86400)) {
                $remove[] = $p['id'];
            }
        }

        return $this->deleteMany($remove);
    }
}

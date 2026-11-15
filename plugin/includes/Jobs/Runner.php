<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * Runs one slice of a job per call. Never throws for task errors: the job
 * keeps its last good state and is retried; after MAX_ERRORS failures in a
 * row it pauses with the reason, and can be resumed from the same point.
 */
class Runner
{
    const MAX_ERRORS = 3;

    /** @var JobStore */
    private $store;

    /** @var array<string,callable> type => function (array $job, string $dir): Task */
    private $factories;

    public function __construct(JobStore $store, array $factories)
    {
        $this->store = $store;
        $this->factories = $factories;
    }

    public function types()
    {
        return array_keys($this->factories);
    }

    /** @return array public view of the new job */
    public function start($type, array $params = [])
    {
        if (!isset($this->factories[$type])) {
            throw new \InvalidArgumentException('Unknown job type.');
        }
        $job = $this->store->create($type, $params, []);
        try {
            $task = $this->task($job);
            $job['state'] = $task->initialState($params);
        } catch (\Throwable $e) {
            $this->store->delete($job['id']); // invalid request: leave nothing behind
            throw $e;
        }
        $job['progress'] = $task->progress($job['state']) + ['percent' => 0, 'phase' => 'start'];
        $job['checkpoints'] = $this->sizes($task->checkpointFiles($job['state']));
        $this->store->save($job);

        return self::view($job);
    }

    /**
     * @param float $deadline absolute time the task must stop by
     * @return array public view, plus 'busy' => true when another request holds the job
     */
    public function step($id, $deadline)
    {
        $job = $this->mustLoad($id);
        if ($job['status'] !== 'running') {
            return self::view($job);
        }
        $lock = $this->store->lock($id);
        if (!$lock->acquire()) {
            return self::view($job) + ['busy' => true];
        }
        try {
            $job = $this->mustLoad($id); // re-read under the lock
            if ($job['status'] !== 'running') {
                return self::view($job);
            }
            $task = $this->task($job);
            $this->restore($job['checkpoints']);
            try {
                $state = $task->run($job['state'], $deadline);
                $job['state'] = $state;
                $job['errors'] = 0;
                $job['last_error'] = null;
                $job['progress'] = $task->progress($state);
                $job['checkpoints'] = $this->sizes($task->checkpointFiles($state));
                if (!empty($state['done'])) {
                    $job['status'] = 'done';
                    $job['progress']['percent'] = 100;
                }
            } catch (\Throwable $e) {
                // Keep the last good state; the next step retries from it.
                $job['errors']++;
                $job['last_error'] = $e->getMessage();
                if ($job['errors'] >= self::MAX_ERRORS) {
                    $job['status'] = 'paused';
                }
            }
            $job['slices']++;
            $job['updated'] = time();
            $this->store->save($job);

            return self::view($job);
        } finally {
            $lock->release();
        }
    }

    /** Resumes a paused job from its last good state. */
    public function resume($id)
    {
        return $this->setStatus($id, ['paused'], 'running', true);
    }

    public function cancel($id)
    {
        return $this->setStatus($id, ['running', 'paused'], 'cancelled', false);
    }

    public function get($id)
    {
        return self::view($this->mustLoad($id));
    }

    /** What the browser sees: no internal state, paths or parameters. */
    public static function view(array $job)
    {
        return [
            'id' => $job['id'],
            'type' => $job['type'],
            'status' => $job['status'],
            'percent' => (int) ($job['progress']['percent'] ?? 0),
            'phase' => (string) ($job['progress']['phase'] ?? ''),
            'detail' => (string) ($job['progress']['detail'] ?? ''),
            'result' => $job['status'] === 'done' ? ($job['progress']['result'] ?? null) : null,
            'error' => $job['last_error'],
            'errors' => (int) $job['errors'],
            'created' => (int) $job['created'],
            'updated' => (int) $job['updated'],
        ];
    }

    private function setStatus($id, array $from, $to, $resetErrors)
    {
        $lock = $this->store->lock($id);
        $waited = 0;
        while (!$lock->acquire()) {
            if (++$waited > 50) {
                throw new \RuntimeException('The job is busy; try again in a moment.');
            }
            usleep(100000);
        }
        try {
            $job = $this->mustLoad($id);
            if (in_array($job['status'], $from, true)) {
                $job['status'] = $to;
                if ($resetErrors) {
                    $job['errors'] = 0;
                }
                $job['updated'] = time();
                $this->store->save($job);
            }

            return self::view($job);
        } finally {
            $lock->release();
        }
    }

    private function mustLoad($id)
    {
        $job = $this->store->load($id);
        if (!$job) {
            throw new \InvalidArgumentException('Job not found.');
        }

        return $job;
    }

    /** @return Task */
    private function task(array $job)
    {
        $task = call_user_func($this->factories[$job['type']], $job, $this->store->dir($job['id']));
        if (!$task instanceof Task) {
            throw new \LogicException('Job factory did not return a Task.');
        }

        return $task;
    }

    /** @return array<string,int> path => size at the last good state */
    private function sizes(array $files)
    {
        $out = [];
        foreach ($files as $file) {
            clearstatcache(true, $file);
            $out[$file] = is_file($file) ? (int) filesize($file) : 0;
        }

        return $out;
    }

    /** Cuts every checkpointed file back to its last good size. */
    private function restore(array $checkpoints)
    {
        foreach ($checkpoints as $file => $size) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                continue;
            }
            if ((int) filesize($file) > $size) {
                $fh = fopen($file, 'r+b');
                if ($fh === false || !ftruncate($fh, $size)) {
                    throw new \RuntimeException('Cannot roll back a partly written file.');
                }
                fclose($fh);
            }
        }
    }
}

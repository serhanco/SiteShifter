<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Support\Problem;

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
            $before = $job;
            $finished = false;
            // PHP kills the request on running out of memory or time; record why before it goes.
            register_shutdown_function(function () use (&$finished, $before) {
                $code = $finished ? null : Problem::fromFatal(error_get_last());
                if ($code !== null) {
                    $this->recordProblem($before, $code, (string) (error_get_last()['message'] ?? ''));
                }
            });
            try {
                $state = $task->run($job['state'], $deadline);
                $job['state'] = $state;
                $job['errors'] = 0;
                $job['last_error'] = null;
                $job['problem'] = null;
                $job['progress'] = $task->progress($state);
                $job['checkpoints'] = $this->sizes($task->checkpointFiles($state));
                if (!empty($state['done'])) {
                    $job['status'] = 'done';
                    $job['progress']['percent'] = 100;
                }
            } catch (\Throwable $e) {
                // Keep the last good state; the next step retries from it.
                $problem = Problem::classify($e);
                $job['errors']++;
                $job['last_error'] = $e->getMessage();
                $job['problem'] = $problem['code'];
                // Trying again cannot fix a missing permission or a full disk: stop at once and say what to do.
                if ($job['errors'] >= self::MAX_ERRORS || !$problem['retryable']) {
                    $job['status'] = 'paused';
                }
            }
            $finished = true;
            $job['slices']++;
            $job['updated'] = time();
            $this->store->save($job);

            return self::view($job);
        } finally {
            $lock->release();
        }
    }

    /** Saves the reason a request died (memory, time) into the last good job state. */
    private function recordProblem(array $job, $code, $message)
    {
        try {
            $job['errors'] = (int) $job['errors'] + 1;
            $job['last_error'] = $message;
            $job['problem'] = $code;
            if ($job['errors'] >= self::MAX_ERRORS || !Problem::isRetryable($code)) {
                $job['status'] = 'paused';
            }
            $job['updated'] = time();
            $this->store->save($job);
        } catch (\Throwable $e) {
            // Nothing more can be done while PHP is shutting down.
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

    /**
     * Issues a short-lived download token for a finished export.
     *
     * @return array{token:string,expires:int}
     */
    public function issueDownload($id, $userId, $now = null)
    {
        $job = $this->mustLoad($id);
        if ($job['type'] !== 'export' || $job['status'] !== 'done') {
            throw new \InvalidArgumentException('There is no finished package for this job.');
        }
        $now = $now === null ? time() : $now;
        $job['download'] = ['token' => \SiteShifter\Plugin\Rest\Download::newToken(), 'user' => (int) $userId, 'expires' => $now + \SiteShifter\Plugin\Rest\Download::TTL];
        $this->store->save($job);

        return ['token' => $job['download']['token'], 'expires' => $job['download']['expires']];
    }

    /**
     * @return array{file:string,created:int}|string package path, or why it is refused
     */
    public function downloadFile($id, $token, $userId, $now = null)
    {
        if (!JobStore::validId($id)) {
            return 'The download link is not valid.';
        }
        $job = $this->store->load($id);
        if (!$job) {
            return 'The download link is not valid.';
        }
        $why = \SiteShifter\Plugin\Rest\Download::check($job, $token, $userId, $now === null ? time() : $now);
        if ($why !== '') {
            return $why;
        }
        $file = $this->store->dir($id) . '/package.zip';
        if (!is_file($file)) {
            return 'The package file is gone. Create a new export.';
        }

        return ['file' => $file, 'created' => (int) $job['created']];
    }

    /**
     * A link another site can fetch the finished package from without a login: long random token, short life,
     * gone with the job. Only the hash of the token is stored.
     *
     * @return array{token:string,expires:int}
     */
    public function issuePull($id, $now = null)
    {
        $job = $this->mustLoad($id);
        if ($job['type'] !== 'export' || $job['status'] !== 'done') {
            throw new \InvalidArgumentException('There is no finished package for this job.');
        }
        $now = $now === null ? time() : $now;
        $token = bin2hex(random_bytes(24));
        $job['pull'] = ['hash' => hash('sha256', $token), 'expires' => $now + \SiteShifter\Plugin\Rest\Pull::TTL];
        $this->store->save($job);

        return ['token' => $token, 'expires' => $job['pull']['expires']];
    }

    /** @return array{file:string,size:int,created:int}|string the package to serve, or why it is refused */
    public function pullFile($id, $token, $now = null)
    {
        $job = JobStore::validId($id) ? $this->store->load($id) : null;
        if (!$job || !\SiteShifter\Plugin\Rest\Pull::check($job, $token, $now === null ? time() : $now)) {
            return 'The link is not valid or has expired.';
        }
        $file = $this->store->dir($id) . '/package.zip';
        if (!is_file($file)) {
            return 'The package file is gone. Create a new export.';
        }

        $pieces = $this->store->dir($id) . '/package.pieces.json';

        return ['file' => $file, 'size' => (int) filesize($file), 'created' => (int) $job['created'], 'pieces' => is_file($pieces) ? $pieces : null];
    }

    /** Deletes a job and every file it made (package, backup, work files). */
    public function delete($id)
    {
        $job = $this->mustLoad($id);
        if ($job['status'] === 'running') {
            throw new \RuntimeException('Cancel the job before deleting it.');
        }
        $this->store->delete($id);

        return ['deleted' => true];
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
            'code' => isset($job['problem']) ? $job['problem'] : null,
            'retryable' => isset($job['problem']) ? Problem::isRetryable($job['problem']) : null,
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

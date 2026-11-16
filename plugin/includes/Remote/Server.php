<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Remote;

use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Rest\Pull;
use SiteShifter\Remote\CloneClient;
use SiteShifter\Remote\Signer;

defined('ABSPATH') || exit;

/**
 * The source site's answer to a clone (admin-ajax.php?action=siteshifter_clone, no login).
 *
 * Every request must carry a valid signature from an active clone key, inside
 * the five minute window and never seen before. The key only reads: describe
 * the site, build a package of it, hand out that package's pieces, remove it.
 * Nothing here writes to the site itself, and a key can only touch the
 * packages it asked for.
 */
class Server
{
    /** Scope switches a target may ask for; anything else is ignored. */
    const PARAMS = ['profile', 'types', 'media', 'users', 'comments', 'settings', 'themes', 'plugins', 'other_tables', 'other_files', 'revisions', 'scope', 'all_tables'];

    /** @var Keys */
    private $keys;

    /** @var Guard */
    private $guard;

    /** @var JobStore */
    private $store;

    /** @var Runner */
    private $runner;

    /** @var callable function (): array  facts about this site (manifest, free space, versions) */
    private $about;

    /** @var callable function (): float  when a build step must stop */
    private $deadline;

    /** @var callable */
    private $clock;

    public function __construct(Keys $keys, Guard $guard, JobStore $store, Runner $runner, $about, $deadline, $clock = null)
    {
        $this->keys = $keys;
        $this->guard = $guard;
        $this->store = $store;
        $this->runner = $runner;
        $this->about = $about;
        $this->deadline = $deadline;
        $this->clock = $clock ?: 'time';
    }

    /**
     * @param array  $headers request headers, names lower-cased
     * @param string $body    raw request body (JSON for POST operations)
     * @param array  $query   job and range for the piece operation
     * @return array ['code' => int, 'json' => array] or, for a piece, ['code' => int, 'file' => string, 'size' => int, 'range' => int[]]
     */
    public function handle($op, array $headers, $body, array $query, $ip)
    {
        $op = (string) $op;
        if (!in_array($op, ['hello', 'export_start', 'export_step', 'pieces', 'piece', 'cleanup'], true)) {
            return self::answer(400, 'Unknown clone operation.');
        }
        if ($this->guard->blocked($ip)) {
            return self::answer(429, 'Too many refused requests from this address. Wait ten minutes.', true);
        }
        $signed = $op === 'piece' ? (string) ($query['job'] ?? '') . '|' . (string) ($query['range'] ?? '') : (string) $body;
        $now = (int) call_user_func($this->clock);
        $id = Signer::verify($headers, $op, $signed, [$this->keys, 'secretFor'], [$this->guard, 'seen'], $now);
        if ($id === null) {
            $this->guard->fail($ip);

            return self::answer(403, 'The clone key was refused. It may have expired or been revoked, or the two servers\' clocks differ by more than five minutes.');
        }
        $this->keys->touch($id);
        $in = $op === 'piece' ? $query : json_decode((string) $body, true);
        $in = is_array($in) ? $in : [];
        try {
            switch ($op) {
                case 'hello':
                    return ['code' => 200, 'json' => ['ok' => true, 'protocol' => CloneClient::PROTOCOL] + (array) call_user_func($this->about)];
                case 'export_start':
                    return $this->start($id, is_array($in['params'] ?? null) ? $in['params'] : []);
                case 'export_step':
                    $job = $this->own($id, $in);
                    $view = $job['status'] === 'running' ? $this->runner->step($job['id'], call_user_func($this->deadline)) : Runner::view($job);

                    return ['code' => 200, 'json' => ['ok' => true, 'job' => $view] + ($view['status'] === 'done' ? $this->package($job['id']) : [])];
                case 'pieces':
                    $job = $this->own($id, $in);
                    if ($job['status'] !== 'done') {
                        return self::answer(409, 'The package is not finished yet.', true);
                    }

                    return ['code' => 200, 'json' => ['ok' => true] + $this->package($job['id'])];
                case 'piece':
                    return $this->piece($this->own($id, $in), (string) ($in['range'] ?? ''));
                case 'cleanup':
                    $job = $this->own($id, $in);
                    if ($job['status'] === 'running' || $job['status'] === 'paused') {
                        $this->runner->cancel($job['id']);
                    }
                    $this->store->delete($job['id']);

                    return ['code' => 200, 'json' => ['ok' => true, 'deleted' => true]];
            }
        } catch (\InvalidArgumentException $e) {
            return self::answer(404, $e->getMessage());
        } catch (\Throwable $e) {
            return self::answer(500, $e->getMessage(), true);
        }

        return self::answer(400, 'Unknown clone operation.');
    }

    private function start($keyId, array $params)
    {
        // One package at a time per key: a repeated start (lost answer, second tab) gets the same job.
        foreach ($this->store->all() as $job) {
            if ($job['type'] === 'export' && ($job['params']['clone_key'] ?? '') === $keyId && in_array($job['status'], ['running', 'paused'], true)) {
                return ['code' => 200, 'json' => ['ok' => true, 'job' => Runner::view($job), 'again' => true]];
            }
        }
        $clean = [];
        foreach (self::PARAMS as $k) {
            if (isset($params[$k]) && (is_scalar($params[$k]) || $params[$k] === null)) {
                $clean[$k] = is_string($params[$k]) ? substr(preg_replace('/[^a-z0-9_,\-]/i', '', $params[$k]), 0, 500) : $params[$k];
            }
        }
        $clean['clone_key'] = $keyId;

        return ['code' => 200, 'json' => ['ok' => true, 'job' => $this->runner->start('export', $clean)]];
    }

    /** @return array the job, when it is a package this key asked for */
    private function own($keyId, array $in)
    {
        $id = (string) ($in['job'] ?? '');
        $job = JobStore::validId($id) ? $this->store->load($id) : null;
        if (!$job || $job['type'] !== 'export' || ($job['params']['clone_key'] ?? '') !== $keyId) {
            throw new \InvalidArgumentException('That package was not found on the source site.');
        }

        return $job;
    }

    /** @return array{name:string,size:int,pieces:array|null} */
    private function package($id)
    {
        $dir = $this->store->dir($id);
        $file = $dir . '/package.zip';
        if (!is_file($file)) {
            throw new \InvalidArgumentException('The package file is gone on the source site.');
        }
        $pieces = is_file($dir . '/package.pieces.json') ? json_decode((string) file_get_contents($dir . '/package.pieces.json'), true) : null;

        return ['name' => 'clone-' . substr($id, 0, 8) . '.zip', 'size' => (int) filesize($file), 'pieces' => is_array($pieces) ? $pieces : null];
    }

    private function piece(array $job, $range)
    {
        if ($job['status'] !== 'done') {
            return self::answer(409, 'The package is not finished yet.', true);
        }
        $file = $this->store->dir($job['id']) . '/package.zip';
        if (!is_file($file)) {
            return self::answer(404, 'The package file is gone on the source site.');
        }
        $size = (int) filesize($file);
        $r = Pull::range('bytes=' . $range, $size);
        if ($r === null) {
            return self::answer(416, 'That part of the package does not exist.');
        }

        return ['code' => 206, 'file' => $file, 'size' => $size, 'range' => $r];
    }

    private static function answer($code, $message, $retry = false)
    {
        return ['code' => $code, 'json' => ['ok' => false, 'message' => $message, 'retry' => $retry]];
    }
}

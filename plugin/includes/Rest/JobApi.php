<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Rest;

use SiteShifter\Plugin\Jobs\Budget;
use SiteShifter\Plugin\Jobs\Runner;

defined('ABSPATH') || exit;

/**
 * Transport-independent job operations. The REST routes and the admin-ajax
 * fallback both call this, so the two paths can never behave differently.
 * Permission and nonce checks happen in the transports, before this runs.
 */
class JobApi
{
    /** @var Runner */
    private $runner;

    /** @var callable|null function (string $newUrl): array  runs the pre-flight report */
    private $checks;

    /** @var callable|null function (string $url): array  live site check */
    private $siteCheck;

    /** @var \SiteShifter\Plugin\Jobs\PackageLibrary|null */
    private $library;

    /** @var \SiteShifter\Plugin\Jobs\Retention|null */
    private $retention;

    public function __construct(Runner $runner, $checks = null, $siteCheck = null, $library = null, $retention = null)
    {
        $this->library = $library;
        $this->retention = $retention;
        $this->runner = $runner;
        $this->checks = $checks;
        $this->siteCheck = $siteCheck;
    }

    /** @var callable|null function (array $measured, array $params): array */
    private $estimate;

    /** @var \SiteShifter\Plugin\Jobs\Incoming|null */
    private $incoming;

    /** @var callable|null function (string $zipPath): array  the import dry run */
    private $inspect;

    public function withImport($incoming, $inspect)
    {
        $this->incoming = $incoming;
        $this->inspect = $inspect;

        return $this;
    }

    /** One uploaded piece (called by the upload transports, which have checked permission and nonce). */
    public function upload($id, $offset, $pieceFile)
    {
        try {
            if (!$this->incoming) {
                return $this->fail('unavailable', 'Not available.');
            }

            return ['ok' => true] + $this->incoming->append((string) $id, (int) $offset, (string) $pieceFile);
        } catch (\InvalidArgumentException $e) {
            return $this->fail('not_found', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('server_error', $e->getMessage());
        }
    }

    public function withEstimate($estimate)
    {
        $this->estimate = $estimate;

        return $this;
    }

    /**
     * @param string $op     start|step|status|resume|cancel
     * @param array  $input  already sanitised: type, id, params
     * @return array response body; always has 'ok'
     */
    public function handle($op, array $input)
    {
        try {
            switch ($op) {
                case 'start':
                    $type = isset($input['type']) ? (string) $input['type'] : '';
                    if (!in_array($type, $this->runner->types(), true)) {
                        return $this->fail('unknown_type', 'Unknown job type.');
                    }
                    $params = isset($input['params']) && is_array($input['params']) ? $input['params'] : [];

                    return ['ok' => true, 'job' => $this->runner->start($type, $params)];
                case 'step':
                    $deadline = Budget::deadline(
                        microtime(true),
                        (int) ini_get('max_execution_time'),
                        isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null
                    );

                    return ['ok' => true, 'job' => $this->runner->step($this->id($input), $deadline)];
                case 'checks':
                    return $this->checks ? ['ok' => true] + call_user_func($this->checks, (string) ($input['params']['new_url'] ?? ''), (array) ($input['params'] ?? [])) : $this->fail('unavailable', 'Checks are not available.');
                case 'site_check':
                    $url = (string) ($input['params']['url'] ?? '');
                    if (!preg_match('#^https?://[^\s/]+#i', $url)) {
                        return $this->fail('invalid', 'Enter an address starting with http:// or https://.');
                    }

                    return $this->siteCheck ? ['ok' => true, 'notes' => call_user_func($this->siteCheck, $url)] : $this->fail('unavailable', 'Site check is not available.');
                case 'estimate':
                    if (!$this->estimate) {
                        return $this->fail('unavailable', 'Not available.');
                    }
                    $job = $this->runner->get($this->id($input));
                    if ($job['type'] !== 'measure' || $job['status'] !== 'done' || !is_array($job['result'])) {
                        return $this->fail('invalid', 'The measurement is not finished.');
                    }

                    return ['ok' => true] + call_user_func($this->estimate, $job['result'], (array) ($input['params'] ?? []));
                case 'upload_begin':
                    return $this->incoming ? ['ok' => true, 'id' => $this->incoming->begin((string) ($input['params']['name'] ?? ''), (int) ($input['params']['total'] ?? 0))] : $this->fail('unavailable', 'Not available.');
                case 'upload_end':
                    return $this->incoming ? ['ok' => true, 'package' => $this->incoming->finish($this->id($input))] : $this->fail('unavailable', 'Not available.');
                case 'incoming':
                    return $this->incoming ? ['ok' => true, 'packages' => array_map(static function ($p) {
                        unset($p['file']);

                        return $p;
                    }, $this->incoming->all())] : $this->fail('unavailable', 'Not available.');
                case 'incoming_delete':
                    return $this->incoming ? ['ok' => true, 'deleted' => $this->incoming->delete($this->id($input))] : $this->fail('unavailable', 'Not available.');
                case 'import_check':
                    $path = $this->incoming ? $this->incoming->path($this->id($input)) : null;
                    if ($path === null || !$this->inspect) {
                        return $this->fail('not_found', 'That package was not found.');
                    }

                    return ['ok' => true] + call_user_func($this->inspect, $path);
                case 'packages':
                    return $this->library ? ['ok' => true, 'packages' => $this->library->all(), 'retention' => $this->retention ? $this->retention->get() : null] : $this->fail('unavailable', 'The package list is not available.');
                case 'delete_many':
                    $ids = array_filter(explode(',', (string) ($input['params']['ids'] ?? '')));

                    return $this->library ? ['ok' => true, 'deleted' => $this->library->deleteMany($ids)] : $this->fail('unavailable', 'The package list is not available.');
                case 'retention':
                    if (!$this->retention) {
                        return $this->fail('unavailable', 'Not available.');
                    }
                    $saved = $this->retention->set((array) ($input['params'] ?? []));

                    return ['ok' => true, 'retention' => $saved, 'deleted' => $this->retention->apply()];
                case 'download_link':
                    $user = isset($input['user']) ? (int) $input['user'] : 0;
                    $d = $this->runner->issueDownload($this->id($input), $user);

                    return ['ok' => true, 'token' => $d['token'], 'expires' => $d['expires']];
                case 'delete':
                    return ['ok' => true] + $this->runner->delete($this->id($input));
                case 'status':
                    return ['ok' => true, 'job' => $this->runner->get($this->id($input))];
                case 'resume':
                    return ['ok' => true, 'job' => $this->runner->resume($this->id($input))];
                case 'cancel':
                    return ['ok' => true, 'job' => $this->runner->cancel($this->id($input))];
            }

            return $this->fail('unknown_op', 'Unknown operation.');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($op === 'start' ? 'invalid' : 'not_found', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('server_error', $e->getMessage());
        }
    }

    /**
     * @return array{file:string,created:int}|string the package to stream, or the reason it is refused
     */
    public function downloadTarget($id, $token, $userId)
    {
        $found = $this->runner->downloadFile($id, $token, $userId);

        return $found;
    }

    private function id(array $input)
    {
        return isset($input['id']) ? (string) $input['id'] : '';
    }

    private function fail($code, $message)
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}

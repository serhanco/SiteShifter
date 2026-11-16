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

    /** @var \SiteShifter\Plugin\Jobs\ImportActions|null */
    private $imports;

    public function withImport($incoming, $imports, $inspect)
    {
        $this->imports = $imports;
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

    /** @var callable|null function (): \SiteShifter\Plugin\Cloud\GoogleAccount */
    private $cloud;

    public function withCloud($cloud)
    {
        $this->cloud = $cloud;

        return $this;
    }

    /** Google Drive: connection, packages in the SiteShifter folder. */
    private function cloud($op, array $input)
    {
        if (!$this->cloud) {
            return $this->fail('unavailable', 'Cloud storage is not available.');
        }
        /** @var \SiteShifter\Plugin\Cloud\GoogleAccount $g */
        $g = call_user_func($this->cloud);
        $p = (array) ($input['params'] ?? []);
        switch ($op) {
            case 'cloud_status':
                return ['ok' => true] + $g->status();
            case 'cloud_connect':
                return ['ok' => true, 'pending' => $g->connect()];
            case 'cloud_poll':
                return ['ok' => true] + $g->poll();
            case 'cloud_disconnect':
                $g->disconnect();

                return ['ok' => true] + $g->status();
            case 'cloud_client':
                $g->setClient((string) ($p['client_id'] ?? ''), (string) ($p['client_secret'] ?? ''));

                return ['ok' => true] + $g->status();
            case 'cloud_items':
                $drive = $g->drive();

                return ['ok' => true, 'items' => $drive->items(), 'space' => $drive->space(), 'account' => $drive->account()];
            case 'cloud_delete':
                $g->drive()->delete((string) ($p['item'] ?? ''));

                return ['ok' => true];
        }

        return $this->fail('unknown_op', 'Unknown operation.');
    }

    /** @var callable|null function (): \SiteShifter\Plugin\Remote\Keys */
    private $cloneKeys;

    /** @var callable|null function (array $key): \SiteShifter\Remote\CloneClient */
    private $cloneClient;

    /** @var string this site's address */
    private $home = '';

    public function withClone($keys, $client, $home)
    {
        $this->cloneKeys = $keys;
        $this->cloneClient = $client;
        $this->home = rtrim((string) $home, '/');

        return $this;
    }

    /** Clone keys: the ones this site hands out (source) and the one pasted here (target). */
    private function cloneOp($op, array $input)
    {
        if (!$this->cloneKeys) {
            return $this->fail('unavailable', 'Cloning is not available.');
        }
        /** @var \SiteShifter\Plugin\Remote\Keys $keys */
        $keys = call_user_func($this->cloneKeys);
        $p = (array) ($input['params'] ?? []);
        switch ($op) {
            case 'clone_keys':
                return ['ok' => true, 'keys' => $keys->all(), 'remote' => $keys->remoteView(), 'https' => stripos($this->home, 'https://') === 0];
            case 'clone_key_create':
                $made = $keys->create($this->home, isset($p['hours']) ? (int) $p['hours'] * 3600 : \SiteShifter\Plugin\Remote\Keys::TTL);

                return ['ok' => true, 'created' => $made, 'keys' => $keys->all()];
            case 'clone_key_revoke':
                $keys->revoke((string) ($p['key'] ?? ''));

                return ['ok' => true, 'keys' => $keys->all()];
            case 'clone_remote_set':
                $key = \SiteShifter\Remote\CloneKey::decode((string) ($p['key'] ?? ''));
                if (self::sameSite($key['url'], $this->home)) {
                    return $this->fail('invalid', 'This key belongs to this site. Paste it into the site you want to copy to.');
                }
                $keys->setRemote((string) $p['key']);

                return ['ok' => true, 'remote' => $keys->remoteView(), 'test' => $this->cloneTest($keys)];
            case 'clone_remote_test':
                return ['ok' => true, 'remote' => $keys->remoteView(), 'test' => $this->cloneTest($keys)];
            case 'clone_remote_forget':
                $keys->forgetRemote();

                return ['ok' => true, 'remote' => null];
        }

        return $this->fail('unknown_op', 'Unknown operation.');
    }

    /** Asks the source who it is: proves the address, the key and the way between the two servers. */
    private function cloneTest($keys)
    {
        $key = $keys->remote();
        if (!$key) {
            return ['ok' => false, 'message' => 'No clone key is saved.'];
        }
        try {
            $hello = call_user_func($this->cloneClient, $key)->call('hello');
            if ((int) ($hello['protocol'] ?? 0) !== \SiteShifter\Remote\CloneClient::PROTOCOL) {
                return ['ok' => false, 'message' => 'The source site runs a SiteShifter version that clones differently. Update SiteShifter on both sites to the same version.'];
            }
            unset($hello['ok']);

            return ['ok' => true, 'source' => $hello];
        } catch (\SiteShifter\Transfer\TransferError $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private static function sameSite($a, $b)
    {
        $host = static function ($u) {
            $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));

            return (strpos($h, 'www.') === 0 ? substr($h, 4) : $h) . rtrim((string) parse_url((string) $u, PHP_URL_PATH), '/');
        };

        return $host($a) !== '' && $host($a) === $host($b);
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
            if (strpos($op, 'cloud_') === 0) {
                return $this->cloud($op, $input);
            }
            if (strpos($op, 'clone_') === 0) {
                return $this->cloneOp($op, $input);
            }
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
                case 'imports':
                    return $this->imports ? ['ok' => true, 'imports' => $this->imports->all()] : $this->fail('unavailable', 'Not available.');
                case 'import_undo':
                    return $this->imports ? ['ok' => true] + $this->imports->undo($this->id($input)) : $this->fail('unavailable', 'Not available.');
                case 'import_discard':
                    return $this->imports ? ['ok' => true] + $this->imports->discard($this->id($input)) : $this->fail('unavailable', 'Not available.');
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
                case 'pull_link':
                    $d = $this->runner->issuePull($this->id($input));

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

    /** @return array{file:string,size:int,created:int}|string */
    public function pullTarget($id, $token)
    {
        return $this->runner->pullFile($id, $token);
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

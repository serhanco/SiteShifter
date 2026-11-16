<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Remote\CloneClient;
use SiteShifter\Remote\CloneSource;
use SiteShifter\Transfer\Download;
use SiteShifter\Transfer\TransferError;

defined('ABSPATH') || exit;

/**
 * Clones the paired source site into this one's incoming folder. This site does all the asking:
 * it has the source build a package of the chosen scope (each signed step advances the source's
 * export by one slice), fetches it piece by piece with checksums, then has the source delete it.
 * The package then goes through the usual import check and Replace or Merge.
 *
 * Phases: start -> build (0-45%) -> fetch (45-98%) -> cleanup -> done.
 */
class CloneTask implements Task
{
    /** @var string */
    private $dir;

    /** @var Incoming */
    private $incoming;

    /** @var callable function (): CloneClient */
    private $client;

    /** @var callable function (): float|false free bytes on this server */
    private $free;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $sleep;

    public function __construct($dir, Incoming $incoming, $client, $free = null, $clock = null, $sleep = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->incoming = $incoming;
        $this->client = $client;
        $this->free = $free;
        $this->clock = $clock;
        $this->sleep = $sleep;
    }

    public function initialState(array $params)
    {
        $scope = [];
        foreach (\SiteShifter\Plugin\Remote\Server::PARAMS as $k) {
            if (isset($params[$k])) {
                $scope[$k] = $params[$k];
            }
        }
        call_user_func($this->client); // fails now, not later, when no key is saved

        return ['phase' => 'start', 'scope' => $scope, 'remote' => null, 'build' => ['percent' => 0, 'phase' => ''], 'package' => null, 'dl' => Download::initialState(), 'result' => null, 'done' => false];
    }

    public function run(array $state, $deadline)
    {
        /** @var CloneClient $client */
        $client = call_user_func($this->client);
        switch ($state['phase']) {
            case 'start':
                $r = $client->call('export_start', ['params' => $state['scope']]);
                $state['remote'] = (string) $r['job']['id'];
                $state['phase'] = 'build';

                return $state;
            case 'build':
                $r = $client->call('export_step', ['job' => $state['remote']]);
                $job = $r['job'];
                $state['build'] = ['percent' => (int) $job['percent'], 'phase' => (string) $job['phase']];
                if ($job['status'] === 'done') {
                    $state['package'] = ['name' => (string) $r['name'], 'size' => (int) $r['size'], 'pieces' => $r['pieces'] ?? null];
                    $free = $this->free ? call_user_func($this->free) : false;
                    if (is_numeric($free) && $free < $state['package']['size'] * 1.05) {
                        throw new \RuntimeException(sprintf('This server has %d MB free but the package is %d MB. Free some disk space and resume.', (int) ($free / 1048576), (int) ceil($state['package']['size'] / 1048576)));
                    }
                    $state['phase'] = 'fetch';
                } elseif ($job['status'] !== 'running') {
                    // The source paused (or was cancelled there); the next signed step resumes it.
                    throw TransferError::transient('The source site stopped building the package: ' . ($job['error'] ?: $job['status']));
                }

                return $state;
            case 'fetch':
                $p = $state['package'];
                $dl = new Download(new CloneSource($client, $state['remote'], $p['name'], $p['size'], $p['pieces']), new IncomingSink($this->incoming), $this->dir . '/piece.tmp', $this->clock, $this->sleep);
                $state['dl'] = $dl->run($state['dl'], $deadline);
                if ($state['dl']['done']) {
                    $state['result'] = $state['dl']['result'];
                    $state['phase'] = 'cleanup';
                }

                return $state;
            case 'cleanup':
                try {
                    $client->call('cleanup', ['job' => $state['remote']]);
                    $state['left_on_source'] = false;
                } catch (TransferError $e) {
                    // The copy is safely here; a package left on the source only costs disk there.
                    $state['left_on_source'] = true;
                }
                $state['phase'] = 'done';
                $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => $state['result'] + ['left_on_source' => !empty($state['left_on_source'])]];
        }
        if ($state['phase'] === 'fetch') {
            $d = Download::progress($state['dl']);

            return ['percent' => 45 + (int) floor($d['percent'] * 0.53), 'phase' => 'fetch', 'detail' => $d['detail']];
        }
        if ($state['phase'] === 'cleanup') {
            return ['percent' => 99, 'phase' => 'cleanup', 'detail' => ''];
        }

        return ['percent' => (int) floor($state['build']['percent'] * 0.45), 'phase' => 'build', 'detail' => $state['build']['phase']];
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }
}

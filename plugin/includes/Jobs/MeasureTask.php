<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Support\SizeScan;

defined('ABSPATH') || exit;

/**
 * Measures the site before an export: folder sizes in resumable slices, then
 * the database size and the free disk space. Phases: scan -> db -> done.
 * Reads only; changes nothing.
 */
class MeasureTask implements Task
{
    /** @var string */
    private $abspath;

    /** @var string[] */
    private $excludes;

    /** @var callable function (): array{db:int,free:float|false}  database size and free disk space */
    private $environment;

    public function __construct($abspath, array $excludes, $environment)
    {
        $this->abspath = $abspath;
        $this->excludes = $excludes;
        $this->environment = $environment;
    }

    public function initialState(array $params)
    {
        return ['phase' => 'scan', 'scan' => SizeScan::initialState(), 'db' => 0, 'free' => false, 'done' => false];
    }

    public function run(array $state, $deadline)
    {
        if ($state['phase'] === 'scan') {
            $state['scan'] = (new SizeScan($this->abspath, $this->excludes))->run($state['scan'], $deadline);
            if ($state['scan']['done']) {
                $state['phase'] = 'db';
            }

            return $state;
        }
        $env = call_user_func($this->environment);
        $state['db'] = (int) $env['db'];
        $state['free'] = $env['free'];
        $state['phase'] = 'done';
        $state['done'] = true;

        return $state;
    }

    public function progress(array $state)
    {
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => [
                'bytes' => $state['scan']['bytes'],
                'files' => $state['scan']['files'],
                'db' => $state['db'],
                'free' => $state['free'],
            ]];
        }
        if ($state['phase'] === 'db') {
            return ['percent' => 97, 'phase' => 'db', 'detail' => 'db'];
        }

        return ['percent' => min(95, SizeScan::percent($state['scan'])), 'phase' => 'scan', 'detail' => 'scan'];
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }
}

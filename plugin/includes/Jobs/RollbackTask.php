<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Database\Importer;

defined('ABSPATH') || exit;

/**
 * Puts the database back from a backup.sql made by an earlier job. The dump
 * drops and recreates each table, so running it twice gives the same result.
 */
class RollbackTask implements Task
{
    /** @var string */
    private $file;

    /** @var callable */
    private $connect;

    public function __construct($backupFile, $connect)
    {
        $this->file = $backupFile;
        $this->connect = $connect;
    }

    public function initialState(array $params)
    {
        if (!is_file($this->file)) {
            throw new \InvalidArgumentException('The backup file for this job no longer exists.');
        }
        $state = Importer::initialState();
        $state['size'] = (int) filesize($this->file);

        return $state;
    }

    public function run(array $state, $deadline)
    {
        return (new Importer(call_user_func($this->connect), $this->file))->run($state, $deadline);
    }

    public function progress(array $state)
    {
        return ['percent' => Importer::percent($state), 'phase' => 'rollback', 'detail' => 'rollback'];
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }
}

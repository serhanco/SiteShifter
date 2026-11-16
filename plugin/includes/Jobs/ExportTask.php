<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Package\PackageBuilder;
use SiteShifter\Package\Scope;
use SiteShifter\Transfer\Pieces;

defined('ABSPATH') || exit;

/**
 * Builds a SiteShifter package (files + database.sql + manifest) and then
 * proves it is complete. Phases: build (resumable, from the core's
 * PackageBuilder) -> verify -> pieces (resumable checksum list, so
 * another site or a cloud copy can check every 8 MB piece) -> done. The zip is rewritten atomically by
 * ZipArchive, so only the SQL dump is a checkpointed append-only file.
 */
class ExportTask implements Task
{
    const WORK_FOLDER = 'wp-content/siteshifter-work';

    /** @var string */
    private $dir;

    /** @var callable function (array $options): PackageBuilder */
    private $builder;

    public function __construct($dir, $builder)
    {
        $this->dir = $dir;
        $this->builder = $builder;
    }

    public function zipFile()
    {
        return $this->dir . '/package.zip';
    }

    public function piecesFile()
    {
        return $this->dir . '/package.pieces.json';
    }

    public function sqlFile()
    {
        return $this->dir . '/database.sql';
    }

    /**
     * What to include, from the form values (scalars only: types is a comma list or "all", switches are 0/1).
     *
     * @return Scope
     */
    public static function scope(array $params)
    {
        $in = ['profile' => isset($params['profile']) ? (string) $params['profile'] : 'full'];
        if (isset($params['types'])) {
            $in['types'] = $params['types'] === 'all' ? 'all' : array_filter(array_map('trim', explode(',', (string) $params['types'])));
        }
        foreach (['media', 'users', 'comments', 'settings', 'themes', 'plugins', 'other_tables', 'other_files', 'revisions'] as $key) {
            if (isset($params[$key])) {
                $in[$key] = !empty($params[$key]);
            }
        }

        return Scope::fromArray($in);
    }

    /** Options the builder receives; never lets the work folder end up inside a package. */
    public static function options(array $params)
    {
        $scope = isset($params['scope']) && $params['scope'] === 'wp-content' ? 'wp-content' : 'root';
        $excludes = \SiteShifter\Package\Archiver::DEFAULT_EXCLUDES;
        $excludes[] = self::WORK_FOLDER;

        return ['scope' => $scope, 'include_foreign_tables' => !empty($params['all_tables']), 'excludes' => $excludes, 'content' => self::scope($params)->toArray()];
    }

    public function initialState(array $params)
    {
        return ['phase' => 'build', 'options' => self::options($params), 'build' => PackageBuilder::initialState(), 'check' => null, 'done' => false];
    }

    public function run(array $state, $deadline)
    {
        if ($state['phase'] === 'build') {
            $builder = call_user_func($this->builder, $state['options']);
            $state['build'] = $builder->run($state['build'], $deadline);
            if ($state['build']['done']) {
                $state['phase'] = 'verify';
            }

            return $state;
        }
        if ($state['phase'] === 'verify') {
            $state['check'] = PackageCheck::run($this->zipFile());
            if (!$state['check']['ok']) {
                // Not a retryable condition: the Runner will pause the job and show this message.
                throw new \RuntimeException('The package failed its check: ' . implode(' ', $state['check']['problems']));
            }
            $state['phase'] = 'pieces';
            $state['pieces'] = Pieces::start($this->zipFile());

            return $state;
        }
        if ($state['phase'] === 'pieces') {
            $state['pieces'] = Pieces::run($state['pieces'], $deadline);
            if ($state['pieces']['done']) {
                file_put_contents($this->piecesFile(), json_encode(Pieces::listing($state['pieces'])));
                $state['pieces'] = null;
                $state['phase'] = 'done';
                $state['done'] = true;
            }
        }

        return $state;
    }

    public function progress(array $state)
    {
        if ($state['done']) {
            $c = $state['check'];

            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => [
                'bytes' => $c['bytes'],
                'files' => $c['files'],
                'tables' => $c['tables'],
            ]];
        }
        if ($state['phase'] === 'verify' || $state['phase'] === 'pieces') {
            return ['percent' => 99, 'phase' => 'verify', 'detail' => 'verify'];
        }
        $p = PackageBuilder::progress($state['build']);

        return ['percent' => min(98, $p['percent']), 'phase' => $p['phase'], 'detail' => $p['phase']];
    }

    public function checkpointFiles(array $state)
    {
        return $state['phase'] === 'build' ? [$this->sqlFile()] : [];
    }
}

<?php

namespace SiteShifter\Package;

use SiteShifter\Database\Connection;
use SiteShifter\Database\Dumper;

/**
 * Creates a SiteShifter package: one zip holding files/, database.sql and
 * siteshifter.json. Resumable: call run() until state['done'] is true.
 */
class PackageBuilder
{
    /** @var Connection */
    private $db;

    /** @var string */
    private $abspath;

    /** @var string */
    private $prefix;

    /** @var string */
    private $workDir;

    /** @var string */
    private $zipPath;

    /** @var array */
    private $options;

    /**
     * Options: excludes (string[]), include_foreign_tables (bool), scope ("root"|"wp-content")
     */
    public function __construct(Connection $db, string $abspath, string $prefix, string $workDir, string $zipPath, array $options = [])
    {
        $this->db = $db;
        $this->abspath = rtrim(str_replace('\\', '/', $abspath), '/');
        $this->prefix = $prefix;
        $this->workDir = rtrim($workDir, '/');
        $this->zipPath = $zipPath;
        $this->options = $options + ['excludes' => Archiver::DEFAULT_EXCLUDES, 'include_foreign_tables' => false, 'scope' => 'root'];
    }

    public static function initialState(): array
    {
        return ['phase' => 'dump', 'dump' => Dumper::initialState(), 'archive' => Archiver::initialState(), 'done' => false];
    }

    public function run(array $state, float $deadline): array
    {
        if (!is_dir($this->workDir) && !@mkdir($this->workDir, 0755, true)) {
            throw new \RuntimeException("Cannot create {$this->workDir}");
        }
        $sqlFile = $this->workDir . '/' . Manifest::SQL;

        if ($state['phase'] === 'dump') {
            $state['dump'] = (new Dumper($this->db, $this->tables(), $sqlFile))->run($state['dump'], $deadline);
            if ($state['dump']['done']) {
                $state['phase'] = 'files';
            }
        }
        if ($state['phase'] === 'files' && microtime(true) < $deadline) {
            $state['archive'] = $this->archiver()->run($state['archive'], $deadline);
            if ($state['archive']['done']) {
                $state['phase'] = 'finish';
            }
        }
        if ($state['phase'] === 'finish') {
            $manifest = Manifest::build($this->db, $this->prefix, $this->abspath, $this->tables(), [
                'db' => ['largest_row' => $state['dump']['largest_row']],
                'files' => ['count' => $state['archive']['total'], 'bytes' => $state['archive']['bytes'], 'scope' => $this->options['scope']],
            ]);
            $manifestFile = $this->workDir . '/' . Manifest::FILE;
            file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->archiver()->addExtra([Manifest::SQL => $sqlFile, Manifest::FILE => $manifestFile]);
            @unlink($sqlFile);
            @unlink($manifestFile);
            $state['phase'] = 'done';
            $state['done'] = true;
            $state['manifest'] = $manifest;
        }

        return $state;
    }

    public function runAll(): array
    {
        return $this->run(self::initialState(), INF);
    }

    /** @return string[] */
    private function tables(): array
    {
        return $this->options['include_foreign_tables'] ? $this->db->tables() : $this->db->tables($this->prefix);
    }

    private function archiver(): Archiver
    {
        $root = $this->options['scope'] === 'wp-content' ? $this->abspath . '/wp-content' : $this->abspath;
        $prefix = $this->options['scope'] === 'wp-content' ? 'files/wp-content/' : 'files/';
        $excludes = $this->options['excludes'];
        if ($this->options['scope'] === 'wp-content') {
            $excludes = array_map(static function ($e) {
                return strpos($e, 'wp-content/') === 0 ? substr($e, 11) : $e;
            }, $excludes);
        }
        // Never pack the work folder or the package itself.
        $work = str_replace('\\', '/', $this->workDir);
        if (strpos($work, $root . '/') === 0) {
            $excludes[] = substr($work, strlen($root) + 1);
        }

        return new Archiver($root, $this->zipPath, $excludes, $prefix);
    }
}

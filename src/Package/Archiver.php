<?php

namespace SiteShifter\Package;

/**
 * Adds a site's files to a zip in resumable batches.
 *
 * ZipArchive compresses everything when close() is called, so each batch
 * is limited by size as well as count; files that are already compressed
 * (images, video, archives) are stored without recompression to save time.
 */
class Archiver
{
    /** Folders that are never worth moving: caches, other backup tools' archives, VCS data. */
    public const DEFAULT_EXCLUDES = [
        'wp-content/cache',
        'wp-content/et-cache',
        'wp-content/litespeed',
        'wp-content/upgrade',
        'wp-content/updraft',
        'wp-content/ai1wm-backups',
        'wp-content/backups-dup-lite',
        'wp-content/backups-dup-pro',
        'wp-content/uploads/backwpup',
        'wp-content/wpvividbackups',
        'wp-content/siteshifter',
        '.git',
        '.svn',
        'node_modules',
        'error_log',
    ];

    private const STORE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'webm', 'mp3', 'm4a', 'zip', 'gz', 'tgz', 'bz2', '7z', 'rar', 'woff', 'woff2', 'pdf'];

    /** @var string */
    private $root;

    /** @var string */
    private $zipPath;

    /** @var string */
    private $listFile;

    /** @var string[] */
    private $excludes;

    /** @var string */
    private $entryPrefix;

    /** @var int */
    private $maxBatchBytes;

    /**
     * @param string   $entryPrefix folder inside the zip, e.g. "files/"
     * @param string[] $excludes    paths relative to $root (folders or files)
     */
    public function __construct(string $root, string $zipPath, array $excludes = self::DEFAULT_EXCLUDES, string $entryPrefix = 'files/', int $maxBatchBytes = 64 * 1048576)
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is not installed.');
        }
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->zipPath = $zipPath;
        $this->listFile = $zipPath . '.list';
        $this->excludes = array_map(static function ($e) {
            return trim(str_replace('\\', '/', $e), '/');
        }, $excludes);
        $this->entryPrefix = $entryPrefix;
        $this->maxBatchBytes = $maxBatchBytes;
    }

    public static function initialState(): array
    {
        return ['phase' => 'scan', 'index' => 0, 'total' => 0, 'bytes' => 0, 'added_bytes' => 0, 'done' => false];
    }

    public static function percent(array $state): int
    {
        return \SiteShifter\Support\Progress::percent($state['added_bytes'] ?? 0, $state['bytes'] ?? 0, $state['done']);
    }

    public function run(array $state, float $deadline): array
    {
        if ($state['phase'] === 'scan') {
            [$state['total'], $state['bytes']] = $this->scan();
            $state['phase'] = 'add';
            $state['index'] = 0;
            @unlink($this->zipPath);
        }
        $first = true; // always make progress, even when the deadline has already passed
        while ($state['phase'] === 'add' && ($first || microtime(true) < $deadline)) {
            $first = false;
            $state = $this->addBatch($state, $deadline);
        }
        if ($state['phase'] === 'finished') {
            @unlink($this->listFile);
            $state['done'] = true;
        }

        return $state;
    }

    public function runAll(): array
    {
        return $this->run(self::initialState(), INF);
    }

    /** Adds extra files (database.sql, manifest) once the file phase is done. */
    public function addExtra(array $files): void
    {
        $zip = $this->open();
        foreach ($files as $entry => $path) {
            if (!$zip->addFile($path, $entry)) {
                throw new \RuntimeException("Could not add {$entry} to the package.");
            }
            if (preg_match('/\.(sql|json)$/', $entry)) {
                $zip->setCompressionName($entry, \ZipArchive::CM_DEFLATE);
            }
        }
        $this->close($zip);
    }

    /** @return array{0:int,1:int} file count, total bytes */
    private function scan(): array
    {
        $out = fopen($this->listFile, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Cannot write {$this->listFile}");
        }
        $count = 0;
        $bytes = 0;
        $zipReal = realpath(dirname($this->zipPath));
        $stack = [''];
        while ($stack !== []) {
            $rel = array_pop($stack);
            $dir = $this->root . ($rel === '' ? '' : '/' . $rel);
            $items = @scandir($dir);
            if ($items === false) {
                continue;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $rel === '' ? $item : $rel . '/' . $item;
                if ($this->excluded($path)) {
                    continue;
                }
                $full = $this->root . '/' . $path;
                if (is_link($full) && is_dir($full)) {
                    continue; // never follow directory symlinks (loops, other sites)
                }
                if (is_dir($full)) {
                    if (realpath($full) === $zipReal && $zipReal !== realpath($this->root)) {
                        continue;
                    }
                    $stack[] = $path;
                } elseif (is_file($full) && is_readable($full)) {
                    if (realpath($full) === realpath($this->zipPath)) {
                        continue;
                    }
                    fwrite($out, $path . "\n");
                    $count++;
                    $bytes += (int) @filesize($full);
                }
            }
        }
        fclose($out);

        return [$count, $bytes];
    }

    private function addBatch(array $state, float $deadline): array
    {
        $list = new \SplFileObject($this->listFile);
        $list->seek($state['index']);
        $zip = $this->open();
        $batchBytes = 0;
        while (!$list->eof()) {
            $path = rtrim((string) $list->current(), "\n");
            if ($path === '') {
                break;
            }
            $full = $this->root . '/' . $path;
            $entry = $this->entryPrefix . $path;
            if (is_file($full)) {
                if (!$zip->addFile($full, $entry)) {
                    throw new \RuntimeException("Could not add {$path} to the package.");
                }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, self::STORE_EXTENSIONS, true)) {
                    $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
                }
                $size = (int) @filesize($full);
                $batchBytes += $size;
                $state['added_bytes'] += $size;
            }
            $state['index']++;
            $list->next();
            if ($batchBytes >= $this->maxBatchBytes || $state['index'] % 2000 === 0 || microtime(true) >= $deadline) {
                break;
            }
        }
        $this->close($zip);
        if ($state['index'] >= $state['total']) {
            $state['phase'] = 'finished';
        }

        return $state;
    }

    private function excluded(string $path): bool
    {
        foreach ($this->excludes as $exclude) {
            if ($path === $exclude || strpos($path, $exclude . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function open(): \ZipArchive
    {
        $zip = new \ZipArchive();
        $result = $zip->open($this->zipPath, \ZipArchive::CREATE);
        if ($result !== true) {
            throw new \RuntimeException("Cannot open {$this->zipPath} (zip error {$result}).");
        }

        return $zip;
    }

    private function close(\ZipArchive $zip): void
    {
        if (!$zip->close()) {
            throw new \RuntimeException('Writing the package failed — is the disk full?');
        }
    }
}

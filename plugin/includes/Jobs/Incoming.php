<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * Packages waiting to be imported: uploaded in small pieces from the browser
 * (so no upload limit or time limit is hit), or placed in the incoming folder
 * by FTP for very large sites. Lives next to the jobs, behind the same guards.
 */
class Incoming
{
    const FOLDER = 'incoming';

    /** @var JobStore */
    private $store;

    /** @var callable|null function (string $dir): float|false  free disk space */
    private $free;

    public function __construct(JobStore $store, $free = null)
    {
        $this->store = $store;
        $this->free = $free;
    }

    public function dir()
    {
        $dir = $this->store->base() . '/' . self::FOLDER;
        $this->store->protect();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Cannot create the folder for uploaded packages. Check folder permissions.');
        }
        foreach (['index.php' => "<?php\n// Silence is golden.\n", '.htaccess' => "Require all denied\n"] as $name => $body) {
            if (!is_file($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $body);
            }
        }

        return $dir;
    }

    /** @return string id */
    public function begin($name, $total, $now = null)
    {
        $total = (int) $total;
        if ($total < 1) {
            throw new \InvalidArgumentException('The package is empty.');
        }
        $dir = $this->dir();
        $free = $this->free ? call_user_func($this->free, $dir) : (function_exists('disk_free_space') ? @disk_free_space($dir) : false);
        if ($free !== false && $free < $total * 1.1) {
            throw new \RuntimeException('Not enough disk space for this package (disk is full): it needs ' . (int) ceil($total / 1048576) . ' MB free.');
        }
        $id = JobStore::newId();
        file_put_contents($dir . '/' . $id . '.part', '');
        $this->writeMeta($id, ['id' => $id, 'name' => self::cleanName($name), 'total' => $total, 'created' => $now === null ? time() : $now, 'state' => 'uploading']);

        return $id;
    }

    /**
     * Appends the next piece. The client sends the offset it believes the file has;
     * if that is not the real size (a repeated or lost piece), nothing is written and
     * the real size is returned so the client can carry on from there.
     *
     * @return array{accepted:bool,size:int}
     */
    public function append($id, $offset, $pieceFile)
    {
        $meta = $this->meta($id);
        if (!$meta || $meta['state'] !== 'uploading') {
            throw new \InvalidArgumentException('Unknown upload.');
        }
        $part = $this->dir() . '/' . $id . '.part';
        $fh = fopen($part, 'cb');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new \RuntimeException('Cannot open the upload file. Check folder permissions.');
        }
        try {
            clearstatcache(true, $part);
            $size = (int) filesize($part);
            if ($size !== (int) $offset) {
                return ['accepted' => false, 'size' => $size];
            }
            $in = @fopen($pieceFile, 'rb');
            if ($in === false) {
                throw new \RuntimeException('The uploaded piece could not be read.');
            }
            fseek($fh, 0, SEEK_END);
            $written = (int) stream_copy_to_stream($in, $fh);
            fclose($in);
            fflush($fh);
            if ($written < 1 || $size + $written > $meta['total']) {
                ftruncate($fh, $size); // never keep a piece that does not fit the announced size
                throw new \RuntimeException($written < 1 ? 'The uploaded piece was empty (disk is full?).' : 'The upload is larger than announced.');
            }

            return ['accepted' => true, 'size' => $size + $written];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Checks the finished upload is a readable zip and makes it available.
     *
     * @return array the package record
     */
    public function finish($id)
    {
        $meta = $this->meta($id);
        if (!$meta || $meta['state'] !== 'uploading') {
            throw new \InvalidArgumentException('Unknown upload.');
        }
        $part = $this->dir() . '/' . $id . '.part';
        clearstatcache(true, $part);
        if ((int) filesize($part) !== $meta['total']) {
            throw new \RuntimeException('The upload is incomplete: ' . (int) filesize($part) . ' of ' . $meta['total'] . ' bytes.');
        }
        $zip = class_exists('ZipArchive') ? new \ZipArchive() : null;
        $ok = $zip && $zip->open($part) === true;
        if ($ok) {
            $zip->close();
        }
        if (!$ok) {
            $this->delete($id);
            throw new \RuntimeException('The file is not a readable zip. It may be damaged; upload it again.');
        }
        rename($part, $this->dir() . '/' . $id . '.zip');
        $meta['state'] = 'ready';
        $this->writeMeta($id, $meta);

        return $this->describe($meta);
    }

    /** @return array[] ready packages, newest first (files put there by FTP included) */
    public function all()
    {
        $out = [];
        foreach ((array) glob($this->dir() . '/*.zip') as $file) {
            $base = basename($file, '.zip');
            $meta = JobStore::validId($base) ? $this->meta($base) : null;
            if (!$meta) {
                $meta = ['id' => md5(basename($file)), 'name' => basename($file), 'total' => (int) filesize($file), 'created' => (int) filemtime($file), 'state' => 'ready'];
            }
            if ($meta['state'] === 'ready') {
                $out[] = $this->describe($meta) + ['file' => $file];
            }
        }
        usort($out, static function ($a, $b) {
            return $b['created'] <=> $a['created'];
        });

        return $out;
    }

    /** @return string|null path of a ready package */
    public function path($id)
    {
        foreach ($this->all() as $p) {
            if ($p['id'] === $id) {
                return $p['file'];
            }
        }

        return null;
    }

    public function delete($id)
    {
        if (!JobStore::validId($id)) {
            return false;
        }
        $found = false;
        foreach ($this->all() as $p) {
            if ($p['id'] === $id && is_file($p['file'])) {
                @unlink($p['file']);
                $found = true;
            }
        }
        $dir = $this->dir();
        foreach (['.part', '.zip', '.json'] as $ext) {
            if (is_file($dir . '/' . $id . $ext)) {
                @unlink($dir . '/' . $id . $ext);
                $found = true;
            }
        }

        return $found;
    }

    /** Removes unfinished uploads older than $age seconds. */
    public function purge($age, $now = null)
    {
        $now = $now === null ? time() : $now;
        foreach ((array) glob($this->dir() . '/*.part') as $part) {
            if ($now - (int) filemtime($part) > $age) {
                $this->delete(basename($part, '.part'));
            }
        }
    }

    private function describe(array $meta)
    {
        return ['id' => $meta['id'], 'name' => $meta['name'], 'bytes' => (int) $meta['total'], 'created' => (int) $meta['created']];
    }

    private function meta($id)
    {
        if (!JobStore::validId($id)) {
            return null;
        }
        $file = $this->dir() . '/' . $id . '.json';
        $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($meta) ? $meta : null;
    }

    private function writeMeta($id, array $meta)
    {
        file_put_contents($this->dir() . '/' . $id . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function cleanName($name)
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename((string) $name));

        return $name === '' ? 'package.zip' : substr($name, 0, 120);
    }
}

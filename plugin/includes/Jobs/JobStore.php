<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * Keeps jobs on disk in wp-content/siteshifter-work/<random id>/job.json.
 * Files, not wp_options: options caches and cleanup plugins cannot lose
 * them, and a 2 GB job never bloats the options table.
 *
 * The folder is closed to the web (.htaccess, web.config, index.php) and,
 * because nginx ignores those, every job lives under a 32-hex random name
 * that is never printed in a URL. Downloads go through PHP.
 */
class JobStore
{
    const STATE = 'job.json';
    const LOCK = 'lock';

    /** @var string */
    private $base;

    public function __construct($base)
    {
        $this->base = rtrim(str_replace('\\', '/', $base), '/');
    }

    public function base()
    {
        return $this->base;
    }

    /** Creates the base folder and its guard files (idempotent). */
    public function protect()
    {
        if (!is_dir($this->base) && !@mkdir($this->base, 0755, true)) {
            throw new \RuntimeException('Cannot create the SiteShifter work folder in wp-content. Check folder permissions.');
        }
        $guards = [
            '.htaccess' => "# SiteShifter work files are never served directly.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
            'index.html' => '',
        ];
        foreach ($guards as $name => $body) {
            $path = $this->base . '/' . $name;
            if (!is_file($path)) {
                file_put_contents($path, $body);
            }
        }
    }

    public static function newId()
    {
        return bin2hex(random_bytes(16));
    }

    public static function validId($id)
    {
        return is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }

    public function dir($id)
    {
        if (!self::validId($id)) {
            throw new \InvalidArgumentException('Invalid job id.');
        }

        return $this->base . '/' . $id;
    }

    /** @return array the new job record */
    public function create($type, array $params, array $state, $now = null)
    {
        $this->protect();
        $id = self::newId();
        $dir = $this->dir($id);
        if (!@mkdir($dir, 0755)) {
            throw new \RuntimeException('Cannot create the job folder.');
        }
        file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        $now = $now === null ? time() : $now;
        $job = [
            'id' => $id,
            'type' => $type,
            'params' => $params,
            'status' => 'running',
            'state' => $state,
            'checkpoints' => [],
            'progress' => ['percent' => 0, 'phase' => 'start'],
            'errors' => 0,
            'last_error' => null,
            'slices' => 0,
            'created' => $now,
            'updated' => $now,
        ];
        $this->save($job);

        return $job;
    }

    /** @return array|null */
    public function load($id)
    {
        $file = $this->dir($id) . '/' . self::STATE;
        if (!is_file($file)) {
            return null;
        }
        $job = json_decode((string) file_get_contents($file), true);

        return is_array($job) ? $job : null;
    }

    /** Atomic: write a temp file, then rename over the old one. */
    public function save(array $job)
    {
        $dir = $this->dir($job['id']);
        $tmp = $dir . '/' . self::STATE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot save job state (disk full?).');
        }
        if (!@rename($tmp, $dir . '/' . self::STATE)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot save job state.');
        }
    }

    public function lock($id)
    {
        return new Lock($this->dir($id) . '/' . self::LOCK);
    }

    /** @return array[] jobs, newest first */
    public function all()
    {
        $jobs = [];
        foreach ((array) @scandir($this->base) as $name) {
            if (self::validId($name) && ($job = $this->load($name))) {
                $jobs[] = $job;
            }
        }
        usort($jobs, static function ($a, $b) {
            return $b['created'] <=> $a['created'];
        });

        return $jobs;
    }

    /** An applied import keeps the old site's tables and folders here until it is discarded. */
    public function holdsBackup($id)
    {
        $file = $this->dir($id) . '/swap.json';
        $swap = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($swap) && in_array($swap['state'] ?? '', ['live', 'swapping'], true);
    }

    public function delete($id)
    {
        $dir = $this->dir($id);
        if (!is_dir($dir)) {
            return;
        }
        if ($this->holdsBackup($id)) {
            throw new \RuntimeException('This import is live and its backup is kept here. Discard or undo the import first.');
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Removes finished, failed or cancelled jobs older than $age seconds.
     * Running or paused jobs are kept: they may still be resumed.
     */
    public function purge($age, $now = null)
    {
        $now = $now === null ? time() : $now;
        foreach ($this->all() as $job) {
            if (in_array($job['status'], ['done', 'cancelled', 'failed'], true) && $now - $job['updated'] > $age && !$this->holdsBackup($job['id'])) {
                $this->delete($job['id']);
            }
        }
    }
}

<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * One slice per job at a time: a second tab or a retried request waits its
 * turn instead of writing the same files twice. flock() is released by the
 * OS when PHP dies, so a killed request never leaves a stuck lock.
 */
class Lock
{
    /** @var resource|null */
    private $fh;

    /** @var string */
    private $file;

    public function __construct($file)
    {
        $this->file = $file;
    }

    /** @return bool false when another request holds the lock */
    public function acquire()
    {
        $fh = @fopen($this->file, 'c');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open lock file ' . basename($this->file));
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        $this->fh = $fh;

        return true;
    }

    public function release()
    {
        if ($this->fh) {
            flock($this->fh, LOCK_UN);
            fclose($this->fh);
            $this->fh = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}

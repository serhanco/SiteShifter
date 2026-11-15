<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * "Host test": proves the slice loop works on this server before a real
 * migration. Writes a test file in 1 MB pieces until $params['mb'] is reached,
 * across as many requests as the host's time limit needs, then checks it.
 * This exercises time limits, disk writes, the lock, the REST/ajax path and
 * any cache or firewall that might break long jobs.
 */
class HostTestTask implements Task
{
    const PIECE = 1048576;

    /** @var string */
    private $dir;

    /** @var float seconds to wait per piece (tests use it to force many slices) */
    private $delay;

    public function __construct($dir, $delay = 0.0)
    {
        $this->dir = $dir;
        $this->delay = (float) $delay;
    }

    public function file()
    {
        return $this->dir . '/host-test.bin';
    }

    public function initialState(array $params)
    {
        $mb = isset($params['mb']) ? (int) $params['mb'] : 64;

        return ['total' => max(1, min(1024, $mb)), 'written' => 0, 'done' => false, 'verified' => false];
    }

    public function run(array $state, $deadline)
    {
        $fh = fopen($this->file(), $state['written'] > 0 ? 'ab' : 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot write the host test file.');
        }
        $first = true;
        while ($state['written'] < $state['total'] && ($first || microtime(true) < $deadline)) {
            $first = false;
            $piece = $this->piece($state['written']);
            if (fwrite($fh, $piece) !== strlen($piece)) {
                fclose($fh);
                throw new \RuntimeException('Disk write failed (disk full?).');
            }
            $state['written']++;
            if ($this->delay > 0) {
                usleep((int) ($this->delay * 1000000));
            }
        }
        fclose($fh);

        if ($state['written'] >= $state['total']) {
            clearstatcache(true, $this->file());
            if ((int) filesize($this->file()) !== $state['total'] * self::PIECE) {
                throw new \RuntimeException('Host test file has the wrong size.');
            }
            @unlink($this->file());
            $state['verified'] = true;
            $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        $percent = $state['done'] ? 100 : (int) min(99, floor(100 * $state['written'] / $state['total']));

        return ['percent' => $percent, 'phase' => 'host_test', 'detail' => $state['written'] . ' / ' . $state['total'] . ' MB'];
    }

    public function checkpointFiles(array $state)
    {
        return $state['done'] ? [] : [$this->file()];
    }

    /** Deterministic content so a duplicated piece would be detectable. */
    public function piece($n)
    {
        return str_repeat(pack('N', $n), self::PIECE / 4);
    }
}

<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * How long one slice may run. Shared hosts kill PHP at max_execution_time
 * (often 30 s) and proxies at ~60 s, so a slice uses at most 60% of the
 * PHP limit, never more than 20 s, minus what the request already used.
 */
class Budget
{
    const CAP = 20.0;
    const SHARE = 0.6;
    const MIN = 1.0;
    const MEMORY_SHARE = 0.7;

    /**
     * @param float      $now          microtime(true)
     * @param int        $maxExec      ini max_execution_time (0 = unlimited)
     * @param float|null $requestStart REQUEST_TIME_FLOAT
     * @return float absolute deadline
     */
    public static function deadline($now, $maxExec, $requestStart = null)
    {
        $limit = $maxExec > 0 ? min(self::CAP, $maxExec * self::SHARE) : self::CAP;
        $used = $requestStart !== null ? max(0.0, $now - $requestStart) : 0.0;

        return $now + max(self::MIN, $limit - $used);
    }

    /** Bytes from a php.ini size ("128M", "1G", "-1" = unlimited → 0). */
    public static function bytes($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $n = (float) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }

        return (int) $n;
    }

    /** True when memory use is high enough that the slice should end now. */
    public static function memoryTight($used, $limit)
    {
        return $limit > 0 && $used >= $limit * self::MEMORY_SHARE;
    }
}

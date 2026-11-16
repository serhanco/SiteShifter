<?php

namespace SiteShifter\Transfer;

/**
 * Waits between attempts of one piece: 2, 5, 15, 30, 60 seconds, then the
 * error is final. The wait lives in the transfer state, so it survives the
 * request ending; a request never sleeps past its own deadline.
 */
class Backoff
{
    const DELAYS = [2, 5, 15, 30, 60];

    /** @return int|null seconds to wait before attempt $attempt+1, null when attempts are used up */
    public static function delay($attempt)
    {
        $attempt = (int) $attempt;

        return $attempt >= 1 && $attempt <= count(self::DELAYS) ? self::DELAYS[$attempt - 1] : null;
    }

    /**
     * Records a failure in $state (keys attempts, wait_until).
     *
     * @throws TransferError when the error is not transient or attempts are used up
     */
    public static function fail(array $state, TransferError $e, $now)
    {
        if (!$e->isTransient()) {
            throw $e;
        }
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        $delay = self::delay($state['attempts']);
        if ($delay === null) {
            throw new TransferError($e->getMessage() . ' (gave up after ' . count(self::DELAYS) . ' retries)', false, $e->getCode(), $e);
        }
        $state['wait_until'] = $now + $delay;
        $state['last_error'] = $e->getMessage();

        return $state;
    }

    /**
     * Sleeps out a pending wait when it ends before the deadline.
     *
     * @param callable|null $sleep function (float $seconds): void, for tests
     * @return bool true when work may go on in this request
     */
    public static function ready(array $state, $deadline, $now, $sleep = null)
    {
        $until = (float) ($state['wait_until'] ?? 0);
        if ($until <= $now) {
            return true;
        }
        if ($until >= $deadline) {
            return false;
        }
        $sleep = $sleep ?: static function ($s) {
            usleep((int) ($s * 1e6));
        };
        $sleep($until - $now);

        return true;
    }

    /** Clears the wait after a piece went through. */
    public static function ok(array $state)
    {
        $state['attempts'] = 0;
        $state['wait_until'] = 0;
        $state['last_error'] = null;

        return $state;
    }
}

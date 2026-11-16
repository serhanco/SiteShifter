<?php

namespace SiteShifter\Transfer;

/**
 * A transfer step that failed. Transient failures (a dropped connection, a busy
 * server, a rate limit) are retried with growing waits; the rest stop the job
 * with the message, which says what to do.
 */
class TransferError extends \RuntimeException
{
    /** @var bool */
    private $transient;

    public function __construct($message, $transient = false, $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->transient = (bool) $transient;
    }

    public static function transient($message, $code = 0)
    {
        return new self($message, true, $code);
    }

    public function isTransient()
    {
        return $this->transient;
    }

    /** HTTP status codes worth trying again: no answer, timeout, rate limit, server trouble. */
    public static function transientStatus($code)
    {
        $code = (int) $code;

        return $code === 0 || $code === 408 || $code === 425 || $code === 429 || $code >= 500;
    }
}

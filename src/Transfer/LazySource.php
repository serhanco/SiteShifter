<?php

namespace SiteShifter\Transfer;

/**
 * Opens the real Source only when it is first used, so a request that only
 * waits out a retry, or a storage whose open() costs network calls, does no
 * needless work.
 */
class LazySource implements Source
{
    /** @var callable function (): Source */
    private $open;

    /** @var Source|null */
    private $source;

    public function __construct($open)
    {
        $this->open = $open;
    }

    public function stat()
    {
        return $this->source()->stat();
    }

    public function fetch($from, $to, $toFile)
    {
        $this->source()->fetch($from, $to, $toFile);
    }

    private function source()
    {
        return $this->source ?: ($this->source = call_user_func($this->open));
    }
}

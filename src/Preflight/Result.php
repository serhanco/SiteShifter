<?php

namespace SiteShifter\Preflight;

/** One line of the pre-flight report. */
class Result
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const BLOCK = 'block';
    public const INFO = 'info';

    /** @var string */
    public $id;
    /** @var string */
    public $level;
    /** @var string */
    public $title;
    /** @var string */
    public $message;
    /** @var string */
    public $fix;

    public function __construct(string $id, string $level, string $title, string $message = '', string $fix = '')
    {
        $this->id = $id;
        $this->level = $level;
        $this->title = $title;
        $this->message = $message;
        $this->fix = $fix;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

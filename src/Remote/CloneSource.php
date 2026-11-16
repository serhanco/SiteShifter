<?php

namespace SiteShifter\Remote;

use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\Source;

/** The package the source site built, read through signed piece requests. */
class CloneSource implements Source
{
    private $client;
    private $job;
    private $name;
    private $size;
    private $pieces;

    public function __construct(CloneClient $client, $job, $name, $size, $pieces)
    {
        $this->client = $client;
        $this->job = (string) $job;
        $this->name = (string) $name;
        $this->size = (int) $size;
        $this->pieces = $pieces !== null ? Pieces::parse($pieces) : null;
    }

    public function stat()
    {
        return ['name' => $this->name, 'size' => $this->size, 'pieces' => $this->pieces];
    }

    public function fetch($from, $to, $toFile)
    {
        $this->client->piece($this->job, $from, $to, $toFile);
    }
}

<?php

namespace SiteShifter\Transfer\Google;

use SiteShifter\Transfer\Source;

/** One package in Google Drive, read in byte ranges. */
class DriveSource implements Source
{
    private $drive;
    private $id;
    private $name;
    private $size;
    private $pieces;

    public function __construct(Drive $drive, $id, $name, $size, $pieces)
    {
        $this->drive = $drive;
        $this->id = (string) $id;
        $this->name = (string) $name;
        $this->size = (int) $size;
        $this->pieces = $pieces;
    }

    public function stat()
    {
        return ['name' => $this->name, 'size' => $this->size, 'pieces' => $this->pieces];
    }

    public function fetch($from, $to, $toFile)
    {
        $this->drive->fetchRange($this->id, $from, $to, $toFile);
    }
}

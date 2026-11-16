<?php

namespace SiteShifter\Transfer;

/** A package that can be read in byte ranges: a link on another site, a cloud file. */
interface Source
{
    /**
     * @return array{name:string,size:int,pieces:array|null} pieces is the Pieces listing when the far side has one
     * @throws TransferError
     */
    public function stat();

    /**
     * Writes bytes $from..$to (inclusive) to $toFile, replacing it.
     *
     * @throws TransferError
     */
    public function fetch($from, $to, $toFile);
}

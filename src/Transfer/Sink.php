<?php

namespace SiteShifter\Transfer;

/** Where a download lands, piece by piece (the plugin's incoming folder, a local file). */
interface Sink
{
    /** @return string id of the new, empty file */
    public function begin($name, $size);

    /**
     * Appends $pieceFile at $offset. When $offset is not the real size (a repeated piece) nothing is written.
     *
     * @return int the file's real size afterwards
     */
    public function append($id, $offset, $pieceFile);

    /** @return array what the caller reports when the download is complete */
    public function finish($id);
}

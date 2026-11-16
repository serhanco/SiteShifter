<?php

namespace SiteShifter\Transfer;

/** A package file on this server, read like any other Source. */
class FileSource implements Source
{
    /** @var string */
    private $file;

    /** @var array|null */
    private $pieces;

    public function __construct($file, $pieces = null)
    {
        $this->file = (string) $file;
        $this->pieces = $pieces;
    }

    public function stat()
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file)) {
            throw new TransferError('The package file is gone.');
        }

        return ['name' => basename($this->file), 'size' => (int) filesize($this->file), 'pieces' => $this->pieces];
    }

    public function fetch($from, $to, $toFile)
    {
        $in = @fopen($this->file, 'rb');
        $out = @fopen($toFile, 'wb');
        if (!$in || !$out) {
            throw new TransferError('Cannot read the package file.');
        }
        fseek($in, $from);
        stream_copy_to_stream($in, $out, $to - $from + 1);
        fclose($in);
        fclose($out);
    }
}

<?php

namespace SiteShifter\Database;

/**
 * Streams SQL statements out of a dump file of any size, starting at a
 * byte offset, so an import can stop and resume between requests.
 *
 * Understands quotes, backticks, escapes, "-- " and "#" comments, block
 * comments (MySQL's executable "/*!" comments are kept), and the
 * DELIMITER command used around triggers and procedures in mysqldump files.
 */
class SqlReader
{
    /** @var int */
    private $chunk;

    /** @var resource */
    private $fh;

    /** @var string */
    private $buffer = '';

    /** @var int read position inside $buffer */
    private $pos = 0;

    /** @var int byte offset of $buffer[0] in the file */
    private $bufferStart;

    /** @var string */
    private $delimiter;

    /** @var bool */
    private $eof = false;

    public function __construct(string $file, int $offset = 0, string $delimiter = ';', int $chunk = 1048576)
    {
        $this->chunk = max(1, $chunk);
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot read {$file}");
        }
        $this->fh = $fh;
        $this->delimiter = $delimiter;
        $this->bufferStart = $offset;
        if ($offset > 0) {
            fseek($fh, $offset);
        } elseif (fread($fh, 3) === "\xEF\xBB\xBF") {
            $this->bufferStart = 3; // skip UTF-8 byte order mark
        } else {
            fseek($fh, 0);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /** Byte offset where the next statement starts; store it to resume later. */
    public function offset(): int
    {
        return $this->bufferStart + $this->pos;
    }

    public function size(): int
    {
        $stat = fstat($this->fh);

        return (int) $stat['size'];
    }

    /** Returns the next statement without its delimiter, or null at the end. */
    public function next(): ?string
    {
        while (($statement = $this->scan()) !== null) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)$/i', $trimmed, $m)) {
                $this->delimiter = $m[1];
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    private function scan(): ?string
    {
        $out = '';
        $quote = null;
        $i = $this->pos;
        while (true) {
            if ($i >= strlen($this->buffer)) {
                $out .= substr($this->buffer, $this->pos, $i - $this->pos);
                $this->pos = $i;
                if (!$this->fill()) {
                    return trim($out) === '' ? null : $out;
                }
                $i = $this->pos;
                continue;
            }

            // Jump over ordinary bytes in one step.
            $mask = $quote !== null ? $quote . '\\' : "'\"`-#/\n" . $this->delimiter[0];
            $span = strcspn($this->buffer, $mask, $i);
            if ($span > 0) {
                $i += $span;
                continue;
            }
            $c = $this->buffer[$i];

            if ($quote !== null) {
                if ($c === '\\' && $quote !== '`') {
                    $this->ensure($i + 1, $out, $i);
                    $i = min($i + 2, strlen($this->buffer));
                    continue;
                }
                // $c is the closing quote, unless it is doubled ('').
                if (!$this->ensure($i + 1, $out, $i)) {
                    $quote = null;
                    $i++;
                    continue;
                }
                if ($this->buffer[$i + 1] === $quote) {
                    $i += 2;
                    continue;
                }
                $quote = null;
                $i++;
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $i++;
                continue;
            }

            if ($c === '#' || ($c === '-' && $this->ensure($i + 2, $out, $i) && $this->buffer[$i + 1] === '-' && ctype_space($this->buffer[$i + 2]))) {
                $out .= substr($this->buffer, $this->pos, $i - $this->pos);
                $end = $this->find("\n", $i);
                $this->pos = $i = ($end === null ? strlen($this->buffer) : $end + 1);
                $out .= "\n";
                continue;
            }

            if ($c === '/' && $this->ensure($i + 1, $out, $i) && $this->buffer[$i + 1] === '*') {
                $out .= substr($this->buffer, $this->pos, $i - $this->pos);
                $end = $this->find('*/', $i + 2);
                $stop = $end === null ? strlen($this->buffer) : $end + 2;
                $out .= ($this->buffer[$i + 2] ?? '') === '!' ? substr($this->buffer, $i, $stop - $i) : ' ';
                $this->pos = $i = $stop;
                continue;
            }

            if ($c === "\n") {
                // A DELIMITER command ends at the newline, not at a delimiter.
                $line = $out . substr($this->buffer, $this->pos, $i - $this->pos);
                if (strlen($line) < 200 && preg_match('/^\s*DELIMITER\s+\S+\s*$/i', $line)) {
                    $this->pos = $i + 1;
                    return $line;
                }
                $i++;
                continue;
            }

            $d = $this->delimiter;
            if ($c === $d[0] && $i - $this->pos + strlen($out) < 200
                && preg_match('/^\s*DELIMITER\s/i', $out . substr($this->buffer, $this->pos, $i - $this->pos))) {
                $i++; // the argument of DELIMITER, e.g. "DELIMITER ;;"
                continue;
            }
            if ($c === $d[0] && $this->ensure($i + strlen($d) - 1, $out, $i) && substr_compare($this->buffer, $d, $i, strlen($d)) === 0) {
                $out .= substr($this->buffer, $this->pos, $i - $this->pos);
                $this->pos = $i + strlen($d);
                return $out;
            }

            $i++;
        }
    }

    /**
     * Makes sure $buffer[$index] exists if the file has it. Filling may
     * compact the buffer, so pending bytes are moved into $out first and
     * $i is rebased.
     */
    private function ensure(int $index, string &$out, int &$i): bool
    {
        while ($index >= strlen($this->buffer)) {
            $out .= substr($this->buffer, $this->pos, $i - $this->pos);
            $this->pos = $i;
            $ok = $this->fill();
            $shift = $i - $this->pos;
            $i -= $shift;
            $index -= $shift;
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function find(string $needle, int $from): ?int
    {
        while (true) {
            $pos = strpos($this->buffer, $needle, $from);
            if ($pos !== false) {
                return $pos;
            }
            if ($this->eof) {
                return null;
            }
            // Keep everything from $this->pos so offsets stay valid.
            $before = $this->pos;
            if (!$this->fill(false)) {
                return null;
            }
            $from -= $before - $this->pos;
        }
    }

    /**
     * Reads the next chunk. Bytes before $pos are dropped (compacted) when
     * $compact is true, which shifts $pos to 0.
     */
    private function fill(bool $compact = true): bool
    {
        if ($this->eof) {
            return false;
        }
        if ($compact && $this->pos > 0) {
            $this->buffer = (string) substr($this->buffer, $this->pos);
            $this->bufferStart += $this->pos;
            $this->pos = 0;
        }
        $data = fread($this->fh, $this->chunk);
        if ($data === false || $data === '') {
            $this->eof = true;

            return false;
        }
        $this->buffer .= $data;

        return true;
    }
}

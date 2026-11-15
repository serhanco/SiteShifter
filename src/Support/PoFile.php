<?php

namespace SiteShifter\Support;

/** Minimal .po reader and .mo writer, so translations can be built without gettext. */
class PoFile
{
    /**
     * @return array<string,string> msgid => msgstr; the header is stored under ''
     */
    public static function parse(string $po): array
    {
        $out = [];
        $id = $str = null;
        $target = null;
        foreach (preg_split('/\R/', $po) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                if ($line === '' && $id !== null && $str !== null) {
                    self::store($out, $id, $str);
                    $id = $str = $target = null;
                }
                continue;
            }
            if (strpos($line, 'msgid ') === 0) {
                if ($id !== null && $str !== null) {
                    self::store($out, $id, $str);
                }
                $id = self::unquote(substr($line, 6));
                $str = null;
                $target = 'id';
            } elseif (strpos($line, 'msgstr ') === 0) {
                $str = self::unquote(substr($line, 7));
                $target = 'str';
            } elseif ($line[0] === '"') {
                if ($target === 'id') {
                    $id .= self::unquote($line);
                } elseif ($target === 'str') {
                    $str .= self::unquote($line);
                }
            }
        }
        if ($id !== null && $str !== null) {
            self::store($out, $id, $str);
        }

        return $out;
    }

    private static function store(array &$out, string $id, string $str): void
    {
        if ($str !== '' || $id === '') {
            $out[$id] = $str;
        }
    }

    private static function unquote(string $s): string
    {
        $s = trim($s);

        return stripcslashes(substr($s, 1, -1));
    }

    /** Binary .mo (little endian, no hash table). */
    public static function compile(array $entries): string
    {
        ksort($entries, SORT_STRING);
        $n = count($entries);
        $ids = $strs = '';
        $idTable = $strTable = [];
        foreach ($entries as $id => $str) {
            $idTable[] = [strlen($id), strlen($ids)];
            $ids .= $id . "\0";
            $strTable[] = [strlen($str), strlen($strs)];
            $strs .= $str . "\0";
        }
        $headerSize = 28;
        $idStart = $headerSize + 16 * $n;
        $strStart = $idStart + strlen($ids);
        $out = pack('V*', 0x950412de, 0, $n, $headerSize, $headerSize + 8 * $n, 0, 0);
        foreach ($idTable as $t) {
            $out .= pack('VV', $t[0], $idStart + $t[1]);
        }
        foreach ($strTable as $t) {
            $out .= pack('VV', $t[0], $strStart + $t[1]);
        }

        return $out . $ids . $strs;
    }

    /** Reads a .mo written by compile() (for tests). */
    public static function readMo(string $mo): array
    {
        $h = unpack('V7', substr($mo, 0, 28));
        $n = $h[3];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $o = unpack('Vlen/Voff', substr($mo, $h[4] + 8 * $i, 8));
            $t = unpack('Vlen/Voff', substr($mo, $h[5] + 8 * $i, 8));
            $out[substr($mo, $o['off'], $o['len'])] = substr($mo, $t['off'], $t['len']);
        }

        return $out;
    }
}

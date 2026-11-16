<?php

namespace SiteShifter\Transfer;

/**
 * SHA-256 of a package in fixed 8 MB pieces. Each piece can be checked the
 * moment it arrives, a broken one is fetched again on its own, and the digest
 * (SHA-256 over the piece hashes) names the whole file. Hashing is resumable,
 * so a 5 GB package never needs one long request.
 */
class Pieces
{
    const PIECE = 8388608;
    const VERSION = 1;

    public static function start($file)
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new TransferError('The package file cannot be read.');
        }

        return ['file' => (string) $file, 'size' => (int) filesize($file), 'hashes' => [], 'done' => false];
    }

    /** Hashes pieces until the deadline (at least one per call). */
    public static function run(array $state, $deadline)
    {
        $count = self::count($state['size']);
        $first = true;
        while (count($state['hashes']) < $count && ($first || microtime(true) < $deadline)) {
            $first = false;
            $i = count($state['hashes']);
            $state['hashes'][] = self::hashRange($state['file'], $i * self::PIECE, self::length($state['size'], $i));
        }
        if (count($state['hashes']) >= $count) {
            if ((int) filesize($state['file']) !== $state['size']) {
                throw new TransferError('The package changed while it was being checked.');
            }
            $state['done'] = true;
        }

        return $state;
    }

    /** Hashes a whole file at once (tests, small files). */
    public static function ofFile($file)
    {
        $s = self::start($file);
        while (!$s['done']) {
            $s = self::run($s, INF);
        }

        return self::listing($s);
    }

    /** @return array{v:int,size:int,piece:int,hashes:string[],digest:string} what is stored next to the package */
    public static function listing(array $state)
    {
        return [
            'v' => self::VERSION,
            'size' => (int) $state['size'],
            'piece' => self::PIECE,
            'hashes' => array_values($state['hashes']),
            'digest' => self::digest($state['hashes']),
        ];
    }

    public static function digest(array $hashes)
    {
        return hash('sha256', implode('', $hashes));
    }

    /**
     * Checks a listing read from elsewhere.
     *
     * @return array|null the listing, or null when it is unusable
     */
    public static function parse($listing)
    {
        if (is_string($listing)) {
            $listing = json_decode($listing, true);
        }
        if (!is_array($listing) || (int) ($listing['piece'] ?? 0) !== self::PIECE || (int) ($listing['size'] ?? 0) < 1 || !isset($listing['hashes']) || !is_array($listing['hashes'])) {
            return null;
        }
        $hashes = array_values($listing['hashes']);
        if (count($hashes) !== self::count($listing['size'])) {
            return null;
        }
        foreach ($hashes as $h) {
            if (!is_string($h) || !preg_match('/^[a-f0-9]{64}$/', $h)) {
                return null;
            }
        }
        $digest = self::digest($hashes);
        if (isset($listing['digest']) && $listing['digest'] !== $digest) {
            return null;
        }

        return ['v' => self::VERSION, 'size' => (int) $listing['size'], 'piece' => self::PIECE, 'hashes' => $hashes, 'digest' => $digest];
    }

    public static function count($size)
    {
        return (int) ceil(max(0, (int) $size) / self::PIECE);
    }

    public static function length($size, $index)
    {
        return (int) min(self::PIECE, $size - $index * self::PIECE);
    }

    public static function hashRange($file, $offset, $length)
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            throw new TransferError('The package file cannot be read.');
        }
        fseek($fh, $offset);
        $ctx = hash_init('sha256');
        $left = $length;
        while ($left > 0) {
            $chunk = fread($fh, min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($ctx, $chunk);
            $left -= strlen($chunk);
        }
        fclose($fh);
        if ($left > 0) {
            throw new TransferError('The package file is shorter than expected.');
        }

        return hash_final($ctx);
    }
}

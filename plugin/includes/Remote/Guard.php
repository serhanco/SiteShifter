<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Remote;

defined('ABSPATH') || exit;

/**
 * Remembers the clone requests already answered (so a captured request
 * cannot be sent again) and slows down an address that keeps failing.
 * One small file, locked while it is changed; old entries drop out.
 */
class Guard
{
    const GUARD = "<?php exit; ?>\n";
    /** Failed requests allowed from one address within WINDOW seconds. */
    const MAX_FAILS = 20;
    const WINDOW = 600;

    /** @var string */
    private $file;

    /** @var callable */
    private $clock;

    public function __construct($file, $clock = null)
    {
        $this->file = (string) $file;
        $this->clock = $clock ?: 'time';
    }

    /** @return bool true when the nonce was seen before; otherwise records it until $until */
    public function seen($nonce, $until)
    {
        return $this->change(static function (array &$d) use ($nonce, $until) {
            if (isset($d['n'][$nonce])) {
                return true;
            }
            $d['n'][$nonce] = (int) $until;

            return false;
        });
    }

    public function blocked($ip)
    {
        $now = $this->now();
        $d = $this->read();
        $f = $d['f'][self::who($ip)] ?? null;

        return is_array($f) && $f[1] > $now - self::WINDOW && $f[0] >= self::MAX_FAILS;
    }

    public function fail($ip)
    {
        $now = $this->now();
        $who = self::who($ip);
        $this->change(static function (array &$d) use ($who, $now) {
            $f = $d['f'][$who] ?? [0, 0];
            $d['f'][$who] = [$f[1] > $now - self::WINDOW ? $f[0] + 1 : 1, $now];
        });
    }

    private static function who($ip)
    {
        return substr(hash('sha256', (string) $ip), 0, 16);
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }

    private function read()
    {
        $raw = is_file($this->file) ? (string) @file_get_contents($this->file) : '';

        return self::decode($raw);
    }

    private static function decode($raw)
    {
        $d = json_decode(substr($raw, strlen(self::GUARD)), true);

        return ['n' => is_array($d['n'] ?? null) ? $d['n'] : [], 'f' => is_array($d['f'] ?? null) ? $d['f'] : []];
    }

    private function change(callable $fn)
    {
        $fh = @fopen($this->file, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open the clone request log. Check folder permissions.');
        }
        try {
            flock($fh, LOCK_EX);
            $d = self::decode((string) stream_get_contents($fh));
            $now = $this->now();
            $d['n'] = array_filter($d['n'], static function ($until) use ($now) {
                return (int) $until >= $now;
            });
            $d['f'] = array_filter($d['f'], static function ($f) use ($now) {
                return is_array($f) && (int) $f[1] > $now - self::WINDOW;
            });
            $result = $fn($d);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, self::GUARD . json_encode($d));
            fflush($fh);

            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

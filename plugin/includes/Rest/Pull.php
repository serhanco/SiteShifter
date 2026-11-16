<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Rest;

defined('ABSPATH') || exit;

/**
 * Lets another SiteShifter fetch a package straight from this server, in pieces (HTTP Range),
 * so no file has to pass through a browser. The link carries a long random token that expires
 * quickly; anyone who has the link can read the package, so it is meant to be pasted into the
 * other site and nowhere else.
 */
class Pull
{
    const TTL = 1800;

    /** @param array $job stored job record */
    public static function check(array $job, $token, $now)
    {
        $p = isset($job['pull']) && is_array($job['pull']) ? $job['pull'] : null;
        if (!$p || $job['type'] !== 'export' || $job['status'] !== 'done' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals((string) $p['hash'], hash('sha256', $token)) && $now <= (int) $p['expires'];
    }

    /**
     * @return array{0:int,1:int}|null first and last byte to send; null when the header is unusable
     */
    public static function range($header, $size)
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) $header), $m) || ($m[1] === '' && $m[2] === '') || $size < 1) {
            return null;
        }
        if ($m[1] === '') { // last N bytes
            $n = (int) $m[2];

            return $n < 1 ? null : [max(0, $size - $n), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        return $start > $end || $start >= $size ? null : [$start, $end];
    }

    /** Sends bytes $start..$end of $file in 1 MB pieces; the caller exits afterwards. */
    public static function send($file, $size, $start, $end, $partial)
    {
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        status_header($partial ? 206 : 200);
        header('Content-Type: application/zip');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . ($end - $start + 1));
        if ($partial) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $fh = fopen($file, 'rb');
        if (!$fh) {
            return;
        }
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $piece = fread($fh, min(1048576, $left));
            if ($piece === false || $piece === '') {
                break;
            }
            echo $piece; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file
            $left -= strlen($piece);
            flush();
        }
        fclose($fh);
    }
}

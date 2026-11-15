<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Rest;

defined('ABSPATH') || exit;

/**
 * Package downloads. The work folder is closed to the web, so the file is
 * streamed by PHP, only to the logged-in user it was issued to, with a
 * random token that expires. It is time-limited rather than single-use so a
 * browser can retry or resume a multi-gigabyte download.
 */
class Download
{
    const TTL = 900;

    /** @return string new token */
    public static function newToken()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array  $job    stored job record
     * @param string $token  token from the request
     * @param int    $userId current user
     * @param int    $now
     * @return string '' when allowed, otherwise the reason
     */
    public static function check(array $job, $token, $userId, $now)
    {
        $d = isset($job['download']) && is_array($job['download']) ? $job['download'] : null;
        if (!$d || $job['status'] !== 'done' || $job['type'] !== 'export') {
            return 'No package is ready for this job.';
        }
        if (!is_string($token) || !hash_equals((string) $d['token'], $token)) {
            return 'The download link is not valid.';
        }
        if ((int) $d['user'] !== (int) $userId) {
            return 'This download link belongs to another user.';
        }
        if ($now > (int) $d['expires']) {
            return 'The download link has expired. Create a new one.';
        }

        return '';
    }

    /** A readable file name that reveals nothing but the site host and date. */
    public static function filename($home, $time)
    {
        $host = parse_url((string) $home, PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9.-]+/i', '-', (string) $host);

        return 'siteshifter-' . ($host !== '' ? $host . '-' : '') . gmdate('Ymd-His', $time) . '.zip';
    }

    /** Streams a file in 1 MB pieces with no time limit; the caller exits afterwards. */
    public static function stream($file, $name)
    {
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        $fh = fopen($file, 'rb');
        while ($fh && !feof($fh) && !connection_aborted()) {
            echo fread($fh, 1048576); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file
            flush();
        }
        if ($fh) {
            fclose($fh);
        }
    }
}

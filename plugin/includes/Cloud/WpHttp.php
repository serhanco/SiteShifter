<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Cloud;

use SiteShifter\Transfer\CurlClient;
use SiteShifter\Transfer\HttpClient;

defined('ABSPATH') || exit;

/** The core's HttpClient on the WordPress HTTP API (proxies, CA bundle and filters apply). */
class WpHttp implements HttpClient
{
    public function request($method, $url, array $headers = [], $body = null, $toFile = null)
    {
        if (is_array($body)) {
            $body = CurlClient::slice($body);
        }
        $args = ['method' => $method, 'headers' => $headers, 'timeout' => 120, 'redirection' => 0];
        if ($body !== null) {
            $args['body'] = $body;
        }
        if ($toFile !== null) {
            $args['stream'] = true;
            $args['filename'] = $toFile;
        }
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) {
            return ['code' => 0, 'headers' => [], 'body' => ''];
        }
        $h = [];
        foreach (wp_remote_retrieve_headers($r) as $k => $v) {
            $h[strtolower((string) $k)] = is_array($v) ? (string) end($v) : (string) $v;
        }

        return ['code' => (int) wp_remote_retrieve_response_code($r), 'headers' => $h, 'body' => $toFile !== null ? '' : (string) wp_remote_retrieve_body($r)];
    }
}

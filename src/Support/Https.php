<?php

namespace SiteShifter\Support;

/**
 * HTTPS helpers for sites that sit behind a proxy or CDN.
 *
 * When Cloudflare (or any reverse proxy / load balancer) terminates TLS,
 * the web server sees plain http and WordPress builds http:// links, which
 * breaks images and causes mixed-content warnings. The proxy tells the
 * origin about the visitor's real scheme in a request header; this class
 * reads those headers and produces the small wp-config.php block that
 * makes WordPress trust them.
 */
class Https
{
    public const MARKER_BEGIN = '// BEGIN SiteShifter https';
    public const MARKER_END = '// END SiteShifter https';

    /** Cloudflare IPv4 ranges (https://www.cloudflare.com/ips-v4). */
    private const CLOUDFLARE_V4 = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    /**
     * Is the current request https, directly or as reported by a proxy?
     *
     * @param array $server usually $_SERVER
     */
    public static function isSecureRequest(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (stripos((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') !== false) {
            return true;
        }
        if (in_array(strtolower((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '')), ['on', '1'], true)
            || in_array(strtolower((string) ($server['HTTP_FRONT_END_HTTPS'] ?? '')), ['on', '1'], true)) {
            return true;
        }
        $visitor = (string) ($server['HTTP_CF_VISITOR'] ?? '');

        return $visitor !== '' && stripos($visitor, '"scheme":"https"') !== false;
    }

    /** "Cloudflare" when every address belongs to it, otherwise null. */
    public static function cdnFor(array $ips): ?string
    {
        $ips = array_values(array_filter($ips));
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            $inside = false;
            foreach (self::CLOUDFLARE_V4 as $range) {
                if (self::inRange((string) $ip, $range)) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                return null;
            }
        }

        return 'Cloudflare';
    }

    /** True when the file already makes WordPress trust a proxy's scheme header. */
    public static function hasProxyFix(string $config): bool
    {
        return strpos($config, self::MARKER_BEGIN) !== false
            || stripos($config, 'HTTP_X_FORWARDED_PROTO') !== false
            || stripos($config, 'HTTP_CF_VISITOR') !== false;
    }

    /** The block inserted into wp-config.php. */
    public static function proxySnippet(): string
    {
        return self::MARKER_BEGIN . " (behind a proxy or CDN such as Cloudflare, the visitor's scheme arrives in a header)\n"
            . "if ( empty( \$_SERVER['HTTPS'] ) || 'off' === \$_SERVER['HTTPS'] ) {\n"
            . "\tif ( false !== stripos( (string) ( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ), 'https' )\n"
            . "\t\t|| false !== stripos( (string) ( \$_SERVER['HTTP_CF_VISITOR'] ?? '' ), '\"scheme\":\"https\"' )\n"
            . "\t\t|| in_array( strtolower( (string) ( \$_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' ) ), array( 'on', '1' ), true )\n"
            . "\t\t|| in_array( strtolower( (string) ( \$_SERVER['HTTP_FRONT_END_HTTPS'] ?? '' ) ), array( 'on', '1' ), true ) ) {\n"
            . "\t\t\$_SERVER['HTTPS'] = 'on';\n"
            . "\t}\n"
            . "}\n"
            . self::MARKER_END . "\n";
    }

    /** Inserts the block right after the opening <?php tag, once. */
    public static function addProxyFix(string $config): string
    {
        if (self::hasProxyFix($config) || !preg_match('/<\?php[ \t]*\r?\n?/', $config, $m, PREG_OFFSET_CAPTURE)) {
            return $config;
        }
        $end = $m[0][1] + strlen($m[0][0]);
        $lead = substr($m[0][0], -1) === "\n" ? '' : "\n";

        return substr($config, 0, $end) . $lead . self::proxySnippet() . "\n" . substr($config, $end);
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $mask = $bits == 0 ? 0 : (~((1 << (32 - (int) $bits)) - 1)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($netLong & $mask);
    }
}

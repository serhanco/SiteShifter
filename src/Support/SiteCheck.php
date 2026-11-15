<?php

namespace SiteShifter\Support;

/**
 * Looks at the live address like a visitor would and explains what is wrong,
 * with the exact setting to change. Built for sites behind Cloudflare or any
 * other proxy/CDN, where most "the migration broke it" reports are really
 * an SSL mode, a redirect loop or a cached page.
 *
 * fetch() talks to the network; diagnose() is pure and does the thinking, so
 * it can be tested with canned responses.
 */
class SiteCheck
{
    private const CF_ERRORS = [
        520 => ['Cloudflare error 520', 'The server sent an empty or invalid reply to Cloudflare.', 'Check the PHP error log of the site (a fatal error or a PHP version mismatch often causes it) and that the server does not drop connections from Cloudflare IPs.'],
        521 => ['Cloudflare error 521', 'The web server refused the connection from Cloudflare.', 'Make sure the web server is running and that the firewall (CSF, Imunify, Wordfence, provider firewall) allows Cloudflare IP ranges on ports 80 and 443.'],
        522 => ['Cloudflare error 522', 'Cloudflare could not connect to the server in time.', 'The DNS record may still point to the old server IP: set the A record to this server and keep the proxy on. Also allow Cloudflare IP ranges in the server firewall.'],
        523 => ['Cloudflare error 523', 'Cloudflare cannot reach the server.', 'The A record points to an IP that does not answer. Use this server\'s public IP.'],
        524 => ['Cloudflare error 524', 'The server took too long to answer Cloudflare.', 'A slow page or a heavy plugin; check the PHP timeout and the error log.'],
        525 => ['Cloudflare error 525', 'The SSL handshake between Cloudflare and the server failed.', 'Install a certificate on the server (AutoSSL / Let\'s Encrypt) or set Cloudflare SSL/TLS mode to Full for now; check that port 443 is open.'],
        526 => ['Cloudflare error 526', 'The server certificate is not valid for Cloudflare\'s Full (strict) mode.', 'Issue a valid certificate for the domain on the server (cPanel: run AutoSSL), or temporarily set SSL/TLS mode to Full, then back to Full (strict).'],
    ];

    /**
     * Requests the address, following redirects by hand so loops can be seen.
     *
     * @return array{status:int,headers:array<string,string>,body:string,error:?string,chain:string[],final_url:string,tls_error:?string}
     */
    public static function fetch(string $url, int $timeout = 8, int $maxHops = 8): array
    {
        $chain = [];
        $tlsError = null;
        $current = $url;
        $res = ['status' => 0, 'headers' => [], 'body' => '', 'error' => null];
        for ($i = 0; $i <= $maxHops; $i++) {
            $chain[] = $current;
            $res = self::request($current, $timeout, true);
            if ($res['status'] === 0 && $res['tls']) {
                $tlsError = $res['error'];
                $res = self::request($current, $timeout, false); // look at it anyway
            }
            if ($res['status'] === 0 || $res['status'] < 300 || $res['status'] >= 400 || empty($res['headers']['location'])) {
                break;
            }
            $next = self::absolute($current, $res['headers']['location']);
            if (in_array($next, $chain, true)) {
                $chain[] = $next;
                $res['error'] = 'redirect loop';
                break;
            }
            $current = $next;
            if ($i === $maxHops) {
                $res['error'] = 'too many redirects';
            }
        }

        return ['status' => $res['status'], 'headers' => $res['headers'], 'body' => $res['body'], 'error' => $res['error'], 'chain' => $chain, 'final_url' => $current, 'tls_error' => $tlsError];
    }

    /**
     * Explains a response. $resp is what fetch() returns, $httpStatus the
     * status of a plain-http request to the same host (0 = not tried).
     *
     * @return array<int,array{id:string,level:string,title:string,message:string,fix:string}>
     */
    public static function diagnose(array $resp, string $url, ?array $httpResp = null): array
    {
        $notes = [];
        $add = static function (string $id, string $level, string $title, string $message, string $fix = '') use (&$notes): void {
            $notes[] = ['id' => $id, 'level' => $level, 'title' => $title, 'message' => $message, 'fix' => $fix];
        };
        $h = $resp['headers'] ?? [];
        $cf = isset($h['cf-ray']) || stripos($h['server'] ?? '', 'cloudflare') !== false;
        $status = (int) ($resp['status'] ?? 0);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $https = stripos($url, 'https://') === 0;

        if (($resp['error'] ?? null) === 'redirect loop' || ($resp['error'] ?? null) === 'too many redirects') {
            if ($cf && $https) {
                $add('loop', 'block', 'Redirect loop behind Cloudflare', 'The address redirects to itself. This is the classic result of Cloudflare SSL/TLS mode "Flexible" while the site forces https.', 'Cloudflare → SSL/TLS → Overview → set the mode to Full (or Full (strict) once the server has a valid certificate). Then clear the browser cookies for the site.');
            } else {
                $add('loop', 'block', 'Redirect loop', 'The address redirects to itself (' . implode(' → ', array_slice($resp['chain'], -3)) . ').', 'Behind a proxy or load balancer WordPress must see that the visitor used https: SiteShifter\'s wp-config.php block does this; also check .htaccess / server rules that force https or www, and that Site URL and Home URL in Settings → General match the address.');
            }

            return $notes;
        }
        if ($status === 0) {
            if (!empty($resp['tls_error'])) {
                $add('tls', 'block', 'Certificate problem', 'The https connection failed: ' . $resp['tls_error'], $cf ? 'Issue a valid certificate on the server (cPanel: AutoSSL), or set Cloudflare SSL/TLS to Full until it exists.' : 'Install a valid certificate for ' . $host . ' (AutoSSL / Let\'s Encrypt) and check that port 443 is open.');
            } else {
                $add('unreachable', 'block', 'Site not reachable', 'No answer from ' . $url . ($resp['error'] ? ' (' . $resp['error'] . ')' : '') . '.', 'The DNS record probably still points to the old server, or the firewall blocks the request. Point the A record to this server and wait for DNS to update.');
            }

            return $notes;
        }
        if (isset(self::CF_ERRORS[$status])) {
            [$title, $message, $fix] = self::CF_ERRORS[$status];
            $add('cf_' . $status, 'block', $title, $message, $fix);

            return $notes;
        }
        if (!empty($resp['tls_error'])) {
            $add('tls', 'warn', 'Certificate problem', 'The site answers but its certificate is not trusted: ' . $resp['tls_error'], 'Issue a valid certificate on the server (AutoSSL / Let\'s Encrypt).');
        }
        if ($status === 403 && $cf && stripos($resp['body'] ?? '', 'cloudflare') !== false) {
            $add('cf_403', 'warn', 'Blocked by a Cloudflare rule', 'Cloudflare answered 403 (WAF, Bot Fight Mode or a firewall rule).', 'Cloudflare → Security → Events shows which rule fired; allow your own IP or relax Bot Fight Mode while testing.');
        } elseif ($status >= 500) {
            $add('http_' . $status, 'block', 'The site returns error ' . $status, 'The server answered but WordPress failed.', 'Read the PHP error log (cPanel → Errors, or wp-content/debug.log with WP_DEBUG_LOG). A PHP version difference with a theme or plugin is the usual cause.');
        } elseif ($status === 404 && ($resp['final_url'] ?? $url) !== $url) {
            $add('http_404', 'warn', 'Page not found', 'The address redirects to a page that does not exist.', 'Settings → Permalinks → Save, and check that .htaccess exists and mod_rewrite is on.');
        }

        // The final address should be the https one asked for.
        if ($https && stripos((string) ($resp['final_url'] ?? ''), 'http://') === 0) {
            $add('downgrade', 'warn', 'https redirects back to http', 'Visitors who open ' . $url . ' end up on ' . $resp['final_url'] . '.', 'Settings → General: Site URL and Home URL must start with https://. Behind a proxy check the wp-config.php block of SiteShifter.');
        }
        $finalHost = (string) parse_url((string) ($resp['final_url'] ?? $url), PHP_URL_HOST);
        if ($finalHost !== '' && $finalHost !== $host) {
            $add('host_changed', 'info', 'Redirects to another spelling', $host . ' redirects to ' . $finalHost . '.', 'Fine if this is the www / non-www choice you want; make it the same in Settings → General and in Cloudflare (Redirect Rules).');
        }

        // Plain http should lead to https.
        if ($https && $httpResp !== null && ($httpResp['status'] ?? 0) > 0) {
            $to = (string) ($httpResp['final_url'] ?? '');
            if (stripos($to, 'https://') !== 0) {
                $add('http_open', 'warn', 'http is still served', 'http://' . $host . ' does not redirect to https.', $cf ? 'Cloudflare → SSL/TLS → Edge Certificates → turn on "Always Use HTTPS".' : 'Add an http→https 301 redirect on the server (cPanel: Domains → "Force HTTPS Redirect").');
            }
        }

        // Mixed content: own-host links that still say http://
        $body = (string) ($resp['body'] ?? '');
        $bare = preg_quote((string) preg_replace('#^www\.#', '', $host), '#');
        if ($https && $body !== '' && preg_match_all('#(?:src|href|action)=["\']http://(?:www\.)?' . $bare . '#i', $body, $m)) {
            $add('mixed', 'warn', 'Mixed content (http links on an https page)', count($m[0]) . ' link(s) on the page still start with http://' . $host . '.', 'Run "siteshifter replace" or the installer again with the https address; clear the cache plugin and Cloudflare cache (Caching → Purge Everything). As a safety net turn on Cloudflare → SSL/TLS → Edge Certificates → "Automatic HTTPS Rewrites".');
        }

        if ($cf && $status > 0 && $status < 400) {
            $cache = strtolower($h['cf-cache-status'] ?? '');
            if ($cache === 'hit') {
                $add('cf_cache', 'info', 'Cloudflare is serving a cached copy', 'This page came from Cloudflare\'s cache, so it may still show the old site.', 'Cloudflare → Caching → Configuration → Purge Everything, after the DNS record points to this server.');
            }
            $add('cf_settings', 'info', 'Cloudflare settings to check', 'The site is served through Cloudflare.', 'SSL/TLS mode Full (strict) once the server has a valid certificate (Full until then, never Flexible); Always Use HTTPS on; Automatic HTTPS Rewrites on; no cache rule on /wp-admin/ and /wp-login.php; if login or the editor misbehaves, turn off Rocket Loader and Bot Fight Mode for those paths.');
        } elseif (!$cf && (isset($h['via']) || isset($h['x-forwarded-for']) || isset($h['x-sucuri-id']) || isset($h['x-cache']) || stripos($h['server'] ?? '', 'cloudfront') !== false)) {
            $add('cdn_other', 'info', 'A proxy or CDN is in front of the site', 'Another proxy/CDN answered (' . ($h['via'] ?? $h['server'] ?? 'unknown') . ').', 'Make sure it passes the original scheme to the server (X-Forwarded-Proto: https), uses https to reach this server, and purge its cache after the move.');
        }

        return $notes;
    }

    /** Fetches https (and plain http) of the address and diagnoses both. */
    public static function check(string $url): array
    {
        $resp = self::fetch($url);
        $http = null;
        if (stripos($url, 'https://') === 0) {
            $http = self::fetch('http://' . substr($url, 8), 6);
        }

        return self::diagnose($resp, $url, $http);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,error:?string,tls:bool} */
    private static function request(string $url, int $timeout, bool $verify): array
    {
        $out = ['status' => 0, 'headers' => [], 'body' => '', 'error' => null, 'tls' => false];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_USERAGENT => 'SiteShifter site check', CURLOPT_ENCODING => '',
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($raw === false) {
                $out['error'] = curl_error($ch);
                $out['tls'] = in_array($errno, [35, 51, 58, 59, 60, 77, 83], true);
            } else {
                $out['headers'] = self::parseHeaders(substr((string) $raw, 0, $size));
                $out['body'] = substr((string) $raw, $size, 400000);
            }
            curl_close($ch);

            return $out;
        }
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'SiteShifter site check'], 'ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify]]);
        $body = @file_get_contents($url, false, $ctx, 0, 400000);
        $lines = $http_response_header ?? [];
        if ($body === false && $lines === []) {
            $out['error'] = 'connection failed';
            $out['tls'] = $verify && stripos($url, 'https://') === 0;

            return $out;
        }
        if (isset($lines[0]) && preg_match('#\s(\d{3})\s#', $lines[0], $m)) {
            $out['status'] = (int) $m[1];
        }
        $out['headers'] = self::parseHeaders(implode("\r\n", $lines));
        $out['body'] = (string) $body;

        return $out;
    }

    /** @return array<string,string> lower-case names, last response block wins */
    private static function parseHeaders(string $raw): array
    {
        $blocks = preg_split('/\r?\n\r?\n/', trim($raw));
        $last = $blocks ? end($blocks) : '';
        $headers = [];
        foreach (preg_split('/\r?\n/', (string) $last) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return $headers;
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strpos($location, '//') === 0) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }

        return $origin . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $location;
    }
}

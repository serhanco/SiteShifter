<?php

namespace SiteShifter\Transfer;

/**
 * A package behind a SiteShifter pull link (made on the other site's Export
 * screen). The piece is asked for in the address (&range=a-b) because some
 * hosts and caches drop the Range header. A site from 0.3 on also answers
 * &pieces=1 with its checksum list; an older one ignores it and sends one byte.
 */
class LinkSource implements Source
{
    /** @var string */
    private $url;

    /** @var callable function (string $url, array $headers, string $toFile): array{code:int,headers:array} headers lower-cased */
    private $http;

    /** @var string */
    private $tmp;

    public function __construct($url, $http, $tmp)
    {
        $this->url = self::validate($url);
        $this->http = $http;
        $this->tmp = (string) $tmp;
    }

    /** Only the link format SiteShifter itself makes is accepted. */
    public static function validate($url)
    {
        $p = parse_url((string) $url);
        if (!$p || empty($p['host']) || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Paste the full link made on the other site (it starts with https://).');
        }
        parse_str((string) ($p['query'] ?? ''), $q);
        if (($q['action'] ?? '') !== 'siteshifter_pull' || !preg_match('/^[a-f0-9]{32}$/', (string) ($q['job'] ?? '')) || !preg_match('/^[a-f0-9]{48}$/', (string) ($q['token'] ?? ''))) {
            throw new \InvalidArgumentException('That is not a SiteShifter package link. Create it with "Create a link for another site" on the other site.');
        }

        return (string) $url;
    }

    public function stat()
    {
        $r = call_user_func($this->http, $this->url . '&range=0-0&pieces=1', [], $this->tmp);
        $body = is_file($this->tmp) ? (string) file_get_contents($this->tmp, false, null, 0, 1048576) : '';
        $len = is_file($this->tmp) ? (int) filesize($this->tmp) : -1;
        @unlink($this->tmp);
        if (!in_array($r['code'], [200, 206], true)) {
            throw new TransferError(self::explain($r['code']), TransferError::transientStatus($r['code']), (int) $r['code']);
        }
        $host = (string) parse_url($this->url, PHP_URL_HOST);
        $name = 'siteshifter-from-' . preg_replace('/[^a-z0-9.-]+/i', '-', $host) . '.zip';
        if (!empty($r['headers']['x-siteshifter-pieces'])) {
            $pieces = Pieces::parse($body);
            if ($pieces === null) {
                throw new TransferError('The other site sent an unreadable checksum list. Update SiteShifter there and try again.');
            }

            return ['name' => $name, 'size' => $pieces['size'], 'pieces' => $pieces];
        }
        $range = $r['headers']['x-siteshifter-range'] ?? ($r['headers']['content-range'] ?? '');
        // A host that ignores the range would send the whole file.
        if ($len !== 1 || !preg_match('#/(\d+)$#', (string) $range, $m) || (int) $m[1] < 1) {
            throw new TransferError(self::explain($r['code']));
        }

        return ['name' => $name, 'size' => (int) $m[1], 'pieces' => null];
    }

    public function fetch($from, $to, $toFile)
    {
        $r = call_user_func($this->http, $this->url . '&range=' . (int) $from . '-' . (int) $to, [], $toFile);
        if (!in_array($r['code'], [200, 206], true)) {
            @unlink($toFile);
            throw new TransferError(self::explain($r['code']), TransferError::transientStatus($r['code']), (int) $r['code']);
        }
    }

    public static function explain($code)
    {
        if ($code === 403) {
            return 'The other site refused the link. It may have expired (links last 30 minutes) or the package was deleted. Create a new link there.';
        }
        if ($code === 0) {
            return 'This server could not reach the other site. Check the address and that this host may make outgoing requests.';
        }

        return 'The other site answered with an unexpected reply (' . $code . '). A firewall or cache may be in the way; use the download and upload route instead.';
    }
}

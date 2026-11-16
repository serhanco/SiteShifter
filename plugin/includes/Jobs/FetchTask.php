<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * Fetches a package from another SiteShifter site (the link made on its Export screen)
 * into the incoming folder, in pieces with HTTP Range requests, so a slow host or a time
 * limit only costs the piece in flight. The result is a normal uploaded package.
 */
class FetchTask implements Task
{
    const PIECE = 8388608;

    /** @var string */
    private $dir;

    /** @var Incoming */
    private $incoming;

    /** @var callable function (string $url, array $headers, string $toFile): array{code:int,headers:array}  headers lower-cased */
    private $http;

    public function __construct($dir, Incoming $incoming, $http)
    {
        $this->dir = rtrim($dir, '/');
        $this->incoming = $incoming;
        $this->http = $http;
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

    public function initialState(array $params)
    {
        return ['url' => self::validate($params['url'] ?? ''), 'total' => null, 'id' => null, 'offset' => 0, 'done' => false, 'result' => null];
    }

    public function run(array $state, $deadline)
    {
        $first = true;
        if ($state['total'] === null) {
            $state['total'] = $this->size($state['url']);
            $host = (string) parse_url($state['url'], PHP_URL_HOST);
            $state['id'] = $this->incoming->begin('siteshifter-from-' . preg_replace('/[^a-z0-9.-]+/i', '-', $host) . '.zip', $state['total']);
        }
        while ($state['offset'] < $state['total'] && ($first || microtime(true) < $deadline)) {
            $first = false;
            $end = min($state['offset'] + static::PIECE, $state['total']) - 1;
            $tmp = $this->dir . '/piece.tmp';
            $r = call_user_func($this->http, self::piece($state['url'], $state['offset'], $end), [], $tmp);
            if (!in_array($r['code'], [200, 206], true) || !is_file($tmp) || filesize($tmp) !== $end - $state['offset'] + 1) {
                @unlink($tmp);
                throw new \RuntimeException(self::explain($r['code']));
            }
            $done = $this->incoming->append($state['id'], $state['offset'], $tmp);
            @unlink($tmp);
            $state['offset'] = $done['size']; // if a piece was repeated, carry on from what the file really holds
        }
        if ($state['offset'] >= $state['total']) {
            $state['result'] = $this->incoming->finish($state['id']);
            $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => $state['result']];
        }
        $total = (int) $state['total'];

        return ['percent' => $total > 0 ? (int) min(99, floor(100 * $state['offset'] / $total)) : 0, 'phase' => 'fetch', 'detail' => $total > 0 ? round($state['offset'] / 1048576) . ' / ' . round($total / 1048576) . ' MB' : ''];
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }

    private static function piece($url, $from, $to)
    {
        return $url . '&range=' . $from . '-' . $to;
    }

    private function size($url)
    {
        $tmp = $this->dir . '/probe.tmp';
        $r = call_user_func($this->http, self::piece($url, 0, 0), [], $tmp);
        $ok = is_file($tmp) && filesize($tmp) === 1; // a host that ignores the range would send the whole file
        @unlink($tmp);
        $range = $r['headers']['x-siteshifter-range'] ?? ($r['headers']['content-range'] ?? '');
        if (!in_array($r['code'], [200, 206], true) || !$ok || !preg_match('#/(\d+)$#', (string) $range, $m) || (int) $m[1] < 1) {
            throw new \RuntimeException(self::explain($r['code']));
        }

        return (int) $m[1];
    }

    private static function explain($code)
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

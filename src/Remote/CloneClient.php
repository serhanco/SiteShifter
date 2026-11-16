<?php

namespace SiteShifter\Remote;

use SiteShifter\Transfer\HttpClient;
use SiteShifter\Transfer\TransferError;

/**
 * The target's side of a clone: signed calls to the source site's clone
 * endpoint (admin-ajax.php?action=siteshifter_clone&op=...). The source only
 * ever answers reads: who it is, build a package, send its pieces, clean up.
 */
class CloneClient
{
    const ACTION = 'siteshifter_clone';
    const PROTOCOL = 1;

    /** @var HttpClient */
    private $http;

    /** @var array{id:string,secret:string,url:string,expires:int} */
    private $key;

    public function __construct(HttpClient $http, array $key)
    {
        $this->http = $http;
        $this->key = $key;
    }

    public function url($op, array $query = [])
    {
        return $this->key['url'] . '/wp-admin/admin-ajax.php?' . http_build_query(['action' => self::ACTION, 'op' => $op] + $query);
    }

    /** @return array decoded JSON answer (ok:true) */
    public function call($op, array $data = [])
    {
        $body = json_encode($data);
        $r = $this->http->request('POST', $this->url($op), Signer::sign($this->key['id'], $this->key['secret'], $op, $body) + ['Content-Type' => 'application/json'], $body);
        $j = json_decode((string) $r['body'], true);
        if (is_array($j) && isset($j['ok'])) {
            if ($j['ok']) {
                return $j;
            }
            throw new TransferError('The source site said: ' . (string) ($j['message'] ?? 'refused'), !empty($j['retry']), (int) $r['code']);
        }
        throw new TransferError(self::explain($r['code']), TransferError::transientStatus($r['code']), (int) $r['code']);
    }

    /** Downloads bytes $from..$to of the source package (job $job) into $toFile. */
    public function piece($job, $from, $to, $toFile)
    {
        $op = 'piece';
        $q = ['job' => $job, 'range' => $from . '-' . $to];
        $r = $this->http->request('GET', $this->url($op, $q), Signer::sign($this->key['id'], $this->key['secret'], $op, $job . '|' . $q['range']), null, $toFile);
        if ($r['code'] !== 200 && $r['code'] !== 206) {
            @unlink($toFile);
            throw new TransferError(self::explain($r['code']), TransferError::transientStatus($r['code']), (int) $r['code']);
        }
    }

    public static function explain($code)
    {
        if ($code === 0) {
            return 'This server could not reach the source site. Check its address and that this host may make outgoing requests.';
        }
        if ($code === 403) {
            return 'The source site refused the clone key. It may have expired or been revoked: create a new one on the source site.';
        }
        if ($code === 404 || $code === 400) {
            return 'The source site does not answer clone requests. Install or update SiteShifter there (0.3 or newer).';
        }

        return 'The source site answered with an unexpected reply (' . $code . '). A firewall, Cloudflare bot protection or a password-protected folder may be in the way; allow /wp-admin/admin-ajax.php for SiteShifter, or move the site through Google Drive instead.';
    }
}

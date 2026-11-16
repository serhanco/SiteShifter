<?php

namespace SiteShifter\Transfer\Google;

use SiteShifter\Transfer\HttpClient;
use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\Storage;
use SiteShifter\Transfer\TransferError;

/**
 * Packages in the person's own Google Drive, in a "SiteShifter" folder the
 * plugin creates (with the drive.file permission it sees nothing else).
 * Each package gets its checksum list as a small file beside it, linked from
 * the package's appProperties. Uploads use Drive's resumable sessions, so a
 * piece that failed half way is sent again from what Drive really holds.
 */
class Drive implements Storage
{
    const API = 'https://www.googleapis.com/drive/v3';
    const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    const FOLDER = 'SiteShifter';
    const FOLDER_TYPE = 'application/vnd.google-apps.folder';

    /** @var HttpClient */
    private $http;

    /** @var DeviceAuth */
    private $auth;

    /** @var array */
    private $tokens;

    /** @var callable function (array $tokens): void, keeps renewed tokens */
    private $save;

    /** @var string|null */
    private $folder;

    /** @var callable */
    private $clock;

    public function __construct(HttpClient $http, DeviceAuth $auth, array $tokens, $save, $folderId = null, $clock = null)
    {
        $this->http = $http;
        $this->auth = $auth;
        $this->tokens = $tokens;
        $this->save = $save;
        $this->folder = $folderId;
        $this->clock = $clock ?: 'time';
    }

    public function label()
    {
        return 'Google Drive';
    }

    /** The account the connection belongs to, to show on screen. */
    public function account()
    {
        return (string) ($this->tokens['email'] ?? '');
    }

    /** @return string id of the SiteShifter folder, created on first use */
    public function folder()
    {
        if ($this->folder !== null) {
            return $this->folder;
        }
        $q = "name = '" . self::FOLDER . "' and mimeType = '" . self::FOLDER_TYPE . "' and trashed = false";
        $j = $this->json('GET', self::API . '/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'spaces' => 'drive', 'pageSize' => 1]));
        if (empty($j['files'][0]['id'])) {
            $j = ['files' => [$this->json('POST', self::API . '/files?fields=id', ['name' => self::FOLDER, 'mimeType' => self::FOLDER_TYPE])]];
        }
        $this->folder = (string) $j['files'][0]['id'];
        // Remembered with the connection, so later requests skip the search.
        $this->tokens['folder'] = $this->folder;
        call_user_func($this->save, $this->tokens);

        return $this->folder;
    }

    public function items()
    {
        $q = "'" . $this->folder() . "' in parents and trashed = false and mimeType = 'application/zip'";
        $out = [];
        $page = null;
        do {
            $args = ['q' => $q, 'fields' => 'nextPageToken,files(id,name,size,createdTime,appProperties)', 'pageSize' => 100, 'orderBy' => 'createdTime desc'];
            if ($page) {
                $args['pageToken'] = $page;
            }
            $j = $this->json('GET', self::API . '/files?' . http_build_query($args));
            foreach ($j['files'] ?? [] as $f) {
                $out[] = self::item($f);
            }
            $page = $j['nextPageToken'] ?? null;
        } while ($page && count($out) < 1000);

        return $out;
    }

    public function open($id)
    {
        $f = $this->json('GET', self::API . '/files/' . rawurlencode($id) . '?fields=id,name,size,trashed,appProperties');
        if (!empty($f['trashed'])) {
            throw new TransferError('That package is in the Google Drive trash.');
        }
        $pieces = null;
        if (!empty($f['appProperties']['ss_pieces'])) {
            $r = $this->call('GET', self::API . '/files/' . rawurlencode($f['appProperties']['ss_pieces']) . '?alt=media');
            $pieces = $r['code'] === 200 ? Pieces::parse($r['body']) : null;
        }

        return new DriveSource($this, (string) $f['id'], (string) $f['name'], (int) ($f['size'] ?? 0), $pieces);
    }

    public function space()
    {
        $j = $this->json('GET', self::API . '/about?fields=storageQuota');
        $q = $j['storageQuota'] ?? [];
        if (!isset($q['limit'])) {
            return null; // unlimited (some Workspace plans)
        }

        return max(0, (int) $q['limit'] - (int) ($q['usage'] ?? 0));
    }

    public function beginUpload($name, $size)
    {
        $meta = ['name' => self::cleanName($name), 'parents' => [$this->folder()], 'mimeType' => 'application/zip'];
        $r = $this->call('POST', self::UPLOAD . '?uploadType=resumable&fields=id', [
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => 'application/zip',
            'X-Upload-Content-Length' => (string) (int) $size,
        ], json_encode($meta));
        $uri = $r['headers']['location'] ?? '';
        if ($r['code'] !== 200 || $uri === '') {
            throw self::error($r, 'Google Drive did not start the upload');
        }

        return ['uri' => $uri, 'name' => $meta['name'], 'size' => (int) $size, 'file_id' => null];
    }

    public function uploadPiece(array $session, $offset, $file, $length)
    {
        $last = $offset + $length - 1;
        $r = $this->http->request('PUT', $session['uri'], [
            'Content-Length' => (string) $length,
            'Content-Range' => 'bytes ' . $offset . '-' . $last . '/' . $session['size'],
        ], ['file' => $file, 'offset' => $offset, 'length' => $length]);

        return $this->uploadAnswer($session, $r);
    }

    public function confirmed(array $session)
    {
        if (!empty($session['file_id'])) {
            return (int) $session['size'];
        }
        $r = $this->http->request('PUT', $session['uri'], ['Content-Length' => '0', 'Content-Range' => 'bytes */' . $session['size']], '');

        return $this->uploadAnswer($session, $r)['offset'];
    }

    public function finishUpload(array $session, array $pieces)
    {
        if (empty($session['file_id'])) {
            throw new TransferError('Google Drive has not confirmed the whole package. Start the upload again.');
        }
        $f = $this->json('GET', self::API . '/files/' . rawurlencode($session['file_id']) . '?fields=id,size');
        if ((int) ($f['size'] ?? -1) !== (int) $session['size']) {
            throw new TransferError('The copy in Google Drive has the wrong size. Delete it and upload again.');
        }
        // The checksum list rides beside the package as a small file (appProperties hold 124 bytes at most).
        $boundary = 'ss' . bin2hex(random_bytes(8));
        $body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode(['name' => $session['name'] . '.pieces.json', 'parents' => [$this->folder()], 'mimeType' => 'application/json'])
            . "\r\n--" . $boundary . "\r\nContent-Type: application/json\r\n\r\n" . json_encode($pieces) . "\r\n--" . $boundary . '--';
        $r = $this->call('POST', self::UPLOAD . '?uploadType=multipart&fields=id', ['Content-Type' => 'multipart/related; boundary=' . $boundary], $body);
        $side = json_decode($r['body'], true);
        if ($r['code'] !== 200 || empty($side['id'])) {
            throw self::error($r, 'Google Drive did not save the checksum list');
        }
        $f = $this->json('PATCH', self::API . '/files/' . rawurlencode($session['file_id']) . '?fields=id,name,size,createdTime,appProperties', [
            'appProperties' => ['ss_digest' => $pieces['digest'], 'ss_pieces' => $side['id']],
        ]);

        return self::item($f);
    }

    public function delete($id)
    {
        $f = $this->json('GET', self::API . '/files/' . rawurlencode($id) . '?fields=appProperties');
        foreach ([$f['appProperties']['ss_pieces'] ?? null, $id] as $one) {
            if ($one) {
                $r = $this->call('DELETE', self::API . '/files/' . rawurlencode($one));
                if ($r['code'] !== 204 && $r['code'] !== 404) {
                    throw self::error($r, 'Google Drive did not delete the package');
                }
            }
        }
    }

    /** Reads part of a file (for DriveSource). */
    public function fetchRange($id, $from, $to, $toFile)
    {
        $r = $this->call('GET', self::API . '/files/' . rawurlencode($id) . '?alt=media', ['Range' => 'bytes=' . $from . '-' . $to], null, $toFile);
        if ($r['code'] !== 206 && !($r['code'] === 200 && $from === 0)) {
            @unlink($toFile);
            throw self::error($r, 'Google Drive did not send the piece');
        }
    }

    private function uploadAnswer(array $session, array $r)
    {
        if ($r['code'] === 200 || $r['code'] === 201) {
            $j = json_decode($r['body'], true);
            $session['file_id'] = is_array($j) ? ($j['id'] ?? null) : null;
            if (!$session['file_id']) {
                throw new TransferError('Google Drive finished the upload but did not name the file.', true);
            }

            return ['session' => $session, 'offset' => (int) $session['size']];
        }
        if ($r['code'] === 308) {
            $range = $r['headers']['range'] ?? '';

            return ['session' => $session, 'offset' => preg_match('/bytes=0-(\d+)/', $range, $m) ? (int) $m[1] + 1 : 0];
        }
        if ($r['code'] === 404 || $r['code'] === 410) {
            throw new TransferError('The Google Drive upload session expired (they last a week). Start the upload again.');
        }
        throw self::error($r, 'Google Drive refused a piece');
    }

    /** One authorised call; renews the access token once when Google says it expired. */
    private function call($method, $url, array $headers = [], $body = null, $toFile = null)
    {
        for ($try = 0; $try < 2; $try++) {
            if ($try > 0 || (int) ($this->tokens['expires_at'] ?? 0) <= (int) call_user_func($this->clock)) {
                $this->tokens = $this->auth->refresh($this->tokens, (int) call_user_func($this->clock));
                call_user_func($this->save, $this->tokens);
            }
            $r = $this->http->request($method, $url, ['Authorization' => 'Bearer ' . $this->tokens['access_token']] + $headers, $body, $toFile);
            if ($r['code'] !== 401) {
                return $r;
            }
        }

        return $r;
    }

    private function json($method, $url, $data = null)
    {
        $r = $this->call($method, $url, $data === null ? [] : ['Content-Type' => 'application/json; charset=UTF-8'], $data === null ? null : json_encode($data));
        $j = json_decode($r['body'], true);
        if ($r['code'] < 200 || $r['code'] > 299 || !is_array($j)) {
            throw self::error($r, 'Google Drive refused the request');
        }

        return $j;
    }

    private static function item(array $f)
    {
        return [
            'id' => (string) $f['id'],
            'name' => (string) ($f['name'] ?? ''),
            'size' => (int) ($f['size'] ?? 0),
            'created' => isset($f['createdTime']) ? (int) strtotime($f['createdTime']) : 0,
            'digest' => $f['appProperties']['ss_digest'] ?? null,
        ];
    }

    public static function error(array $r, $what)
    {
        $code = (int) $r['code'];
        if ($code === 0) {
            return TransferError::transient($what . ': this server could not reach Google Drive.', 0);
        }
        $j = json_decode((string) $r['body'], true);
        $reason = is_array($j) ? (string) ($j['error']['errors'][0]['reason'] ?? '') : '';
        $message = is_array($j) ? trim((string) ($j['error']['message'] ?? '')) : '';
        if ($reason === 'storageQuotaExceeded') {
            return new TransferError('Your Google Drive is full. Free some space or delete old packages, then try again.', false, $code);
        }
        if ($code === 401) {
            return new TransferError('The Google Drive connection is no longer valid. Connect Google Drive again.', false, $code);
        }
        // Drive answers a rate limit with 403 + rateLimitExceeded/userRateLimitExceeded.
        $transient = TransferError::transientStatus($code) || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true);

        return new TransferError($what . ' (' . $code . ($message !== '' ? ': ' . $message : '') . ').', $transient, $code);
    }

    public static function cleanName($name)
    {
        $name = trim(preg_replace('/[^\w.@ -]+/u', '-', basename((string) $name)), '.- ');

        return ($name === '' ? 'package' : $name) . (substr($name, -4) === '.zip' ? '' : '.zip');
    }
}

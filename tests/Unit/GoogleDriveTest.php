<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Transfer\Download;
use SiteShifter\Transfer\Google\DeviceAuth;
use SiteShifter\Transfer\Google\Drive;
use SiteShifter\Transfer\HttpClient;
use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\Sink;
use SiteShifter\Transfer\TransferError;
use SiteShifter\Transfer\Upload;

/** A small in-memory Google: OAuth device flow + the Drive v3 calls SiteShifter makes. */
class FakeGoogle implements HttpClient
{
    public $files = [];
    public $sessions = [];
    public $pending = 2; // polls before the person approves
    public $access = 'at1';
    public $quota = ['limit' => '100000000000', 'usage' => '0'];
    public $dropNextPut = false;
    public $calls = [];
    private $next = 1;

    public function request($method, $url, array $headers = [], $body = null, $toFile = null)
    {
        $this->calls[] = $method . ' ' . $url;
        if (is_array($body)) {
            $body = \SiteShifter\Transfer\CurlClient::slice($body);
        }
        $r = $this->route($method, $url, $headers, (string) $body);
        $r += ['headers' => [], 'body' => ''];
        if ($toFile !== null) {
            file_put_contents($toFile, $r['body']);
            $r['body'] = '';
        }

        return $r;
    }

    private function json($code, $data, array $headers = [])
    {
        return ['code' => $code, 'headers' => $headers, 'body' => json_encode($data)];
    }

    private function route($method, $url, array $h, $body)
    {
        $u = parse_url($url);
        parse_str($u['query'] ?? '', $q);
        if ($url === DeviceAuth::CODE_URL) {
            return $this->json(200, ['device_code' => 'dev', 'user_code' => 'ABCD-EFGH', 'verification_url' => 'https://www.google.com/device', 'expires_in' => 1800, 'interval' => 5]);
        }
        if ($url === DeviceAuth::TOKEN_URL) {
            parse_str($body, $f);
            if ($f['grant_type'] === 'refresh_token') {
                $this->access = 'at' . (++$this->next);

                return $this->json(200, ['access_token' => $this->access, 'expires_in' => 3600]);
            }
            if ($this->pending-- > 0) {
                return $this->json(428, ['error' => 'authorization_pending']);
            }
            $idt = 'x.' . rtrim(strtr(base64_encode(json_encode(['email' => 'serhan@example.com'])), '+/', '-_'), '=') . '.y';

            return $this->json(200, ['access_token' => $this->access, 'refresh_token' => 'rt', 'expires_in' => 3600, 'id_token' => $idt]);
        }
        if (($h['Authorization'] ?? '') !== 'Bearer ' . $this->access && strpos($url, '/upload/drive/v3/files?upload_id=') === false) {
            return $this->json(401, ['error' => ['message' => 'Invalid Credentials']]);
        }
        $path = $u['path'];
        if ($path === '/drive/v3/about') {
            return $this->json(200, ['storageQuota' => $this->quota]);
        }
        if ($path === '/drive/v3/files' && $method === 'GET') {
            $out = [];
            foreach ($this->files as $id => $f) {
                if (!empty($f['trashed'])) {
                    continue;
                }
                $isFolder = $f['mimeType'] === Drive::FOLDER_TYPE;
                if (strpos($q['q'], 'vnd.google-apps.folder') !== false ? $isFolder : (!$isFolder && $f['mimeType'] === 'application/zip')) {
                    $out[] = ['id' => $id, 'name' => $f['name'], 'size' => (string) strlen($f['data']), 'createdTime' => '2026-11-16T10:00:00Z', 'appProperties' => $f['appProperties']];
                }
            }

            return $this->json(200, ['files' => $out]);
        }
        if ($path === '/drive/v3/files' && $method === 'POST') {
            $m = json_decode($body, true);

            return $this->json(200, ['id' => $this->add($m, '')]);
        }
        if ($path === '/upload/drive/v3/files' && ($q['uploadType'] ?? '') === 'resumable') {
            $sid = 's' . count($this->sessions);
            $this->sessions[$sid] = ['meta' => json_decode($body, true), 'size' => (int) $h['X-Upload-Content-Length'], 'data' => ''];

            return ['code' => 200, 'headers' => ['location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=' . $sid]];
        }
        if ($path === '/upload/drive/v3/files' && ($q['uploadType'] ?? '') === 'multipart') {
            preg_match('/boundary=(\S+)/', $h['Content-Type'], $b);
            $parts = explode('--' . $b[1], $body);
            $meta = json_decode(trim(explode("\r\n\r\n", $parts[1], 2)[1]), true);
            $data = trim(explode("\r\n\r\n", $parts[2], 2)[1]);

            return $this->json(200, ['id' => $this->add($meta, $data)]);
        }
        if (isset($q['upload_id']) && $method === 'PUT') {
            $s = &$this->sessions[$q['upload_id']];
            preg_match('#bytes (\*|(\d+)-(\d+))/(\d+)#', $h['Content-Range'], $m);
            if ($m[1] !== '*') {
                if ((int) $m[2] !== strlen($s['data'])) {
                    return $this->json(400, ['error' => ['message' => 'range mismatch']]);
                }
                if ($this->dropNextPut) {
                    $this->dropNextPut = false;
                    $s['data'] .= substr($body, 0, 262144); // Drive kept a quarter MB, then the line dropped

                    return ['code' => 0, 'headers' => []];
                }
                $s['data'] .= $body;
            }
            if (strlen($s['data']) >= $s['size']) {
                $id = $s['id'] ?? ($s['id'] = $this->add($s['meta'], $s['data']));

                return $this->json(200, ['id' => $id]);
            }

            return ['code' => 308, 'headers' => strlen($s['data']) ? ['range' => 'bytes=0-' . (strlen($s['data']) - 1)] : []];
        }
        if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if (!isset($this->files[$id])) {
                return $this->json(404, ['error' => ['message' => 'File not found']]);
            }
            $f = &$this->files[$id];
            if ($method === 'DELETE') {
                unset($this->files[$id]);

                return ['code' => 204];
            }
            if ($method === 'PATCH') {
                $patch = json_decode($body, true);
                if (!empty($patch['trashed'])) {
                    $f['trashed'] = true;

                    return $this->json(200, ['id' => $id]);
                }
                $f['appProperties'] = $patch['appProperties'] + $f['appProperties'];
            }
            if (($q['alt'] ?? '') === 'media') {
                if (isset($h['Range']) && preg_match('/bytes=(\d+)-(\d+)/', $h['Range'], $r)) {
                    return ['code' => 206, 'body' => substr($f['data'], (int) $r[1], $r[2] - $r[1] + 1)];
                }

                return ['code' => 200, 'body' => $f['data']];
            }

            return $this->json(200, ['id' => $id, 'name' => $f['name'], 'size' => (string) strlen($f['data']), 'trashed' => !empty($f['trashed']), 'createdTime' => '2026-11-16T10:00:00Z', 'appProperties' => $f['appProperties']]);
        }

        return $this->json(500, ['error' => ['message' => 'fake: no route for ' . $method . ' ' . $url]]);
    }

    private function add(array $meta, $data)
    {
        $id = 'f' . (++$this->next);
        $this->files[$id] = ['name' => $meta['name'], 'mimeType' => $meta['mimeType'] ?? 'application/octet-stream', 'data' => $data, 'appProperties' => []];

        return $id;
    }
}

class GoogleDriveTest extends TestCase
{
    private $base;
    private $file;
    private $now = 1000;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-gd-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0755, true);
        $this->file = $this->base . '/site.zip';
        file_put_contents($this->file, random_bytes(2 * Pieces::PIECE + 5000));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->base . '/*') ?: []);
        @rmdir($this->base);
    }

    private function connect(FakeGoogle $g, &$saved = null)
    {
        $auth = new DeviceAuth($g, 'client.apps.googleusercontent.com', 'secret');
        $p = $auth->start($this->now);
        $this->assertSame('ABCD-EFGH', $p['user_code']);
        $this->assertSame('pending', $auth->poll($p, $this->now)['status']);
        $this->assertSame('pending', $auth->poll($p, $this->now)['status']);
        $done = $auth->poll($p, $this->now);
        $this->assertSame('approved', $done['status']);
        $this->assertSame('serhan@example.com', $done['tokens']['email']);
        $saved = $done['tokens'];

        return new Drive($g, $auth, $done['tokens'], function ($t) use (&$saved) {
            $saved = $t;
        }, null, function () {
            return $this->now;
        });
    }

    public function testDeviceFlowNeedsAClientIdAndReportsExpiry(): void
    {
        $auth = new DeviceAuth(new FakeGoogle(), 'id', 's');
        $this->assertSame('expired', $auth->poll(['device_code' => 'd', 'expires_at' => 10, 'interval' => 5], 11)['status']);
        $this->expectExceptionMessage('not set up');
        new DeviceAuth(new FakeGoogle(), '', '');
    }

    public function testUploadListDownloadDelete(): void
    {
        $g = new FakeGoogle();
        $drive = $this->connect($g);
        $this->assertSame('serhan@example.com', $drive->account());
        $up = new Upload($drive, function () {
            return $this->now;
        }, function () {
        });
        $state = Upload::initialState($this->file, 'acibadem.cz 2026-11-16.zip');
        for ($i = 0; $i < 30 && !$state['done']; $i++) {
            $state = $up->run($state, 0);
        }
        $this->assertTrue($state['done']);
        $items = $drive->items();
        $this->assertCount(1, $items);
        $this->assertSame(Pieces::ofFile($this->file)['digest'], $items[0]['digest']);
        $folders = array_filter($g->files, function ($f) {
            return $f['mimeType'] === Drive::FOLDER_TYPE;
        });
        $this->assertCount(1, $folders, 'one SiteShifter folder, reused');

        $sinkFile = $this->base . '/back.zip';
        $sink = new class($sinkFile) implements Sink {
            private $f;

            public function __construct($f)
            {
                $this->f = $f;
            }

            public function begin($name, $size)
            {
                file_put_contents($this->f, '');

                return 'x';
            }

            public function append($id, $offset, $piece)
            {
                clearstatcache();
                if (filesize($this->f) === $offset) {
                    file_put_contents($this->f, file_get_contents($piece), FILE_APPEND);
                }
                clearstatcache();

                return filesize($this->f);
            }

            public function finish($id)
            {
                return ['file' => $this->f];
            }
        };
        $dl = new Download($drive->open($items[0]['id']), $sink, $this->base . '/piece.tmp');
        $d = $dl->run(Download::initialState(), microtime(true) + 30);
        $this->assertTrue($d['done']);
        $this->assertTrue($d['verified']);
        $this->assertSame(md5_file($this->file), md5_file($sinkFile));

        $drive->delete($items[0]['id']);
        $this->assertSame([], $drive->items());
        $trashed = array_filter($g->files, function ($f) {
            return !empty($f['trashed']);
        });
        $this->assertCount(2, $trashed, 'package and checksum list go to the Drive trash');
    }

    public function testAPieceCutHalfWayResumesFromWhatDriveHolds(): void
    {
        $g = new FakeGoogle();
        $drive = $this->connect($g);
        $up = new Upload($drive, function () {
            return $this->now;
        }, function ($s) {
            $this->now += $s;
        });
        $state = Upload::initialState($this->file, 'a.zip');
        for ($i = 0; $i < 4; $i++) {
            $state = $up->run($state, 0); // hash x3, begin
        }
        $g->dropNextPut = true;
        for ($i = 0; $i < 30 && !$state['done']; $i++) {
            $state = $up->run($state, 0);
            $this->now += 1;
        }
        $this->assertTrue($state['done']);
        $id = $drive->items()[0]['id'];
        $this->assertSame(md5_file($this->file), md5($g->files[$id]['data']));
    }

    public function testAnExpiredAccessTokenIsRenewedAndSaved(): void
    {
        $g = new FakeGoogle();
        $drive = $this->connect($g, $saved);
        $g->access = 'rotated-by-google';
        $this->assertNotNull($drive->space());
        $this->assertSame($g->access, $saved['access_token']);
        $this->assertSame('rt', $saved['refresh_token']);
        $this->assertSame('serhan@example.com', $saved['email']);
    }

    public function testAFullDriveIsRefusedBeforeSending(): void
    {
        $g = new FakeGoogle();
        $g->quota = ['limit' => '1000', 'usage' => '900'];
        $drive = $this->connect($g);
        $up = new Upload($drive, function () {
            return $this->now;
        }, function () {
        });
        $state = Upload::initialState($this->file, 'a.zip', Pieces::ofFile($this->file));
        $this->expectException(TransferError::class);
        $this->expectExceptionMessage('Not enough free space in Google Drive');
        $up->run($state, 0);
    }

    public function testErrorsAreClassified(): void
    {
        $quota = Drive::error(['code' => 403, 'body' => json_encode(['error' => ['errors' => [['reason' => 'storageQuotaExceeded']]]])], 'x');
        $this->assertFalse($quota->isTransient());
        $this->assertStringContainsString('full', $quota->getMessage());
        $rate = Drive::error(['code' => 403, 'body' => json_encode(['error' => ['errors' => [['reason' => 'userRateLimitExceeded']]]])], 'x');
        $this->assertTrue($rate->isTransient());
        $this->assertTrue(Drive::error(['code' => 503, 'body' => ''], 'x')->isTransient());
        $this->assertFalse(Drive::error(['code' => 404, 'body' => ''], 'x')->isTransient());
    }
}

<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\CloneTask;
use SiteShifter\Plugin\Jobs\Incoming;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Jobs\Task;
use SiteShifter\Plugin\Remote\Guard;
use SiteShifter\Plugin\Remote\Keys;
use SiteShifter\Plugin\Remote\Server;
use SiteShifter\Plugin\Rest\JobApi;
use SiteShifter\Remote\CloneClient;
use SiteShifter\Remote\CloneKey;
use SiteShifter\Remote\CloneSource;
use SiteShifter\Remote\Signer;
use SiteShifter\Transfer\HttpClient;
use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\TransferError;
use SiteShifter\Transfer\Vault;

/** Builds a small package in two slices, like ExportTask, without a database. */
class FakeExportTask implements Task
{
    public static $md5;
    private $dir;

    public function __construct($dir)
    {
        $this->dir = $dir;
    }

    public function initialState(array $params)
    {
        return ['step' => 0, 'done' => false, 'params' => $params];
    }

    public function run(array $state, $deadline)
    {
        if (++$state['step'] === 2) {
            $zip = $this->dir . '/package.zip';
            $z = new \ZipArchive();
            $z->open($zip, \ZipArchive::CREATE);
            $z->addFromString('database.sql', str_repeat('siteshifter', 2000000)); // 22 MB stored: three pieces
            $z->setCompressionName('database.sql', \ZipArchive::CM_STORE);
            $z->close();
            self::$md5 = md5_file($zip);
            file_put_contents($this->dir . '/package.pieces.json', json_encode(Pieces::ofFile($zip)));
            $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        return ['percent' => $state['done'] ? 100 : 50, 'phase' => 'build'];
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }
}

/** Sends the client's requests straight to a Server, the way admin-ajax would. */
class ServerHttp implements HttpClient
{
    public $server;
    public $ip = '203.0.113.9';
    public $last;

    public function request($method, $url, array $headers = [], $body = null, $toFile = null)
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        $this->last = [$q['op'], $h, (string) $body, $q];
        $r = $this->server->handle($q['op'], $h, (string) $body, $q, $this->ip);
        if (isset($r['file'])) {
            $fh = fopen($r['file'], 'rb');
            fseek($fh, $r['range'][0]);
            file_put_contents($toFile, fread($fh, $r['range'][1] - $r['range'][0] + 1));
            fclose($fh);

            return ['code' => $r['code'], 'headers' => [], 'body' => ''];
        }
        if ($toFile !== null) {
            file_put_contents($toFile, json_encode($r['json']));

            return ['code' => $r['code'], 'headers' => [], 'body' => ''];
        }

        return ['code' => $r['code'], 'headers' => [], 'body' => json_encode($r['json'])];
    }
}

class CloneTest extends TestCase
{
    private $base;
    private $now = 1700000000;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-clone-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function clock()
    {
        return function () {
            return $this->now;
        };
    }

    private function keys($name = 'vault.php')
    {
        return new Keys(new Vault($this->base . '/' . $name, str_repeat('k', 64)), $this->clock());
    }

    public function testAKeyTravelsAsOneStringAndDamageIsCaught(): void
    {
        $key = CloneKey::make('https://old.example/', 3600, 100);
        $this->assertSame('https://old.example', $key['url']);
        $text = CloneKey::encode($key);
        $this->assertStringStartsWith('ssk1.', $text);
        $this->assertSame($key, CloneKey::decode("  $text\n"));
        foreach (['', 'hello', 'ssk1.' . substr($text, 5, 20), 'ssk1.!!!!'] as $bad) {
            try {
                CloneKey::decode($bad);
                $this->fail('accepted: ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testSignaturesAreBoundToOperationBodyTimeAndUseOnce(): void
    {
        $seen = [];
        $remember = function ($nonce) use (&$seen) {
            if (isset($seen[$nonce])) {
                return true;
            }
            $seen[$nonce] = true;

            return false;
        };
        $secretFor = function ($id) {
            return $id === 'aaaaaaaaaaaaaaaa' ? 'secret' : null;
        };
        $lower = function (array $h) {
            return array_change_key_case($h, CASE_LOWER);
        };
        $h = $lower(Signer::sign('aaaaaaaaaaaaaaaa', 'secret', 'hello', '{}', 1000));
        $this->assertNull(Signer::verify($h, 'cleanup', '{}', $secretFor, $remember, 1000), 'other operation');
        $this->assertNull(Signer::verify($h, 'hello', '{"x":1}', $secretFor, $remember, 1000), 'other body');
        $this->assertNull(Signer::verify($h, 'hello', '{}', $secretFor, $remember, 1301), 'too late');
        $this->assertNull(Signer::verify($h, 'hello', '{}', $secretFor, $remember, 699), 'too early');
        $this->assertSame([], $seen, 'nonces of refused requests are not stored');
        $this->assertSame('aaaaaaaaaaaaaaaa', Signer::verify($h, 'hello', '{}', $secretFor, $remember, 1200));
        $this->assertNull(Signer::verify($h, 'hello', '{}', $secretFor, $remember, 1200), 'replayed');
        $other = $lower(Signer::sign('bbbbbbbbbbbbbbbb', 'secret', 'hello', '{}', 1000));
        $this->assertNull(Signer::verify($other, 'hello', '{}', $secretFor, $remember, 1000), 'unknown key');
        $forged = $lower(Signer::sign('aaaaaaaaaaaaaaaa', 'guess', 'hello', '{}', 1000));
        $this->assertNull(Signer::verify($forged, 'hello', '{}', $secretFor, $remember, 1000), 'wrong secret');
    }

    public function testKeysExpireCanBeRevokedAndNeverSitInTheClear(): void
    {
        $keys = $this->keys();
        $made = $keys->create('https://old.example', 3600);
        $this->assertFalse($made['insecure']);
        $key = CloneKey::decode($made['key']);
        $this->assertSame($key['secret'], $keys->secretFor($made['id']));
        $this->assertStringNotContainsString($key['secret'], (string) file_get_contents($this->base . '/vault.php'));
        $list = $keys->all();
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['active']);
        $this->assertArrayNotHasKey('secret', $list[0]);

        $this->now += 3601;
        $this->assertNull($keys->secretFor($made['id']), 'expired');
        $this->assertFalse($keys->all()[0]['active']);
        $this->now += Keys::KEEP + 1;
        $this->assertSame([], $keys->all(), 'old keys are forgotten');

        $second = $keys->create('http://old.example', 3600);
        $this->assertTrue($second['insecure']);
        $keys->revoke($second['id']);
        $this->assertNull($keys->secretFor($second['id']));
        $this->assertTrue($keys->all()[0]['revoked']);
        $this->expectException(\InvalidArgumentException::class);
        $keys->revoke('ffffffffffffffff');
    }

    public function testTheTargetKeepsOnePastedKey(): void
    {
        $source = $this->keys('source.php');
        $target = $this->keys('target.php');
        $made = $source->create('https://old.example');
        $this->assertNull($target->remoteView());
        $target->setRemote($made['key']);
        $this->assertSame(['url' => 'https://old.example', 'expires' => $made['expires'], 'expired' => false, 'insecure' => false], $target->remoteView());
        $target->forgetRemote();
        $this->assertNull($target->remote());
        $this->now += Keys::TTL + 1;
        $this->expectException(\InvalidArgumentException::class);
        $target->setRemote($made['key']);
    }

    public function testAnAddressThatKeepsFailingIsHeldOff(): void
    {
        $guard = new Guard($this->base . '/guard.php', $this->clock());
        for ($i = 0; $i < Guard::MAX_FAILS; $i++) {
            $this->assertFalse($guard->blocked('198.51.100.1'));
            $guard->fail('198.51.100.1');
        }
        $this->assertTrue($guard->blocked('198.51.100.1'));
        $this->assertFalse($guard->blocked('198.51.100.2'));
        $this->now += Guard::WINDOW + 1;
        $this->assertFalse($guard->blocked('198.51.100.1'));
        $this->assertFalse($guard->seen('n1', $this->now + 10));
        $this->assertTrue($guard->seen('n1', $this->now + 10));
        $this->now += 11;
        $this->assertFalse($guard->seen('n1', $this->now + 10), 'expired nonces drop out of the log');
    }

    /** @return array{0:Server,1:Keys,2:JobStore,3:ServerHttp} */
    private function source()
    {
        $store = new JobStore($this->base . '/jobs');
        $runner = new Runner($store, ['export' => function ($job, $dir) {
            return new FakeExportTask($dir);
        }]);
        $keys = $this->keys('source.php');
        $server = new Server($keys, new Guard($this->base . '/guard.php', $this->clock()), $store, $runner, function () {
            return ['plugin' => '0.3.0', 'manifest' => ['site' => ['home' => 'https://old.example']], 'free' => 5e9];
        }, function () {
            return microtime(true) + 5;
        }, $this->clock());
        $http = new ServerHttp();
        $http->server = $server;

        return [$server, $keys, $store, $http];
    }

    public function testATargetBuildsFetchesAndRemovesAPackageOfTheSource(): void
    {
        list(, $keys, $store, $http) = $this->source();
        $this->now = time(); // the client signs with real time
        $made = $keys->create('https://old.example');
        $remote = CloneKey::decode($made['key']);
        $client = new CloneClient($http, $remote);

        $hello = $client->call('hello');
        $this->assertSame(CloneClient::PROTOCOL, $hello['protocol']);
        $this->assertSame('https://old.example', $hello['manifest']['site']['home']);

        $start = $client->call('export_start', ['params' => ['profile' => 'content', 'media' => 0, 'evil' => 'x', 'clone_key' => 'ffffffffffffffff']]);
        $job = $start['job']['id'];
        $params = $store->load($job)['params'];
        $this->assertSame($made['id'], $params['clone_key']);
        $this->assertArrayNotHasKey('evil', $params);
        $this->assertSame('content', $params['profile']);
        $again = $client->call('export_start', ['params' => []]);
        $this->assertSame($job, $again['job']['id'], 'a repeated start continues the same package');

        $step = ['job' => ['status' => 'running']];
        for ($i = 0; $i < 5 && $step['job']['status'] === 'running'; $i++) {
            $step = $client->call('export_step', ['job' => $job]);
        }
        $this->assertSame('done', $step['job']['status']);
        $zip = $store->dir($job) . '/package.zip';
        $this->assertSame(filesize($zip), $step['size']);

        $source = new CloneSource($client, $job, $step['name'], $step['size'], $step['pieces']);
        $stat = $source->stat();
        $this->assertCount(3, $stat['pieces']['hashes']);
        $copy = $this->base . '/copy.zip';
        $part = $this->base . '/part';
        for ($i = 0, $n = Pieces::count($stat['size']); $i < $n; $i++) {
            $from = $i * Pieces::PIECE;
            $source->fetch($from, $from + Pieces::length($stat['size'], $i) - 1, $part);
            file_put_contents($copy, file_get_contents($part), FILE_APPEND);
        }
        $this->assertSame(md5_file($zip), md5_file($copy));

        $this->assertTrue($client->call('cleanup', ['job' => $job])['deleted']);
        $this->assertNull($store->load($job), 'the package does not stay on the source');
    }

    public function testRequestsWithoutAValidKeyAreRefusedAndCountedAgainstTheAddress(): void
    {
        list($server, $keys, $store, $http) = $this->source();
        $this->now = time();
        $made = $keys->create('https://old.example');
        $remote = CloneKey::decode($made['key']);
        $client = new CloneClient($http, $remote);
        $client->call('hello');

        // The same request again (captured and replayed) is refused.
        list($op, $h, $body, $q) = $http->last;
        $this->assertSame(403, $server->handle($op, $h, $body, $q, $http->ip)['code']);

        // Another key cannot see this key's packages.
        $job = $client->call('export_start')['job']['id'];
        $other = new CloneClient($http, CloneKey::decode($keys->create('https://old.example')['key']));
        try {
            $other->call('export_step', ['job' => $job]);
            $this->fail('another key read the package');
        } catch (TransferError $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        try {
            $other->piece($job, 0, 10, $this->base . '/x');
            $this->fail('another key fetched a piece');
        } catch (TransferError $e) {
            $this->assertFileDoesNotExist($this->base . '/x');
        }

        $keys->revoke($made['id']);
        try {
            $client->call('hello');
            $this->fail('a revoked key still works');
        } catch (TransferError $e) {
            $this->assertSame(403, $e->getCode());
        }
        $forged = new CloneClient($http, ['secret' => str_repeat('x', 43)] + $remote);
        for ($i = 0; $i < Guard::MAX_FAILS; $i++) {
            try {
                $forged->call('hello');
            } catch (TransferError $e) {
            }
        }
        $fresh = new CloneClient($http, CloneKey::decode($keys->create('https://old.example')['key']));
        try {
            $fresh->call('hello');
            $this->fail('the address was not held off');
        } catch (TransferError $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertTrue($e->isTransient());
        }
        $this->assertNotNull($store->load($job), 'refused requests change nothing');
    }

    public function testTheAdminScreensPairTheTwoSites(): void
    {
        list(, $sourceKeys, , $http) = $this->source();
        $this->now = time();
        $sourceApi = (new JobApi(new Runner(new JobStore($this->base . '/a'), [])))->withClone(function () use ($sourceKeys) {
            return $sourceKeys;
        }, null, 'https://old.example');
        $made = $sourceApi->handle('clone_key_create', ['params' => ['hours' => 2]]);
        $this->assertTrue($made['ok']);
        $this->assertSame($this->now + 7200, $made['created']['expires']);
        $this->assertCount(1, $made['keys']);
        $this->assertFalse($sourceApi->handle('clone_remote_set', ['params' => ['key' => $made['created']['key']]])['ok'], 'a site cannot clone itself');

        $targetKeys = $this->keys('target.php');
        $targetApi = (new JobApi(new Runner(new JobStore($this->base . '/b'), [])))->withClone(function () use ($targetKeys) {
            return $targetKeys;
        }, function (array $key) use ($http) {
            return new CloneClient($http, $key);
        }, 'https://new.example');
        $set = $targetApi->handle('clone_remote_set', ['params' => ['key' => $made['created']['key']]]);
        $this->assertTrue($set['ok']);
        $this->assertTrue($set['test']['ok'], (string) ($set['test']['message'] ?? ''));
        $this->assertSame('0.3.0', $set['test']['source']['plugin']);
        $this->assertSame('https://old.example', $targetApi->handle('clone_keys', [])['remote']['url']);

        $sourceApi->handle('clone_key_revoke', ['params' => ['key' => $made['created']['id']]]);
        $test = $targetApi->handle('clone_remote_test', []);
        $this->assertFalse($test['test']['ok']);
        $this->assertStringContainsString('refused', $test['test']['message']);
        $this->assertNull($targetApi->handle('clone_remote_forget', [])['remote']);
    }

    public function testTheCloneJobCopiesTheSourceIntoIncomingAndCleansUp(): void
    {
        list(, $keys, $store, $http) = $this->source();
        $this->now = time();
        $remote = CloneKey::decode($keys->create('https://old.example')['key']);
        $incoming = new Incoming(new JobStore($this->base . '/in'));
        $free = 1e12;
        $runner = new Runner(new JobStore($this->base . '/target'), ['clone' => function ($job, $dir) use ($incoming, $http, $remote, &$free) {
            return new CloneTask($dir, $incoming, function () use ($http, $remote) {
                return new CloneClient($http, $remote);
            }, function () use (&$free) {
                return $free;
            }, null, function () {
            });
        }]);
        $view = $runner->start('clone', ['profile' => 'content', 'scope' => 'root', 'bogus' => 1]);
        $percents = [];
        for ($i = 0; $i < 30 && $view['status'] === 'running'; $i++) {
            $view = $runner->step($view['id'], microtime(true) + 5);
            $percents[] = $view['percent'];
        }
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertSame($percents, (function ($p) {
            sort($p);

            return $p;
        })($percents), 'progress only goes up');
        $this->assertFalse($view['result']['left_on_source']);
        $copy = $incoming->path($view['result']['id']);
        $this->assertSame(FakeExportTask::$md5, md5_file($copy));
        $this->assertSame([], array_filter($store->all(), function ($j) {
            return $j['type'] === 'export';
        }), 'the package was removed from the source');

        // Not enough disk here: the job stops before fetching and says why.
        $free = 1000;
        $view = $runner->start('clone', ['profile' => 'full']);
        for ($i = 0; $i < 10 && $view['status'] === 'running'; $i++) {
            $view = $runner->step($view['id'], microtime(true) + 5);
        }
        $this->assertSame('paused', $view['status']);
        $this->assertStringContainsString('MB free', (string) $view['error']);
    }

    public function testCloningNeedsAPastedKey(): void
    {
        $runner = new Runner(new JobStore($this->base . '/target'), ['clone' => function ($job, $dir) {
            return new CloneTask($dir, new Incoming(new JobStore($this->base . '/in')), function () {
                throw new \InvalidArgumentException('Paste a clone key from the source site first.');
            });
        }]);
        $this->expectException(\InvalidArgumentException::class);
        $runner->start('clone', []);
    }
}

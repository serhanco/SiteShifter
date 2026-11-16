<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\FetchTask;
use SiteShifter\Plugin\Jobs\Incoming;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Rest\Pull;
use SiteShifter\Transfer\Pieces;

class PullFetchTest extends TestCase
{
    private $base;
    private $zip;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-pf-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/job', 0755, true);
        $this->zip = $this->base . '/remote.zip';
        $z = new \ZipArchive();
        $z->open($this->zip, \ZipArchive::CREATE);
        $z->addFromString('database.sql', random_bytes(9000));
        $z->close();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function url(): string
    {
        return 'https://other.test/wp-admin/admin-ajax.php?action=siteshifter_pull&job=' . str_repeat('a', 32) . '&token=' . str_repeat('b', 48);
    }

    /** A fake remote site that honours Range like Pull does. */
    private function http(&$calls = 0)
    {
        $file = $this->zip;

        return function ($url, array $headers, $to) use ($file, &$calls) {
            $calls++;
            $size = filesize($file);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $r = Pull::range('bytes=' . $q['range'], $size);
            if ($r === null) {
                return ['code' => 416, 'headers' => []];
            }
            file_put_contents($to, file_get_contents($file, false, null, $r[0], $r[1] - $r[0] + 1));

            return ['code' => 200, 'headers' => ['x-siteshifter-range' => "{$r[0]}-{$r[1]}/{$size}"]];
        };
    }

    public function testRangeParsing(): void
    {
        $this->assertSame([0, 0], Pull::range('bytes=0-0', 100));
        $this->assertSame([10, 99], Pull::range('bytes=10-', 100));
        $this->assertSame([90, 99], Pull::range('bytes=-10', 100));
        $this->assertSame([5, 99], Pull::range('bytes=5-500', 100));
        $this->assertNull(Pull::range('bytes=100-', 100));
        $this->assertNull(Pull::range('bytes=9-3', 100));
        $this->assertNull(Pull::range('items=0-1', 100));
        $this->assertNull(Pull::range('bytes=-', 100));
    }

    public function testTokenIsCheckedByHashAndExpires(): void
    {
        $token = bin2hex(random_bytes(24));
        $job = ['type' => 'export', 'status' => 'done', 'pull' => ['hash' => hash('sha256', $token), 'expires' => 1000]];
        $this->assertTrue(Pull::check($job, $token, 999));
        $this->assertFalse(Pull::check($job, $token, 1001), 'expired');
        $this->assertFalse(Pull::check($job, 'x' . $token, 999));
        $this->assertFalse(Pull::check($job, '', 999));
        $this->assertFalse(Pull::check(['type' => 'export', 'status' => 'done'], $token, 999), 'no link issued');
        $this->assertFalse(Pull::check(['type' => 'import', 'status' => 'done'] + $job, $token, 999));
    }

    public function testRunnerIssuesALinkOnlyForAFinishedExportAndNeverStoresTheToken(): void
    {
        $store = new JobStore($this->base . '/jobs');
        $job = $store->create('export', [], []);
        $job['status'] = 'done';
        $store->save($job);
        copy($this->zip, $store->dir($job['id']) . '/package.zip');
        $runner = new Runner($store, []);
        $d = $runner->issuePull($job['id'], 100);
        $this->assertStringNotContainsString($d['token'], file_get_contents($store->dir($job['id']) . '/job.json'));
        $found = $runner->pullFile($job['id'], $d['token'], 200);
        $this->assertIsArray($found);
        $this->assertSame(filesize($this->zip), $found['size']);
        $this->assertIsString($runner->pullFile($job['id'], $d['token'], 100 + Pull::TTL + 1));
        $this->assertIsString($runner->pullFile($job['id'], str_repeat('0', 48), 200));
        $this->assertIsString($runner->pullFile('nope', $d['token'], 200));
    }

    public function testOnlySiteShifterLinksAreAccepted(): void
    {
        $this->assertSame($this->url(), FetchTask::validate($this->url()));
        foreach (['', 'ftp://x/y', 'https://other.test/', 'https://other.test/wp-admin/admin-ajax.php?action=other&job=' . str_repeat('a', 32) . '&token=' . str_repeat('b', 48), 'https://other.test/?action=siteshifter_pull&job=zz&token=yy'] as $bad) {
            try {
                FetchTask::validate($bad);
                $this->fail('accepted: ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testFetchesInPiecesAndLeavesAReadyPackage(): void
    {
        $incoming = new Incoming(new JobStore($this->base . '/in'));
        $calls = 0;
        $task = new FetchTask($this->base . '/job', $incoming, $this->http($calls));
        $state = $task->initialState(['url' => $this->url()]);
        $this->assertGreaterThan(0, filesize($this->zip));
        $state = $task->run($state, microtime(true) + 5);
        $this->assertTrue($state['done']);
        $this->assertSame(100, $task->progress($state)['percent']);
        $this->assertSame(filesize($this->zip), $state['dl']['size']);
        $this->assertFalse($state['dl']['verified'], 'an older site sends no checksum list');
        $path = $incoming->path($state['result']['id']);
        $this->assertSame(md5_file($this->zip), md5_file($path));
    }

    public function testResumesAfterAnInterruptionAndChecksPieces(): void
    {
        // 3 pieces of 8 MB; the network drops once mid-way; the other site publishes its checksum list.
        $big = $this->base . '/big.zip';
        $z = new \ZipArchive();
        $z->open($big, \ZipArchive::CREATE);
        $z->addFromString('database.sql', random_bytes(18 * 1048576));
        $z->setCompressionName('database.sql', \ZipArchive::CM_STORE);
        $z->close();
        $this->zip = $big;
        $listing = json_encode(Pieces::ofFile($big));
        $http = $this->http();
        $count = 0;
        $flaky = function ($url, array $h, $to) use ($http, &$count, $listing) {
            if (++$count === 3) {
                return ['code' => 0, 'headers' => []]; // the network drops once
            }
            if (strpos($url, '&pieces=1') !== false) {
                file_put_contents($to, $listing);

                return ['code' => 200, 'headers' => ['x-siteshifter-pieces' => '1']];
            }

            return $http($url, $h, $to);
        };
        $now = 1000.0;
        $clock = function () use (&$now) {
            return $now;
        };
        $incoming = new Incoming(new JobStore($this->base . '/in'));
        $task = new FetchTask($this->base . '/job', $incoming, $flaky, $clock, function ($s) use (&$now) {
            $now += $s;
        });
        $state = $task->initialState(['url' => $this->url()]);
        for ($i = 0; $i < 50 && !$state['done']; $i++) {
            $state = $task->run($state, $now - 1); // one step per call
            $now += 1;
        }
        $this->assertTrue($state['done']);
        $this->assertTrue($state['dl']['verified']);
        $this->assertSame(md5_file($big), md5_file($incoming->path($state['result']['id'])));
    }

    public function testExplainsARefusedLink(): void
    {
        $task = new FetchTask($this->base . '/job', new Incoming(new JobStore($this->base . '/in')), function () {
            return ['code' => 403, 'headers' => []];
        });
        $state = $task->initialState(['url' => $this->url()]);
        $this->expectExceptionMessage('expired');
        $task->run($state, microtime(true) + 1);
    }
}

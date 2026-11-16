<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Cloud\GoogleAccount;
use SiteShifter\Plugin\Jobs\CloudFetchTask;
use SiteShifter\Plugin\Jobs\CloudUploadTask;
use SiteShifter\Plugin\Jobs\Incoming;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Transfer\FolderStorage;
use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\Vault;

require_once __DIR__ . '/GoogleDriveTest.php'; // FakeGoogle

class CloudJobsTest extends TestCase
{
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-cj-' . bin2hex(random_bytes(4));
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

    public function testConnectPollAndDisconnectKeepNothingInTheClear(): void
    {
        $now = 1000;
        $g = new FakeGoogle();
        $vault = new Vault($this->base . '/vault.php', str_repeat('s', 64));
        $acct = new GoogleAccount($vault, $g, ['id' => 'x.apps.googleusercontent.com', 'secret' => 'sec'], function () use (&$now) {
            return $now;
        });
        $this->assertFalse($acct->status()['connected']);
        $this->assertTrue($acct->status()['configured']);
        $code = $acct->connect();
        $this->assertSame('ABCD-EFGH', $code['user_code']);
        $this->assertSame('ABCD-EFGH', $acct->status()['pending']['user_code']);
        $this->assertSame('pending', $acct->poll()['status']);
        $this->assertSame('pending', $acct->poll()['status'], 'too soon: Google is not asked again');
        $now += 5;
        $this->assertSame('pending', $acct->poll()['status']);
        $now += 5;
        $done = $acct->poll();
        $this->assertSame('approved', $done['status']);
        $this->assertSame('serhan@example.com', $acct->status()['account']);
        $this->assertTrue($acct->status()['connected']);
        $raw = (string) file_get_contents($this->base . '/vault.php');
        $this->assertStringNotContainsString('refresh_token', $raw);
        $this->assertStringNotContainsString('serhan@example.com', $raw);
        $this->assertNotNull($acct->drive()->space());
        $acct->disconnect();
        $this->assertFalse($acct->status()['connected']);
        $this->expectException(\InvalidArgumentException::class);
        $acct->drive();
    }

    public function testWithoutAClientIdTheScreenSaysSoAndOwnClientsAreChecked(): void
    {
        $acct = new GoogleAccount(new Vault($this->base . '/v.php', str_repeat('s', 64)), new FakeGoogle(), ['id' => '', 'secret' => '']);
        $this->assertFalse($acct->status()['configured']);
        $acct->setClient('mine.apps.googleusercontent.com', 's');
        $this->assertTrue($acct->status()['own_client']);
        $this->expectException(\InvalidArgumentException::class);
        $acct->setClient('not-a-client', '');
    }

    public function testAnExportGoesToTheCloudAndComesBackAsAnIncomingPackage(): void
    {
        $store = new JobStore($this->base . '/jobs');
        $job = $store->create('export', [], []);
        $job['status'] = 'done';
        $store->save($job);
        $zip = $store->dir($job['id']) . '/package.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('database.sql', random_bytes(300000));
        $z->close();
        file_put_contents($store->dir($job['id']) . '/package.pieces.json', json_encode(Pieces::ofFile($zip)));

        $cloud = new FolderStorage($this->base . '/cloud');
        $storage = function () use ($cloud) {
            return $cloud;
        };
        $incoming = new Incoming(new JobStore($this->base . '/in'));
        $runner = new Runner($store, [
            'cloud_upload' => function () use ($store, $storage) {
                return new CloudUploadTask($store, $storage, 'old.example');
            },
            'cloud_fetch' => function ($j, $dir) use ($incoming, $storage) {
                return new CloudFetchTask($dir, $incoming, $storage);
            },
        ]);
        $view = $runner->start('cloud_upload', ['job' => $job['id']]);
        for ($i = 0; $i < 20 && $view['status'] === 'running'; $i++) {
            $view = $runner->step($view['id'], microtime(true) + 5);
        }
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $items = $cloud->items();
        $this->assertCount(1, $items);
        $this->assertStringStartsWith('siteshifter-old.example-', $items[0]['name']);

        $view = $runner->start('cloud_fetch', ['item' => $items[0]['id']]);
        for ($i = 0; $i < 20 && $view['status'] === 'running'; $i++) {
            $view = $runner->step($view['id'], microtime(true) + 5);
        }
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertSame(md5_file($zip), md5_file($incoming->path($view['result']['id'])));
    }

    public function testUploadNeedsAFinishedExport(): void
    {
        $store = new JobStore($this->base . '/jobs');
        $task = new CloudUploadTask($store, function () {
        }, 'x');
        $this->expectException(\InvalidArgumentException::class);
        $task->initialState(['job' => str_repeat('a', 32)]);
    }
}

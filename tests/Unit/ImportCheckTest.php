<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Import\Inspector;
use SiteShifter\Plugin\Jobs\Incoming;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Rest\JobApi;

class ImportCheckTest extends TestCase
{
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-in-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function incoming(?callable $free = null): Incoming
    {
        return new Incoming(new JobStore($this->base), $free);
    }

    private function zip(array $manifestOverride = [], bool $withSql = true): string
    {
        $file = sys_get_temp_dir() . '/ss-pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $manifest = array_replace_recursive([
            'format' => 1, 'generator' => 'SiteShifter', 'created' => '2026-11-15T10:00:00+00:00',
            'site' => ['url' => 'https://old.test', 'home' => 'https://old.test', 'abspath' => '/old/', 'table_prefix' => 'wp_', 'wp_version' => '6.5', 'multisite' => false],
            'php' => ['version' => '8.1.0', 'requires' => '0'],
            'db' => ['server' => '10.11.0-MariaDB', 'tables' => ['wp_posts' => 3], 'collations' => [], 'foreign_tables' => [], 'largest_row' => 0, 'db_version' => '57155'],
            'plugins' => ['active' => [], 'details' => []], 'themes' => [], 'files' => ['count' => 2, 'bytes' => 10],
        ], $manifestOverride);
        $z = new \ZipArchive();
        $z->open($file, \ZipArchive::CREATE);
        $z->addFromString('siteshifter.json', json_encode($manifest));
        if ($withSql) {
            $z->addFromString('database.sql', "CREATE TABLE wp_posts (id int);\n");
        }
        $z->addFromString('files/wp-content/uploads/a.txt', 'hello');
        $z->close();

        return $file;
    }

    private function target(array $o = []): array
    {
        return $o + ['prefix' => 'wp_', 'home' => 'https://new.test', 'abspath' => sys_get_temp_dir(), 'wp_version' => '6.5', 'wp_db_version' => 57155, 'db' => null];
    }

    private function ids(array $result): array
    {
        return array_column($result['rows'], 'level', 'id');
    }

    public function testChunkedUploadCanBeResumedAndIsVerified(): void
    {
        $zip = $this->zip();
        $bytes = (string) file_get_contents($zip);
        $in = $this->incoming();
        $id = $in->begin('my site.zip', strlen($bytes));
        $half = intdiv(strlen($bytes), 2);
        $piece = function (string $data) {
            $f = tempnam(sys_get_temp_dir(), 'pc');
            file_put_contents($f, $data);

            return $f;
        };

        $this->assertSame(['accepted' => true, 'size' => $half], $in->append($id, 0, $piece(substr($bytes, 0, $half))));
        // The same piece again (the reply was lost): not written twice, the real size comes back.
        $this->assertSame(['accepted' => false, 'size' => $half], $in->append($id, 0, $piece(substr($bytes, 0, $half))));
        $this->assertSame(['accepted' => true, 'size' => strlen($bytes)], $in->append($id, $half, $piece(substr($bytes, $half))));

        $done = $in->finish($id);
        $this->assertSame('my site.zip', $done['name']);
        $this->assertSame(strlen($bytes), $done['bytes']);
        $this->assertSame($bytes, file_get_contents($in->path($id)));
        $this->assertCount(1, $in->all());
        unlink($zip);
    }

    public function testBrokenOrOversizedUploadsAreRejectedAndLeaveNothing(): void
    {
        $in = $this->incoming();
        $id = $in->begin('x.zip', 10);
        $f = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($f, str_repeat('a', 10));
        $in->append($id, 0, $f);
        try {
            $in->finish($id); // 10 bytes of "a" is not a zip
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a readable zip', $e->getMessage());
        }
        $this->assertSame([], $in->all());
        $this->assertFileDoesNotExist($this->base . '/incoming/' . $id . '.part');

        $id2 = $in->begin('y.zip', 5);
        file_put_contents($f, 'toolongvalue');
        $this->expectException(\RuntimeException::class);
        $in->append($id2, 0, $f);
    }

    public function testNotEnoughDiskStopsTheUploadBeforeItStarts(): void
    {
        $this->expectExceptionMessage('disk is full');
        $this->incoming(static function () {
            return 1000.0;
        })->begin('big.zip', 5000);
    }

    public function testFtpFilesAreListedAndOnlyKnownIdsResolve(): void
    {
        $in = $this->incoming();
        $dir = $in->dir();
        copy($z = $this->zip(), $dir . '/from-ftp.zip');
        unlink($z);
        $all = $in->all();
        $this->assertSame('from-ftp.zip', $all[0]['name']);
        $this->assertSame($dir . '/from-ftp.zip', $in->path($all[0]['id']));
        $this->assertNull($in->path(str_repeat('a', 32)));
        $this->assertFalse($in->delete('../../etc/passwd'));
        $this->assertTrue($in->delete($all[0]['id']));
        $this->assertSame([], $in->all());
    }

    public function testDryRunDescribesAMatchingPackage(): void
    {
        $zip = $this->zip();
        $r = (new Inspector())->inspect($zip, $this->target());
        $this->assertTrue($r['ok']);
        $this->assertSame('https://old.test', $r['package']['source']);
        $this->assertSame('full', $r['package']['profile']);
        $this->assertArrayHasKey('plan_replace', $this->ids($r));
        $this->assertSame('info', $this->ids($r)['plan_address']);
        $this->assertArrayNotHasKey('plan_prefix', $this->ids($r));
        unlink($zip);
    }

    public function testDryRunBlocksDifferentPrefixMultisiteAndNonPackages(): void
    {
        $zip = $this->zip(['site' => ['table_prefix' => 'abc_']]);
        $r = (new Inspector())->inspect($zip, $this->target());
        $this->assertSame('block', $this->ids($r)['plan_prefix']);
        $this->assertTrue($r['blockers']);

        $ms = $this->zip(['site' => ['multisite' => true]]);
        $this->assertSame('block', $this->ids((new Inspector())->inspect($ms, $this->target()))['plan_multisite']);

        $bad = $this->zip([], false);
        $r = (new Inspector())->inspect($bad, $this->target());
        $this->assertFalse($r['ok']);
        $this->assertSame('block', $this->ids($r)['package_type']);

        $notZip = tempnam(sys_get_temp_dir(), 'nz');
        file_put_contents($notZip, 'nope');
        $this->assertFalse((new Inspector())->inspect($notZip, $this->target())['ok']);
        array_map('unlink', [$zip, $ms, $bad, $notZip]);
    }

    public function testDryRunWarnsAboutPartialPackagesAndManualUploads(): void
    {
        $zip = $this->zip(['scope' => ['profile' => 'content', 'manual_uploads' => true, 'warnings' => ['users_excluded']]]);
        $r = (new Inspector())->inspect($zip, $this->target());
        $ids = $this->ids($r);
        $this->assertSame('warn', $ids['plan_uploads']);
        $this->assertSame('warn', $ids['plan_users']);
        $text = json_encode($r['rows']);
        $this->assertStringContainsString('partial (content)', $text);
        unlink($zip);
    }

    public function testApiExposesTheImportOperationsWithoutPaths(): void
    {
        $in = $this->incoming();
        $z = $this->zip();
        $id = $in->begin('p.zip', filesize($z));
        $in->append($id, 0, $z);
        $in->finish($id);
        $api = (new JobApi(new Runner(new JobStore($this->base), [])))->withImport($in, static function ($path) {
            return ['package' => null, 'rows' => [], 'path_seen' => basename($path)];
        });

        $list = $api->handle('incoming', []);
        $this->assertTrue($list['ok']);
        $this->assertArrayNotHasKey('file', $list['packages'][0]);
        $this->assertSame($id . '.zip', $api->handle('import_check', ['id' => $id])['path_seen']);
        $this->assertSame('not_found', $api->handle('import_check', ['id' => str_repeat('b', 32)])['code']);
        $this->assertTrue($api->handle('incoming_delete', ['id' => $id])['deleted']);
        $this->assertSame('unavailable', (new JobApi(new Runner(new JobStore($this->base), [])))->handle('incoming', [])['code']);
        unlink($z);
    }
}

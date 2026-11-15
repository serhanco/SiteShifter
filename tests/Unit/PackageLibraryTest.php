<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\PackageLibrary;
use SiteShifter\Plugin\Jobs\Retention;
use SiteShifter\Plugin\Rest\JobApi;
use SiteShifter\Plugin\Jobs\Runner;

class PackageLibraryTest extends TestCase
{
    private $base;

    /** @var JobStore */
    private $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-lib-' . bin2hex(random_bytes(4));
        $this->store = new JobStore($this->base);
    }

    protected function tearDown(): void
    {
        $this->store->purge(-1, PHP_INT_MAX - 10);
        foreach ((array) glob($this->base . '/{,.}*', GLOB_BRACE) as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->base);
    }

    private function package(int $age, string $type = 'export', string $status = 'done', bool $zip = true): string
    {
        $job = $this->store->create($type, [], ['build' => ['manifest' => ['site' => ['home' => 'https://old.test'], 'scope' => ['profile' => 'content', 'manual_uploads' => true, 'warnings' => ['users_excluded']]]], 'check' => ['ok' => true, 'files' => 3, 'tables' => 2]], time() - $age);
        $job['status'] = $status;
        $this->store->save($job);
        if ($zip) {
            file_put_contents($this->store->dir($job['id']) . '/package.zip', str_repeat('x', 100));
        }

        return $job['id'];
    }

    public function testListsOnlyFinishedExportPackagesNewestFirstWithoutPaths(): void
    {
        $old = $this->package(5000);
        $new = $this->package(10);
        $this->package(10, 'address_change');
        $this->package(10, 'export', 'running');
        $this->package(10, 'export', 'done', false);

        $list = (new PackageLibrary($this->store))->all();
        $this->assertSame([$new, $old], array_column($list, 'id'));
        $this->assertSame('content', $list[0]['profile']);
        $this->assertSame('https://old.test', $list[0]['source']);
        $this->assertSame(100, $list[0]['bytes']);
        $this->assertTrue($list[0]['valid']);
        $this->assertTrue($list[0]['manual_uploads']);
        $this->assertStringNotContainsString($this->base, json_encode($list));
    }

    public function testDeleteManyOnlyDeletesKnownPackages(): void
    {
        $a = $this->package(10);
        $b = $this->package(20);
        $addr = $this->package(10, 'address_change');
        $lib = new PackageLibrary($this->store);

        $done = $lib->deleteMany([$a, $addr, '../etc', 'nothex']);
        $this->assertSame([$a], $done);
        $this->assertDirectoryDoesNotExist($this->store->dir($a));
        $this->assertDirectoryExists($this->store->dir($addr)); // not a package: untouched
        $this->assertDirectoryExists($this->store->dir($b));
    }

    public function testPruneKeepsNewestAndDropsOld(): void
    {
        $ids = [$this->package(100), $this->package(200), $this->package(3 * 86400), $this->package(40 * 86400)];
        $lib = new PackageLibrary($this->store);

        $removed = $lib->prune(3, 30);
        $this->assertSame([$ids[3]], $removed);                  // older than 30 days
        $this->assertSame([$ids[2]], $lib->prune(2, 0));         // beyond the newest two
        $this->assertSame([], $lib->prune(0, 0));                // no limit set: nothing happens
    }

    public function testRetentionIsOffByDefaultAndClampsValues(): void
    {
        $saved = null;
        $lib = new PackageLibrary($this->store);
        $this->package(40 * 86400);
        $r = new Retention($lib, static function () use (&$saved) {
            return $saved;
        }, static function (array $v) use (&$saved) {
            $saved = $v;
        });

        $this->assertFalse($r->get()['enabled']);
        $this->assertSame([], $r->apply());
        $this->assertCount(1, $lib->all());

        $s = $r->set(['enabled' => 1, 'max_count' => 99999, 'max_days' => -4]);
        $this->assertTrue($s['enabled']);
        $this->assertSame(1000, $s['max_count']);
        $this->assertSame(0, $s['max_days']);
        $r->set(['enabled' => 1, 'max_count' => 5, 'max_days' => 30]);
        $this->assertCount(1, $r->apply());
        $this->assertCount(0, $lib->all());
    }

    public function testApiExposesListAndBulkDelete(): void
    {
        $id = $this->package(10);
        $lib = new PackageLibrary($this->store);
        $api = new JobApi(new Runner($this->store, []), null, null, $lib, null);

        $r = $api->handle('packages', []);
        $this->assertTrue($r['ok']);
        $this->assertSame($id, $r['packages'][0]['id']);
        $d = $api->handle('delete_many', ['params' => ['ids' => $id . ',zzz']]);
        $this->assertSame([$id], $d['deleted']);
        $this->assertSame('unavailable', (new JobApi(new Runner($this->store, [])))->handle('packages', [])['code']);
    }
}

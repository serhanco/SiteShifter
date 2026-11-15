<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Package\PackageBuilder;
use SiteShifter\Plugin\Jobs\ExportTask;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;

class ExportTaskTest extends DatabaseTestCase
{
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = sys_get_temp_dir() . '/ss-site-' . bin2hex(random_bytes(4));
        foreach (['wp-content/uploads/2026', 'wp-content/plugins/x', 'wp-content/cache', 'wp-includes'] as $d) {
            mkdir($this->site . '/' . $d, 0755, true);
        }
        file_put_contents($this->site . '/wp-includes/version.php', "<?php\n\$wp_version = '6.8';\n\$wp_db_version = 60000;\n");
        file_put_contents($this->site . '/wp-config.php', "<?php\n");
        file_put_contents($this->site . '/wp-content/cache/skip.txt', 'cache');
        for ($i = 0; $i < 25; $i++) {
            file_put_contents($this->site . "/wp-content/uploads/2026/f{$i}.txt", str_repeat("file {$i}\n", 50));
        }
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->site);
        parent::tearDown();
    }

    public function testExportRunsInSlicesAndProducesACheckedPackageWithoutItsOwnWorkFolder(): void
    {
        $this->createWordPressTables();
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','http://old.test'),('home','http://old.test'),('active_plugins','a:0:{}')");
        $db = $this->db;
        $site = $this->site;
        $store = new JobStore($site . '/wp-content/siteshifter-work');
        // An earlier job's leftovers must never be packed into a new package.
        $old = $store->create('host_test', [], []);
        file_put_contents($store->dir($old['id']) . '/big.bin', 'old job data');

        $runner = new Runner($store, [
            'export' => static function ($job, $dir) use ($db, $site) {
                return new ExportTask($dir, static function (array $options) use ($db, $site, $dir) {
                    return new PackageBuilder($db, $site, 'wp_', $dir, $dir . '/package.zip', $options);
                });
            },
        ]);
        $view = $runner->start('export', []);
        $slices = 0;
        while ($view['status'] === 'running' && $slices < 500) {
            $view = $runner->step($view['id'], microtime(true) - 1);
            $slices++;
        }
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertGreaterThan(3, $slices);
        $this->assertSame(100, $view['percent']);
        $this->assertGreaterThan(25, $view['result']['files']);
        $this->assertGreaterThan(0, $view['result']['tables']);

        $zip = new \ZipArchive();
        $zip->open($store->dir($view['id']) . '/package.zip');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertSame(count($names), count(array_unique($names)), 'no entry may appear twice');
        $this->assertContains('database.sql', $names);
        $this->assertContains('files/wp-content/uploads/2026/f3.txt', $names);
        $this->assertNotContains('files/wp-content/cache/skip.txt', $names);
        foreach ($names as $n) {
            $this->assertStringNotContainsString('siteshifter-work', $n, 'work folder leaked into the package');
        }
    }
}

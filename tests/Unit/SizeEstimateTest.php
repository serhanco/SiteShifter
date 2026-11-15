<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Package\Estimate;
use SiteShifter\Package\Scope;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\MeasureTask;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Support\SizeScan;

class SizeEstimateTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ss-size-' . bin2hex(random_bytes(4));
        foreach (['wp-content/uploads/2026/11', 'wp-content/plugins/a', 'wp-content/themes/t', 'wp-content/cache', 'wp-content/siteshifter-work/x', 'wp-includes', 'wp-content/mu-plugins'] as $d) {
            mkdir($this->root . '/' . $d, 0755, true);
        }
        $put = function ($f, $n) {
            file_put_contents($this->root . '/' . $f, str_repeat('x', $n));
        };
        $put('wp-content/uploads/2026/11/a.jpg', 1000);
        $put('wp-content/uploads/b.png', 500);
        $put('wp-content/plugins/a/a.php', 200);
        $put('wp-content/mu-plugins/m.php', 50);
        $put('wp-content/themes/t/style.css', 300);
        $put('wp-content/cache/c.html', 9999);          // excluded
        $put('wp-content/siteshifter-work/x/p.zip', 9999); // excluded by the plugin
        $put('wp-content/index.php', 10);
        $put('wp-includes/v.php', 70);
        $put('index.php', 20);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    public function testScanSplitsSizesByAreaAndSkipsExcludedFolders(): void
    {
        $r = (new SizeScan($this->root, ['wp-content/cache', 'wp-content/siteshifter-work']))->runAll();
        $this->assertTrue($r['done']);
        $this->assertSame(1500, $r['bytes']['uploads']);
        $this->assertSame(250, $r['bytes']['plugins']);
        $this->assertSame(300, $r['bytes']['themes']);
        $this->assertSame(10, $r['bytes']['content_other']);
        $this->assertSame(90, $r['bytes']['core']);
        $this->assertSame(2, $r['files']['uploads']);
        $this->assertSame(100, SizeScan::percent($r));
    }

    public function testScanIsResumableAndGivesTheSameAnswerInTinySlices(): void
    {
        $scan = new SizeScan($this->root, ['wp-content/cache', 'wp-content/siteshifter-work']);
        $state = SizeScan::initialState();
        $steps = 0;
        $last = 0;
        while (!$state['done']) {
            $state = json_decode(json_encode($scan->run($state, microtime(true) - 1)), true);
            $this->assertGreaterThanOrEqual($last, SizeScan::percent($state)); // the bar never goes backwards
            $last = SizeScan::percent($state);
            $this->assertLessThan(50, ++$steps);
        }
        $this->assertGreaterThan(5, $steps);
        $this->assertSame((new SizeScan($this->root, ['wp-content/cache', 'wp-content/siteshifter-work']))->runAll()['bytes'], $state['bytes']);
    }

    public function testMeasureTaskRunsThroughTheJobRunner(): void
    {
        $base = $this->root . '-jobs';
        $runner = new Runner(new JobStore($base), ['measure' => function () {
            return new MeasureTask($this->root, ['wp-content/cache'], static function () {
                return ['db' => 12345, 'free' => 1.0e9];
            });
        }]);
        $job = $runner->start('measure');
        for ($i = 0; $i < 60 && $job['status'] === 'running'; $i++) {
            $job = $runner->step($job['id'], microtime(true) - 1);
        }
        $this->assertSame('done', $job['status']);
        $this->assertSame(12345, $job['result']['db']);
        $this->assertSame(1500, $job['result']['bytes']['uploads']);
        (new JobStore($base))->purge(-1, PHP_INT_MAX - 10);
        foreach ((array) glob($base . '/{,.}*', GLOB_BRACE) as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($base);
    }

    private function bytes(int $uploads): array
    {
        return ['uploads' => $uploads, 'plugins' => 100, 'themes' => 100, 'content_other' => 0, 'core' => 800];
    }

    private function ids(array $rows): array
    {
        return array_column($rows, 'level', 'id');
    }

    public function testBigUploadsSuggestCopyingByHandAndRecommendNoMedia(): void
    {
        $e = Estimate::report($this->bytes(3 * 1073741824), 1000000, Scope::full(), 50.0 * 1073741824, 2 * 1073741824);
        $this->assertSame('warn', $this->ids($e['rows'])['large_uploads']);
        $this->assertSame('ok', $this->ids($e['rows'])['disk_space']);
        $this->assertTrue($e['recommend_no_media']);

        $small = Estimate::report($this->bytes(1000), 1000, Scope::full(), 1.0e12, 2 * 1073741824);
        $this->assertArrayNotHasKey('large_uploads', $this->ids($small['rows']));
        $this->assertFalse($small['recommend_no_media']);
    }

    public function testTooLittleDiskBlocksAndPointsToWithoutMediaWhenThatWouldFit(): void
    {
        $gb = 1073741824;
        $e = Estimate::report($this->bytes($gb), 1000, Scope::full(), 1000.0, 10 * $gb);
        $this->assertSame('block', $this->ids($e['rows'])['disk_space']);
        $this->assertFalse($e['recommend_no_media']); // even without media it does not fit

        $e2 = Estimate::report($this->bytes($gb), 1000, Scope::full(), 1.0 * $gb, 10 * $gb);
        $this->assertSame('block', $this->ids($e2['rows'])['disk_space']);
        $this->assertTrue($e2['recommend_no_media']);
        $fix = array_values(array_filter($e2['rows'], static function ($r) { return $r['id'] === 'disk_space'; }))[0]['fix'];
        $this->assertStringContainsString('without media', $fix);
    }

    public function testExcludedMediaIsNotCountedAndUnknownDiskIsAWarning(): void
    {
        $noMedia = Scope::fromArray(['profile' => 'custom', 'media' => false]);
        $a = Estimate::report($this->bytes(5 * 1073741824), 0, $noMedia, false, 1);
        $this->assertSame('warn', $this->ids($a['rows'])['disk_space']);
        $this->assertArrayNotHasKey('large_uploads', $this->ids($a['rows']));
        $this->assertLessThan(5000, $a['need']);
        $this->assertFalse($a['recommend_no_media']);
    }
}

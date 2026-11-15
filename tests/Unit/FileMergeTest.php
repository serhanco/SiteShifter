<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Import\FileMerge;
use SiteShifter\Plugin\Import\FileSwap;
use SiteShifter\Plugin\Import\MergePolicy;

class FileMergeTest extends TestCase
{
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-fm-' . bin2hex(random_bytes(4));
        foreach (['live/themes/t-old', 'live/themes/t-new', 'live/plugins/p', 'live/plugins/siteshifter', 'live/uploads/2026',
                  'stage/themes/t-old', 'stage/themes/t-new', 'stage/themes/t-add', 'stage/plugins/p', 'stage/plugins/siteshifter', 'stage/uploads/2026', 'stage/uploads/2027'] as $d) {
            mkdir($this->base . '/' . $d, 0755, true);
        }
        $w = function ($f, $c) {
            file_put_contents($this->base . '/' . $f, $c);
        };
        $w('live/themes/t-old/style.css', "/*\nVersion: 1.0\n*/");
        $w('stage/themes/t-old/style.css', "/*\nVersion: 2.0\n*/");
        $w('live/themes/t-new/style.css', "/*\nVersion: 3.0\n*/");
        $w('stage/themes/t-new/style.css', "/*\nVersion: 2.5\n*/");
        $w('stage/themes/t-add/style.css', "/*\nVersion: 1\n*/");
        $w('live/plugins/p/p.php', "<?php\n/* Plugin Name: P\n Version: 1.0 */");
        $w('stage/plugins/p/p.php', "<?php\n/* Plugin Name: P\n Version: 1.1 */");
        $w('stage/plugins/siteshifter/evil.php', 'x');
        $w('live/uploads/2026/a.jpg', 'live a');
        $w('stage/uploads/2026/a.jpg', 'new a');
        $w('stage/uploads/2026/b.jpg', 'new b');
        $w('stage/uploads/2027/c.jpg', 'new c');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function merge(array $policy)
    {
        return new FileMerge($this->base . '/live', $this->base . '/stage', $this->base . '/aside', new MergePolicy($policy));
    }

    private function actions(array $report)
    {
        $out = [];
        foreach ($report as $r) {
            $out[$r['kind'] . ':' . $r['item']] = $r['action'];
        }

        return $out;
    }

    public function testDefaultsKeepNewerAndNeverDeleteOrOverwriteMedia(): void
    {
        $plan = $this->merge([])->plan();
        $a = $this->actions($plan['report']);
        $this->assertSame('overwrite', $a['themes:t-old']);
        $this->assertSame('skip', $a['themes:t-new']);
        $this->assertSame('add', $a['themes:t-add']);
        $this->assertSame('overwrite', $a['plugins:p']);
        $this->assertSame('protected', $a['plugins:siteshifter']);
        $this->assertSame('skip', $a['media:uploads/2026/a.jpg']);
        $this->assertSame('add', $a['media:uploads/2026/b.jpg']);
        $this->assertSame('add', $a['media:uploads/2027/']);

        (new FileSwap('', '', ''))->apply($plan['ops']);
        $this->assertSame('live a', file_get_contents($this->base . '/live/uploads/2026/a.jpg'));
        $this->assertSame('new b', file_get_contents($this->base . '/live/uploads/2026/b.jpg'));
        $this->assertSame('new c', file_get_contents($this->base . '/live/uploads/2027/c.jpg'));
        $this->assertFileDoesNotExist($this->base . '/live/plugins/siteshifter/evil.php');
        $this->assertStringContainsString('2.0', file_get_contents($this->base . '/live/themes/t-old/style.css'));
        $this->assertStringContainsString('3.0', file_get_contents($this->base . '/live/themes/t-new/style.css'));
    }

    public function testRenamePolicyKeepsBothAndUndoRestoresEverything(): void
    {
        $plan = $this->merge(['media' => 'rename', 'themes' => 'overwrite'])->plan();
        $swap = new FileSwap('', '', '');
        $done = $swap->apply($plan['ops']);
        $this->assertSame('live a', file_get_contents($this->base . '/live/uploads/2026/a.jpg'));
        $this->assertSame('new a', file_get_contents($this->base . '/live/uploads/2026/a-2.jpg'));
        $this->assertStringContainsString('2.5', file_get_contents($this->base . '/live/themes/t-new/style.css'));
        $swap->undo($done);
        $this->assertFileDoesNotExist($this->base . '/live/uploads/2026/a-2.jpg');
        $this->assertFileDoesNotExist($this->base . '/live/uploads/2026/b.jpg');
        $this->assertStringContainsString('3.0', file_get_contents($this->base . '/live/themes/t-new/style.css'));
        $this->assertStringContainsString('1.0', file_get_contents($this->base . '/live/themes/t-old/style.css'));
    }

    public function testUnknownChoicesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MergePolicy(['media' => 'delete_everything']);
    }
}

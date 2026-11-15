<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Installer\Installer;
use SiteShifter\Package\PackageBuilder;
use SiteShifter\Support\Progress;

class ProgressTest extends TestCase
{
    private function state(string $step, array $extra = []): array
    {
        return array_replace_recursive([
            'step' => $step,
            'extract' => ['index' => 0, 'total' => null, 'done' => false],
            'import' => ['offset' => 0, 'size' => 0, 'done' => false],
            'replace' => ['rows_done' => 0, 'total_rows' => null, 'done' => false],
        ], $extra);
    }

    public function testStepAndOverallPercentagesMoveForward(): void
    {
        $seen = [];
        $states = [
            $this->state('backup'),
            $this->state('extract', ['extract' => ['index' => 500, 'total' => 1000]]),
            $this->state('import', ['import' => ['offset' => 0, 'size' => 4 * 1048576]]),
            $this->state('import', ['import' => ['offset' => 3 * 1048576, 'size' => 4 * 1048576]]),
            $this->state('replace', ['replace' => ['rows_done' => 250, 'total_rows' => 1000]]),
            $this->state('verify', ['verify' => ['index' => 3, 'total' => 12]]),
            $this->state('done'),
        ];
        foreach ($states as $s) {
            $seen[] = Installer::progress($s);
        }
        $overall = array_column($seen, 1);
        $sorted = $overall;
        sort($sorted);
        $this->assertSame($sorted, $overall, 'overall % never goes backwards');
        $this->assertSame([0, 50, 0, 75, 25, 25, 100], array_column($seen, 2));
        $this->assertSame('500 / 1,000 files', $seen[1][3]);
        $this->assertSame('3 MB / 4 MB', $seen[3][3]);
        $this->assertSame('250 / ~1,000 rows', $seen[4][3]);
        $this->assertSame(100, $seen[6][1]);
    }

    public function testPercentNeverClaims100BeforeFinished(): void
    {
        $this->assertSame(99, Progress::percent(1200, 1000));
        $this->assertSame(100, Progress::percent(10, 1000, true));
        $this->assertSame(0, Progress::percent(5, 0));
    }

    public function testPackageProgressSplitsDumpAndFiles(): void
    {
        $dump = PackageBuilder::progress(['done' => false, 'phase' => 'dump', 'dump' => ['rows_done' => 50, 'total_rows' => 100, 'done' => false], 'archive' => []]);
        $this->assertSame(['phase' => 'dump', 'percent' => 15, 'phase_percent' => 50], $dump);
        $files = PackageBuilder::progress(['done' => false, 'phase' => 'files', 'dump' => [], 'archive' => ['added_bytes' => 50, 'bytes' => 100, 'done' => false]]);
        $this->assertSame(['phase' => 'files', 'percent' => 65, 'phase_percent' => 50], $files);
    }
}

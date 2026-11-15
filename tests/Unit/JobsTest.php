<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\Budget;
use SiteShifter\Plugin\Jobs\HostTestTask;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Jobs\Task;

class JobsTest extends TestCase
{
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-jobs-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            (new JobStore($this->base))->purge(-1, PHP_INT_MAX - 10);
            foreach ((array) glob($this->base . '/{,.}*', GLOB_BRACE) as $f) {
                is_file($f) && @unlink($f);
            }
            @rmdir($this->base);
        }
    }

    private function runner(array $extra = []): Runner
    {
        return new Runner(new JobStore($this->base), $extra + [
            'host_test' => static function ($job, $dir) {
                return new HostTestTask($dir);
            },
        ]);
    }

    public function testBudgetStaysUnderTheHostLimit(): void
    {
        $this->assertEqualsWithDelta(18.0, Budget::deadline(100.0, 30) - 100.0, 0.001);   // 60% of 30
        $this->assertEqualsWithDelta(20.0, Budget::deadline(100.0, 0) - 100.0, 0.001);    // unlimited -> cap
        $this->assertEqualsWithDelta(20.0, Budget::deadline(100.0, 300) - 100.0, 0.001);  // cap
        $this->assertEqualsWithDelta(8.0, Budget::deadline(100.0, 30, 90.0) - 100.0, 0.001); // 10 s of 18 already used
    }

    public function testBudgetNeverGoesBelowOneSecond(): void
    {
        $this->assertEqualsWithDelta(1.0, Budget::deadline(100.0, 10, 50.0) - 100.0, 0.001);
    }

    public function testMemoryParsing(): void
    {
        $this->assertSame(134217728, Budget::bytes('128M'));
        $this->assertSame(1073741824, Budget::bytes('1G'));
        $this->assertSame(0, Budget::bytes('-1'));
        $this->assertTrue(Budget::memoryTight(90, 100));
        $this->assertFalse(Budget::memoryTight(50, 100));
        $this->assertFalse(Budget::memoryTight(500, 0));
    }

    public function testWorkFolderIsGuardedAndJobIdsAreRandom(): void
    {
        $store = new JobStore($this->base);
        $job = $store->create('host_test', [], []);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $job['id']);
        $this->assertFileExists($this->base . '/.htaccess');
        $this->assertFileExists($this->base . '/web.config');
        $this->assertFileExists($this->base . '/index.php');
        $this->expectException(\InvalidArgumentException::class);
        $store->dir('../../etc');
    }

    public function testJobRunsAcrossManySlicesAndFinishesAt100(): void
    {
        $runner = $this->runner();
        $view = $runner->start('host_test', ['mb' => 5]);
        $this->assertSame('running', $view['status']);
        $seen = [];
        for ($i = 0; $i < 50 && $view['status'] === 'running'; $i++) {
            $view = $runner->step($view['id'], microtime(true) - 1); // deadline already past: one piece per slice
            $seen[] = $view['percent'];
        }
        $this->assertSame('done', $view['status']);
        $this->assertSame(100, $view['percent']);
        $this->assertGreaterThan(1, count($seen), 'must take several slices');
        $this->assertSame($seen, array_values(array_filter($seen, static function ($p) {
            return $p >= 0 && $p <= 100;
        })));
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen, 'percent never goes backwards');
        $this->assertLessThan(100, $seen[0], '100 only when really finished');
    }

    public function testKilledSliceLeavesNoDuplicateData(): void
    {
        // A task that writes, then "dies" (throws) after the write on its first attempt.
        $task = new class implements Task {
            public static $failOnce = true;
            public $file;

            public function initialState(array $p)
            {
                return ['n' => 0, 'done' => false];
            }

            public function run(array $s, $deadline)
            {
                file_put_contents($this->file, "line\n", FILE_APPEND);
                if (self::$failOnce) {
                    self::$failOnce = false;
                    throw new \RuntimeException('simulated crash after partial write');
                }
                $s['n']++;
                $s['done'] = $s['n'] >= 3;

                return $s;
            }

            public function progress(array $s)
            {
                return ['percent' => $s['done'] ? 100 : 10 * $s['n'], 'phase' => 'x'];
            }

            public function checkpointFiles(array $s)
            {
                return [$this->file];
            }
        };
        $runner = $this->runner(['crashy' => static function ($job, $dir) use ($task) {
            $task->file = $dir . '/out.txt';

            return $task;
        }]);
        $view = $runner->start('crashy');
        for ($i = 0; $i < 10 && $view['status'] === 'running'; $i++) {
            $view = $runner->step($view['id'], microtime(true) + 5);
        }
        $this->assertSame('done', $view['status']);
        $store = new JobStore($this->base);
        $this->assertSame("line\nline\nline\n", file_get_contents($store->dir($view['id']) . '/out.txt'));
    }

    public function testRepeatedFailuresPauseAndResumeKeepsProgress(): void
    {
        $fail = new class implements Task {
            public $broken = true;

            public function initialState(array $p)
            {
                return ['n' => 0, 'done' => false];
            }

            public function run(array $s, $d)
            {
                if ($this->broken) {
                    throw new \RuntimeException('disk full');
                }
                $s['done'] = true;

                return $s;
            }

            public function progress(array $s)
            {
                return ['percent' => 0, 'phase' => 'x'];
            }

            public function checkpointFiles(array $s)
            {
                return [];
            }
        };
        $runner = $this->runner(['flaky' => static function () use ($fail) {
            return $fail;
        }]);
        $view = $runner->start('flaky');
        for ($i = 0; $i < Runner::MAX_ERRORS; $i++) {
            $view = $runner->step($view['id'], microtime(true) + 5);
        }
        $this->assertSame('paused', $view['status']);
        $this->assertSame('disk full', $view['error']);
        $fail->broken = false;
        $view = $runner->resume($view['id']);
        $this->assertSame('running', $view['status']);
        $view = $runner->step($view['id'], microtime(true) + 5);
        $this->assertSame('done', $view['status']);
    }

    public function testSecondRequestWaitsWhileAnotherHoldsTheLock(): void
    {
        $runner = $this->runner();
        $view = $runner->start('host_test', ['mb' => 2]);
        $lock = (new JobStore($this->base))->lock($view['id']);
        $this->assertTrue($lock->acquire());
        $busy = $runner->step($view['id'], microtime(true) + 5);
        $this->assertTrue($busy['busy']);
        $this->assertSame(0, $busy['percent']);
        $lock->release();
        $after = $runner->step($view['id'], microtime(true) + 5);
        $this->assertArrayNotHasKey('busy', $after);
    }

    public function testCancelStopsAJob(): void
    {
        $runner = $this->runner();
        $view = $runner->start('host_test', ['mb' => 3]);
        $this->assertSame('cancelled', $runner->cancel($view['id'])['status']);
        $this->assertSame('cancelled', $runner->step($view['id'], microtime(true) + 5)['status']);
    }

    public function testBrowserViewHidesInternals(): void
    {
        $view = $this->runner()->start('host_test', ['mb' => 1]);
        $this->assertSame(['id', 'type', 'status', 'percent', 'phase', 'detail', 'result', 'error', 'code', 'retryable', 'errors', 'created', 'updated'], array_keys($view));
    }

    private function failingTask(string $message, int $code = 0): Task
    {
        return new class($message, $code) implements Task {
            private $m;
            private $c;

            public function __construct($m, $c)
            {
                $this->m = $m;
                $this->c = $c;
            }

            public function initialState(array $params)
            {
                return ['done' => false];
            }

            public function run(array $state, $deadline)
            {
                throw new \RuntimeException($this->m, $this->c);
            }

            public function progress(array $state)
            {
                return ['percent' => 0, 'phase' => 'x'];
            }

            public function checkpointFiles(array $state)
            {
                return [];
            }
        };
    }

    public function testAPermanentProblemPausesAtOnceWithItsCode(): void
    {
        $runner = $this->runner(['fail' => function () {
            return $this->failingTask('Cannot write /x/database.sql — check folder permissions.');
        }]);
        $job = $runner->start('fail');
        $view = $runner->step($job['id'], microtime(true) + 5);

        $this->assertSame('paused', $view['status']);
        $this->assertSame(1, $view['errors']);
        $this->assertSame('SS_E_PERM_WRITE', $view['code']);
        $this->assertFalse($view['retryable']);
    }

    public function testATemporaryProblemIsRetriedBeforePausing(): void
    {
        $runner = $this->runner(['fail' => function () {
            return $this->failingTask('Database error: Deadlock found', 1213);
        }]);
        $job = $runner->start('fail');
        $first = $runner->step($job['id'], microtime(true) + 5);
        $this->assertSame('running', $first['status']);
        $this->assertSame('SS_E_DB_LOCK', $first['code']);
        $this->assertTrue($first['retryable']);
        $runner->step($job['id'], microtime(true) + 5);
        $this->assertSame('paused', $runner->step($job['id'], microtime(true) + 5)['status']);
    }
}

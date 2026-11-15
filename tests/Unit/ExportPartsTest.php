<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\PackageCheck;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Jobs\Task;
use SiteShifter\Plugin\Rest\Download;

class ExportPartsTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ss-exp-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    private function zip(array $entries): string
    {
        $path = $this->tmp . '/p.zip';
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $z->addFromString($name, $content);
        }
        $z->close();

        return $path;
    }

    private function manifest(int $files, array $tables): string
    {
        return json_encode(['db' => ['tables' => array_fill_keys($tables, 1)], 'files' => ['count' => $files]]);
    }

    public function testCompletePackagePasses(): void
    {
        $sql = "DROP TABLE IF EXISTS `wp_a`;\nCREATE TABLE `wp_a` (id int);\nDROP TABLE IF EXISTS `wp_b`;\nCREATE TABLE `wp_b` (id int);\n";
        $r = PackageCheck::run($this->zip(['files/a.txt' => 'a', 'files/b/c.txt' => 'c', 'database.sql' => $sql, 'siteshifter.json' => $this->manifest(2, ['wp_a', 'wp_b'])]));
        $this->assertTrue($r['ok'], implode(' ', $r['problems']));
        $this->assertSame(2, $r['files']);
        $this->assertSame(2, $r['tables']);
    }

    public function testMissingFileCountTableAndSqlAreAllCaught(): void
    {
        $sql = "CREATE TABLE `wp_a` (id int);\n";
        $r = PackageCheck::run($this->zip(['files/a.txt' => 'a', 'database.sql' => $sql, 'siteshifter.json' => $this->manifest(5, ['wp_a', 'wp_b'])]));
        $this->assertFalse($r['ok']);
        $this->assertCount(2, $r['problems']);
        $this->assertStringContainsString('1 files but 5', $r['problems'][0]);
        $this->assertStringContainsString('wp_b', $r['problems'][1]);

        $this->assertFalse(PackageCheck::run($this->zip(['siteshifter.json' => $this->manifest(0, ['wp_a'])]))['ok'], 'no sql');
        $this->assertFalse(PackageCheck::run($this->zip(['database.sql' => 'x']))['ok'], 'no manifest');
        $this->assertFalse(PackageCheck::run($this->tmp . '/nope.zip')['ok']);
        file_put_contents($this->tmp . '/bad.zip', 'not a zip');
        $this->assertFalse(PackageCheck::run($this->tmp . '/bad.zip')['ok']);
    }

    public function testTableNameStraddlingAChunkBoundaryIsStillSeen(): void
    {
        $filler = str_repeat("INSERT INTO `wp_a` VALUES (1);\n", 40000); // > 1 MB
        $sql = $filler . "CREATE TABLE `wp_z` (id int);\n";
        $r = PackageCheck::run($this->zip(['database.sql' => $sql, 'siteshifter.json' => $this->manifest(0, ['wp_z'])]));
        $this->assertSame(1, $r['tables']);
        $this->assertTrue($r['ok']);
    }

    public function testDownloadTokenRules(): void
    {
        $job = ['status' => 'done', 'type' => 'export', 'download' => ['token' => 'abc', 'user' => 7, 'expires' => 1000]];
        $this->assertSame('', Download::check($job, 'abc', 7, 999));
        $this->assertNotSame('', Download::check($job, 'abd', 7, 999), 'wrong token');
        $this->assertNotSame('', Download::check($job, 'abc', 8, 999), 'other user');
        $this->assertNotSame('', Download::check($job, 'abc', 7, 1001), 'expired');
        $this->assertNotSame('', Download::check(['status' => 'running'] + $job, 'abc', 7, 999));
        $this->assertNotSame('', Download::check(['type' => 'rollback'] + $job, 'abc', 7, 999));
        unset($job['download']);
        $this->assertNotSame('', Download::check($job, 'abc', 7, 999), 'never issued');
        $this->assertSame('siteshifter-example.com-20261115-143000.zip', Download::filename('https://example.com/blog', gmmktime(14, 30, 0, 11, 15, 2026)));
    }

    public function testRunnerIssuesAndHonoursDownloads(): void
    {
        $store = new JobStore($this->tmp . '/work');
        $task = new class implements Task {
            public function initialState(array $p)
            {
                return ['done' => true];
            }

            public function run(array $s, $d)
            {
                return $s;
            }

            public function progress(array $s)
            {
                return ['percent' => 100, 'phase' => 'done'];
            }

            public function checkpointFiles(array $s)
            {
                return [];
            }
        };
        $runner = new Runner($store, ['export' => static function () use ($task) {
            return $task;
        }]);
        $view = $runner->start('export');
        $this->assertSame('running', $view['status']);
        $this->assertSame('done', $runner->step($view['id'], microtime(true) + 5)['status']);

        $file = $store->dir($view['id']) . '/package.zip';
        file_put_contents($file, 'zipdata');
        $d = $runner->issueDownload($view['id'], 7, 1000);
        $ok = $runner->downloadFile($view['id'], $d['token'], 7, 1001);
        $this->assertSame($file, $ok['file']);
        $this->assertIsString($runner->downloadFile($view['id'], $d['token'], 8, 1001));
        $this->assertIsString($runner->downloadFile($view['id'], $d['token'], 7, 1000 + Download::TTL + 1));
        $this->assertIsString($runner->downloadFile('../../etc/passwd', 'x', 7));

        $runner->delete($view['id']);
        $this->assertFileDoesNotExist($file);
    }
}

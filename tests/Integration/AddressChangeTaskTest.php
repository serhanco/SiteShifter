<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Plugin\Jobs\AddressChangeTask;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\RollbackTask;
use SiteShifter\Plugin\Jobs\Runner;

class AddressChangeTaskTest extends DatabaseTestCase
{
    private $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/ss-addr-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->base && is_dir($this->base)) {
            $store = new JobStore($this->base);
            foreach ($store->all() as $j) {
                $store->delete($j['id']);
            }
            foreach ((array) glob($this->base . '/{,.}*', GLOB_BRACE) as $f) {
                is_file($f) && @unlink($f);
            }
            @rmdir($this->base);
        }
        parent::tearDown();
    }

    private function seed(): void
    {
        $this->createWordPressTables();
        $q = function ($v) { return $this->db->quote($v); };
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES
            ('siteurl', 'http://old.test'), ('home', 'http://old.test'),
            ('widget', " . $q(serialize(['t' => '<a href="http://old.test/x">x</a>'])) . ")");
        for ($i = 1; $i <= 30; $i++) {
            $this->db->exec("INSERT INTO wp_posts (post_content, post_title, guid) VALUES (" . $q("<img src=\"http://old.test/u/{$i}.jpg\">") . ", 'P{$i}', 'http://old.test/?p={$i}')");
        }
    }

    private function runner(): Runner
    {
        $db = $this->db;
        $connect = static function () use ($db) {
            return $db;
        };

        return new Runner(new JobStore($this->base), [
            'address_change' => static function ($job, $dir) use ($connect) {
                return new AddressChangeTask($dir, 'wp_', $connect);
            },
            'rollback' => function ($job, $dir) use ($connect) {
                return new RollbackTask($this->base . '/' . $job['params']['job'] . '/backup.sql', $connect);
            },
        ]);
    }

    public function testChangesAddressInManySlicesThenRollsBack(): void
    {
        $this->seed();
        $runner = $this->runner();
        $view = $runner->start('address_change', ['old_url' => 'http://old.test', 'new_url' => 'https://new.com']);
        $slices = 0;
        $last = -1;
        while ($view['status'] === 'running' && $slices < 400) {
            $view = $runner->step($view['id'], microtime(true) - 1); // one unit of work per slice
            $this->assertGreaterThanOrEqual($last, $view['percent']);
            $last = $view['percent'];
            $slices++;
        }
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertGreaterThan(3, $slices);
        $this->assertSame('https://new.com', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='home'"));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%old.test%'"));
        $this->assertSame('http://old.test/?p=1', $this->db->value('SELECT guid FROM wp_posts WHERE ID = 1'), 'guid must stay');
        $this->assertGreaterThan(0, $view['result']['rows_changed']);

        // Roll back to the pre-change backup.
        $rb = $runner->start('rollback', ['job' => $view['id']]);
        for ($i = 0; $i < 400 && $rb['status'] === 'running'; $i++) {
            $rb = $runner->step($rb['id'], microtime(true) - 1);
        }
        $this->assertSame('done', $rb['status'], (string) $rb['error']);
        $this->assertSame('http://old.test', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='home'"));
        $this->assertSame(30, (int) $this->db->value("SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%http://old.test/u/%'"));
    }
}

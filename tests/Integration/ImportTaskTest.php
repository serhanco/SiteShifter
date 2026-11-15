<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Package\PackageBuilder;
use SiteShifter\Plugin\Jobs\ExportTask;
use SiteShifter\Plugin\Jobs\ImportActions;
use SiteShifter\Plugin\Jobs\ImportTask;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;

/** Replace-mode import end to end: staging, swap, undo, discard (source prefix src_, target prefix wp_). */
class ImportTaskTest extends DatabaseTestCase
{
    private $src;
    private $dst;
    private $zip;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/ss-imp-' . bin2hex(random_bytes(4));
        $this->src = $base . '/src';
        $this->dst = $base . '/dst';
        foreach ([$this->src . '/wp-content/uploads/2026', $this->src . '/wp-content/themes/new', $this->src . '/wp-includes', $this->src . '/wp-content/plugins/other',
                  $this->dst . '/wp-content/uploads/2020', $this->dst . '/wp-content/themes/old', $this->dst . '/wp-content/plugins/siteshifter', $this->dst . '/wp-content/plugins/mine'] as $d) {
            mkdir($d, 0755, true);
        }
        file_put_contents($this->src . '/wp-includes/version.php', "<?php\n\$wp_version = '6.8';\n\$wp_db_version = 60000;\n");
        file_put_contents($this->src . '/wp-config.php', "<?php\n");
        file_put_contents($this->src . '/wp-content/uploads/2026/new.txt', 'new upload');
        file_put_contents($this->src . '/wp-content/themes/new/style.css', 'new theme');
        file_put_contents($this->src . '/wp-content/plugins/other/o.php', '<?php // other');
        mkdir($this->src . '/wp-content/plugins/siteshifter', 0755, true);
        file_put_contents($this->src . '/wp-content/plugins/siteshifter/evil.php', 'must never be copied');
        file_put_contents($this->dst . '/wp-content/uploads/2020/old.txt', 'old upload');
        file_put_contents($this->dst . '/wp-content/themes/old/style.css', 'old theme');
        file_put_contents($this->dst . '/wp-content/plugins/siteshifter/main.php', 'the running plugin');
        file_put_contents($this->dst . '/wp-content/plugins/mine/m.php', 'local plugin');

        // source site, prefix src_
        $this->createWordPressTables('src_');
        $this->db->exec('CREATE TABLE src_users (ID bigint unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, user_email varchar(100) NOT NULL, PRIMARY KEY (ID))');
        $this->db->exec('CREATE TABLE src_usermeta (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext, PRIMARY KEY (umeta_id))');
        $this->db->exec("INSERT INTO src_options (option_name, option_value) VALUES ('siteurl','http://old.test'),('home','http://old.test'),('active_plugins','a:1:{i:0;s:15:\"other/other.php\";}'),('src_user_roles','a:0:{}'),('_transient_x','1')");
        $this->db->exec("INSERT INTO src_posts (post_content, post_title, guid) VALUES ('see http://old.test/a', 'New post', 'http://old.test/?p=1')");
        $this->db->exec("INSERT INTO src_users (user_login, user_email) VALUES ('newadmin','n@x.test')");
        $this->db->exec("INSERT INTO src_usermeta (user_id, meta_key, meta_value) VALUES (1,'src_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(1,'nickname','n')");
        $db = $this->db;
        $src = $this->src;
        $dir = $base . '/pkg';
        mkdir($dir);
        $store = new JobStore($base . '/exportwork');
        $runner = new Runner($store, ['export' => static function ($job, $d) use ($db, $src) {
            return new ExportTask($d, static function (array $o) use ($db, $src, $d) {
                return new PackageBuilder($db, $src, 'src_', $d, $d . '/package.zip', $o);
            });
        }]);
        $v = $runner->start('export', []);
        for ($i = 0; $v['status'] === 'running' && $i < 300; $i++) {
            $v = $runner->step($v['id'], microtime(true) - 1);
        }
        $this->assertSame('done', $v['status'], (string) $v['error']);
        $this->zip = $store->dir($v['id']) . '/package.zip';
        $this->assertFileExists($this->zip);

        // target site, prefix wp_
        $this->createWordPressTables('wp_');
        $this->db->exec('CREATE TABLE wp_users (ID bigint unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, user_email varchar(100) NOT NULL, PRIMARY KEY (ID))');
        $this->db->exec('CREATE TABLE wp_usermeta (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext, PRIMARY KEY (umeta_id))');
        $this->db->exec('CREATE TABLE wp_localonly (id int)');
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://new.test'),('home','https://new.test'),('active_plugins','a:0:{}')");
        $this->db->exec("INSERT INTO wp_posts (post_content, post_title) VALUES ('x', 'Old post')");
        $this->db->exec("INSERT INTO wp_users (user_login, user_email) VALUES ('oldadmin','o@x.test')");
        $this->base = $base;
    }

    private $base;

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
        parent::tearDown();
    }

    private function runner(&$store, $zip = null)
    {
        $db = $this->db;
        $dst = $this->dst;
        $zip = $zip ?: $this->zip;
        $store = new JobStore($dst . '/wp-content/siteshifter-work');

        return new Runner($store, ['import' => static function ($job, $dir) use ($db, $dst, $zip) {
            return new ImportTask($dir, [
                'zip' => $zip, 'content_dir' => $dst . '/wp-content', 'abspath' => $dst, 'home' => 'https://new.test', 'siteurl' => 'https://new.test',
                'prefix' => 'wp_', 'plugin_file' => 'siteshifter/siteshifter.php',
                'connect' => static function () use ($db) {
                    return $db;
                },
                'inspect' => static function () {
                    return ['ok' => true, 'blockers' => false, 'rows' => []];
                },
            ]);
        }]);
    }

    private function finish(Runner $runner, array $view)
    {
        for ($i = 0; $view['status'] === 'running' && $i < 400; $i++) {
            $view = $runner->step($view['id'], microtime(true) - 1);
        }

        return $view;
    }

    public function testReplaceImportSwapsUndoesAndDiscards(): void
    {
        $runner = $this->runner($store);
        $view = $this->finish($runner, $runner->start('import', ['package' => 'x']));
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertTrue($view['result']['swapped']);
        $this->assertSame([], $view['result']['leftovers']);
        $this->assertSame([], $view['result']['row_mismatches']);

        // live now = the imported site, under the target prefix
        $this->assertSame('New post', $this->db->value("SELECT post_title FROM wp_posts"));
        $this->assertSame('see https://new.test/a', $this->db->value("SELECT post_content FROM wp_posts"));
        $this->assertSame('newadmin', $this->db->value('SELECT user_login FROM wp_users'));
        $this->assertSame('1', $this->db->value("SELECT COUNT(*) FROM wp_options WHERE option_name = 'wp_user_roles'"));
        $this->assertSame('0', $this->db->value("SELECT COUNT(*) FROM wp_options WHERE option_name = 'src_user_roles' OR option_name LIKE '\\_transient%'"));
        $this->assertSame('wp_capabilities', $this->db->value("SELECT meta_key FROM wp_usermeta WHERE meta_key LIKE '%capabilities'"));
        $this->assertSame('https://new.test', $this->db->value("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'"));
        $plugins = unserialize($this->db->value("SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'"));
        $this->assertContains('siteshifter/siteshifter.php', $plugins);
        $this->assertContains('other/other.php', $plugins);
        // a full package replaces tables it does not contain too (wp_localonly is backed up, not live)
        $this->assertNotContains('wp_localonly', $this->db->tables('wp_'));

        $c = $this->dst . '/wp-content';
        $this->assertFileExists($c . '/uploads/2026/new.txt');
        $this->assertFileDoesNotExist($c . '/uploads/2020/old.txt');
        $this->assertFileExists($c . '/themes/new/style.css');
        $this->assertFileDoesNotExist($c . '/themes/old/style.css');
        $this->assertFileExists($c . '/plugins/other/o.php');
        $this->assertSame('the running plugin', file_get_contents($c . '/plugins/siteshifter/main.php'));
        $this->assertFileDoesNotExist($c . '/plugins/siteshifter/evil.php');
        $this->assertFileDoesNotExist($c . '/plugins/mine/m.php');

        // the job state cannot be deleted while the backup is held
        try {
            $store->delete($view['id']);
            $this->fail('delete must be refused');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        }

        $actions = new ImportActions($store, function () {
            return $this->db;
        });
        $this->assertCount(1, $actions->all());
        $this->assertTrue($actions->undo($view['id'])['undone']);
        $this->assertSame('Old post', $this->db->value('SELECT post_title FROM wp_posts'));
        $this->assertSame('oldadmin', $this->db->value('SELECT user_login FROM wp_users'));
        $this->assertContains('wp_localonly', $this->db->tables('wp_'));
        $this->assertSame([], $this->db->tables('ssi' . substr($view['id'], 0, 6) . '_'));
        $this->assertFileExists($c . '/uploads/2020/old.txt');
        $this->assertFileExists($c . '/themes/old/style.css');
        $this->assertFileExists($c . '/plugins/mine/m.php');
        $this->assertFileDoesNotExist($c . '/uploads/2026/new.txt');

        $this->assertTrue($actions->discard($view['id'])['discarded']);
        $this->assertSame([], $this->db->tables('ssb'));
        $store->delete($view['id']);
    }

    public function testBackupsStayUntilDiscardAndDiscardDropsThem(): void
    {
        $runner = $this->runner($store);
        $view = $this->finish($runner, $runner->start('import', ['package' => 'x']));
        $this->assertSame('done', $view['status']);
        $tag = substr($view['id'], 0, 6);
        $this->assertNotEmpty($this->db->tables('ssb' . $tag . '_'));
        $actions = new ImportActions($store, function () {
            return $this->db;
        });
        $actions->discard($view['id']);
        $this->assertSame([], $this->db->tables('ssb' . $tag . '_'));
        $this->assertDirectoryDoesNotExist($store->dir($view['id']) . '/aside');
        $this->expectException(\InvalidArgumentException::class);
        $actions->undo($view['id']);
    }

    public function testBlockedPackageIsRefusedBeforeAnythingChanges(): void
    {
        $db = $this->db;
        $store = new JobStore($this->dst . '/wp-content/siteshifter-work');
        $runner = new Runner($store, ['import' => function ($job, $dir) use ($db) {
            return new ImportTask($dir, ['zip' => $this->zip, 'content_dir' => $this->dst . '/wp-content', 'abspath' => $this->dst, 'home' => 'https://new.test', 'siteurl' => 'https://new.test',
                'prefix' => 'wp_', 'plugin_file' => 'siteshifter/siteshifter.php', 'connect' => function () use ($db) {
                    return $db;
                }, 'inspect' => function () {
                    return ['ok' => true, 'blockers' => true, 'rows' => [['level' => 'block', 'title' => 'Multisite', 'message' => 'not supported']]];
                }]);
        }]);
        try {
            $runner->start('import', ['package' => 'x']);
            $this->fail('must refuse');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Multisite', $e->getMessage());
        }
        $this->assertSame('Old post', $this->db->value('SELECT post_title FROM wp_posts'));
    }

    public function testAnInterruptedImportSliceRestartsTheStagingImport(): void
    {
        $runner = $this->runner($store);
        $view = $runner->start('import', ['package' => 'x']);
        // leftovers of a slice that died: marker plus a half-filled staging table
        $tag = substr($view['id'], 0, 6);
        $this->db->exec("CREATE TABLE ssi{$tag}_posts (junk int)");
        file_put_contents($store->dir($view['id']) . '/import.running', '1');
        $view = $this->finish($runner, $view);
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertSame('New post', $this->db->value('SELECT post_title FROM wp_posts'));
    }

    public function testMergeModeKeepsWhatIsHereAddsWhatIsNewAndUndoes(): void
    {
        $runner = $this->runner($store);
        $view = $this->finish($runner, $runner->start('import', ['package' => 'x', 'mode' => 'merge', 'policy' => ['themes' => 'overwrite']]));
        $this->assertSame('done', $view['status'], (string) $view['error']);
        $this->assertSame('merge', $view['result']['mode']);
        $this->assertFalse($view['result']['merge']['content_merged']);
        $c = $this->dst . '/wp-content';

        // files: nothing deleted, new things added
        $this->assertFileExists($c . '/uploads/2020/old.txt');
        $this->assertFileExists($c . '/uploads/2026/new.txt');
        $this->assertFileExists($c . '/themes/old/style.css');
        $this->assertFileExists($c . '/themes/new/style.css');
        $this->assertFileExists($c . '/plugins/mine/m.php');
        $this->assertFileExists($c . '/plugins/other/o.php');
        $this->assertFileDoesNotExist($c . '/plugins/siteshifter/evil.php');
        // database: own posts and users stay, package users are added, address stays
        $this->assertSame('Old post', $this->db->value('SELECT post_title FROM wp_posts'));
        $this->assertSame('2', $this->db->value('SELECT COUNT(*) FROM wp_users'));
        $this->assertSame('https://new.test', $this->db->value("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'"));
        $this->assertSame('wp_capabilities', $this->db->value("SELECT meta_key FROM wp_usermeta WHERE meta_key LIKE '%capabilities'"));

        $actions = new ImportActions($store, function () {
            return $this->db;
        });
        $this->assertTrue($actions->undo($view['id'])['undone']);
        $this->assertSame('1', $this->db->value('SELECT COUNT(*) FROM wp_users'));
        $this->assertFileDoesNotExist($c . '/uploads/2026/new.txt');
        $this->assertFileDoesNotExist($c . '/themes/new/style.css');
        $this->assertFileExists($c . '/uploads/2020/old.txt');
        $this->assertSame([], $this->db->tables('ssb'));
        $this->assertSame([], $this->db->tables('ssi'));
    }
}

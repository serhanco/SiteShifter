<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Database\Connection;
use SiteShifter\Database\Dumper;
use SiteShifter\Database\Importer;
use SiteShifter\Database\SqlInspector;
use SiteShifter\Database\Verifier;
use SiteShifter\Preflight\Preflight;

class DumpImportTest extends DatabaseTestCase
{
    /** @var Connection|null */
    private $target;

    private function targetDb(): Connection
    {
        $name = $this->dbName . '_t';
        $this->db->exec('DROP DATABASE IF EXISTS ' . Connection::ident($name));
        $this->db->exec('CREATE DATABASE ' . Connection::ident($name) . ' CHARACTER SET utf8mb4');

        return $this->target = new Connection(getenv('SS_DB_HOST') ?: 'localhost', getenv('SS_DB_USER') ?: 'ss', getenv('SS_DB_PASS') ?: 'ss', $name);
    }

    protected function tearDown(): void
    {
        if ($this->target) {
            $this->target->exec('DROP DATABASE IF EXISTS ' . Connection::ident($this->target->name()));
        }
        parent::tearDown();
    }

    private function seed(): void
    {
        $this->createWordPressTables();
        $this->db->exec('CREATE TABLE wp_bin (id int PRIMARY KEY, data longblob, n int NULL)');
        $q = function ($v) { return $this->db->quote($v); };
        $this->db->exec("INSERT INTO wp_bin VALUES (1, " . $q("\x00\xff\x01'\";\\") . ", NULL), (2, '', 5)");
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://old.test'), ('home', 'http://old.test'),"
            . " ('active_plugins', " . $q(serialize(['elementor-pro/elementor-pro.php', 'akismet/akismet.php'])) . "), ('template', 'hello-elementor'), ('stylesheet', 'hello-child')");
        for ($i = 1; $i <= 120; $i++) {
            $this->db->exec("INSERT INTO wp_posts (post_content, post_title) VALUES (" . $q(str_repeat("Çok satırlı;\n'içerik' -- {$i} ", 20)) . ", 'P{$i}')");
        }
    }

    public function testDumpAndImportRoundTripInSmallSteps(): void
    {
        $this->seed();
        $tables = $this->db->tables('wp_');
        $file = tempnam(sys_get_temp_dir(), 'dump');

        $dumper = new Dumper($this->db, $tables, $file, 17);
        $state = Dumper::initialState();
        $steps = 0;
        while (!$state['done']) {
            $state = json_decode(json_encode($dumper->run($state, microtime(true) - 1)), true);
            $steps++;
        }
        $this->assertGreaterThan(5, $steps);
        $this->assertSame(120, $state['rows']['wp_posts']);

        $target = $this->targetDb();
        $importer = new Importer($target, $file);
        $state = Importer::initialState();
        $steps = 0;
        while (!$state['done']) {
            $state = json_decode(json_encode($importer->run($state, microtime(true) - 1)), true);
            $steps++;
        }
        $this->assertGreaterThan(5, $steps);

        $v = new Verifier($this->db);
        $this->assertSame([], Verifier::compareRowCounts($v->rowCounts($tables), (new Verifier($target))->rowCounts($target->tables('wp_'))));
        $this->assertSame($this->db->value('SELECT data FROM wp_bin WHERE id=1'), $target->value('SELECT data FROM wp_bin WHERE id=1'));
        $this->assertNull($target->value('SELECT n FROM wp_bin WHERE id=1'));
        $this->assertSame(
            $this->db->value('SELECT MD5(GROUP_CONCAT(post_content ORDER BY ID)) FROM wp_posts'),
            $target->value('SELECT MD5(GROUP_CONCAT(post_content ORDER BY ID)) FROM wp_posts')
        );
    }

    public function testImportsPhpMyAdminDumpFromMySql8IntoMariaDb(): void
    {
        $sql = <<<'SQL'
-- phpMyAdmin SQL Dump
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
CREATE TABLE `xyz_options` (
  `option_id` bigint UNSIGNED NOT NULL,
  `option_name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `option_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `xyz_options` (`option_id`, `option_name`, `option_value`) VALUES
(1, 'siteurl', 'https://test.example.com'),
(2, 'home', 'https://test.example.com'),
(3, 'active_plugins', 'a:2:{i:0;s:34:\"sitepress-multilingual-cms/sitepress.php\";i:1;s:19:\"akismet/akismet.php\";}'),
(4, 'template', 'Avada');
CREATE TABLE `xyz_posts` (`ID` bigint NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `xyz_postmeta` (`meta_id` bigint NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `xyz_posts` VALUES (1),(2),(3);
CREATE TABLE `crm_contacts` (`id` int) ENGINE=InnoDB;
CREATE DEFINER=`olduser`@`localhost` VIEW `xyz_v` AS SELECT 1 AS `a`;
ALTER TABLE `xyz_options` ADD PRIMARY KEY (`option_id`);
COMMIT;
SQL;
        // serialized length of the sitepress path is wrong on purpose? No: 40 chars.
        $sql = str_replace('s:34:', 's:40:', $sql);
        $file = tempnam(sys_get_temp_dir(), 'pma');
        file_put_contents($file, $sql);

        $info = (new SqlInspector())->inspect($file);
        $this->assertSame('xyz_', $info['site']['table_prefix']);
        $this->assertSame('https://test.example.com', $info['site']['url']);
        $this->assertSame(['utf8mb4_0900_ai_ci'], $info['db']['collations']);
        $this->assertSame(['crm_contacts'], $info['db']['foreign_tables']);
        $this->assertSame(3, $info['db']['tables']['xyz_posts']);
        $this->assertSame(['sitepress-multilingual-cms/sitepress.php', 'akismet/akismet.php'], $info['plugins']['active']);
        $this->assertSame(['Avada'], $info['themes']);

        $available = array_flip(array_column($this->db->rows('SHOW COLLATION'), 'Collation'));
        $plan = Preflight::collationPlan($info['db']['collations'], $available);
        $this->assertSame(['utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci'], $plan['convert']);

        (new Importer($this->db, $file, $plan['convert']))->runAll();
        $this->assertSame('https://test.example.com', $this->db->value("SELECT option_value FROM xyz_options WHERE option_name='home'"));
        $this->assertSame('1', $this->db->value('SELECT a FROM xyz_v'));
        $this->assertStringContainsString('utf8mb4_unicode_520_ci', $this->db->rows('SHOW CREATE TABLE xyz_options')[0]['Create Table']);
    }

    public function testBackupAndRestoreExistingTables(): void
    {
        $this->createWordPressTables();
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('home', 'keep-me')");
        $renamed = Importer::backupTables($this->db, 'wp_', 'ssbak_');
        $this->assertSame([], $this->db->tables('wp_'));
        $this->assertContains('ssbak_wp_options', $this->db->tables('ssbak_'));

        $this->createWordPressTables();
        Importer::restoreTables($this->db, $renamed);
        $this->assertSame('keep-me', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='home'"));
        $this->assertSame([], $this->db->tables('ssbak_'));
    }
}

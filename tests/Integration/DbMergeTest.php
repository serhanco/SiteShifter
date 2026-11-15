<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Plugin\Import\DbMerge;
use SiteShifter\Plugin\Import\MergePolicy;

class DbMergeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['wp_', 'ssi_'] as $p) {
            $this->createWordPressTables($p);
            $this->db->exec("CREATE TABLE {$p}users (ID bigint unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, user_email varchar(100) NOT NULL, PRIMARY KEY (ID))");
            $this->db->exec("CREATE TABLE {$p}usermeta (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext, PRIMARY KEY (umeta_id))");
        }
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://live.test'),('keep_me','live'),('shared','live'),('wp_user_roles','live roles')");
        $this->db->exec("INSERT INTO ssi_options (option_name, option_value) VALUES ('siteurl','https://old.test'),('only_pkg','pkg'),('shared','pkg'),('wp_user_roles','pkg roles'),('active_plugins','x')");
        $this->db->exec("INSERT INTO wp_users (user_login, user_email) VALUES ('alice','alice@x.test')");
        $this->db->exec("INSERT INTO ssi_users (ID, user_login, user_email) VALUES (5,'alice','alice@x.test'),(6,'bob','bob@x.test'),(7,'carol2','alice@x.test')");
        $this->db->exec("INSERT INTO ssi_usermeta (user_id, meta_key, meta_value) VALUES (6,'nickname','Bobby'),(5,'nickname','A')");
    }

    public function testDryRunCountsWithoutChanging(): void
    {
        $r = DbMerge::dryRun($this->db, 'ssi_', 'wp_');
        $this->assertSame(['options_new' => 1, 'options_changed' => 1, 'users_new' => 1, 'users_clash' => 2], $r);
        $this->assertSame('1', $this->db->value("SELECT COUNT(*) FROM wp_users"));
    }

    public function testDefaultMergeAddsAndKeepsAndMapsUsersAndUndoRestores(): void
    {
        $r = DbMerge::apply($this->db, 'ssi_', 'wp_', 'ssb_', new MergePolicy([]));
        $this->assertSame('pkg', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='only_pkg'"));
        $this->assertSame('live', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='shared'"));
        $this->assertSame('https://live.test', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='siteurl'"));
        $this->assertSame('0', $this->db->value("SELECT COUNT(*) FROM wp_options WHERE option_name='active_plugins'"));
        $this->assertSame('2', $this->db->value('SELECT COUNT(*) FROM wp_users'));
        $bob = (int) $this->db->value("SELECT ID FROM wp_users WHERE user_login='bob'");
        $this->assertSame('Bobby', $this->db->value("SELECT meta_value FROM wp_usermeta WHERE user_id={$bob}"));
        $alice = (int) $this->db->value("SELECT ID FROM wp_users WHERE user_login='alice'");
        $this->assertSame([5 => $alice, 6 => $bob, 7 => $alice], $r['user_map']);
        $this->assertSame(1, $r['stats']['users_added']);
        $this->assertSame(2, $r['stats']['users_mapped']);

        DbMerge::undo($this->db, $r['copies']);
        $this->assertSame('1', $this->db->value('SELECT COUNT(*) FROM wp_users'));
        $this->assertSame('0', $this->db->value("SELECT COUNT(*) FROM wp_options WHERE option_name='only_pkg'"));
        $this->assertSame([], $this->db->tables('ssb_'));
    }

    public function testOverwriteSettingsAndSkipUsers(): void
    {
        $r = DbMerge::apply($this->db, 'ssi_', 'wp_', 'ssb_', new MergePolicy(['settings' => 'overwrite', 'users' => 'skip']));
        $this->assertSame('pkg', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='shared'"));
        $this->assertSame('live roles', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='wp_user_roles'"));
        $this->assertSame(0, $r['user_map'][5]);
        $this->assertSame(2, $r['stats']['users_skipped']);
    }
}

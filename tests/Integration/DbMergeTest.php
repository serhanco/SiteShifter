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

    private function contentTables(): void
    {
        foreach (['wp_', 'ssi_'] as $p) {
            $this->db->exec("CREATE TABLE {$p}terms (term_id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(200) NOT NULL, slug varchar(200) NOT NULL, term_group bigint NOT NULL DEFAULT 0, PRIMARY KEY (term_id))");
            $this->db->exec("CREATE TABLE {$p}term_taxonomy (term_taxonomy_id bigint unsigned NOT NULL AUTO_INCREMENT, term_id bigint unsigned NOT NULL, taxonomy varchar(32) NOT NULL, description longtext NOT NULL, parent bigint unsigned NOT NULL DEFAULT 0, count bigint NOT NULL DEFAULT 0, PRIMARY KEY (term_taxonomy_id))");
            $this->db->exec("CREATE TABLE {$p}term_relationships (object_id bigint unsigned NOT NULL, term_taxonomy_id bigint unsigned NOT NULL, term_order int NOT NULL DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))");
            $this->db->exec("CREATE TABLE {$p}comments (comment_ID bigint unsigned NOT NULL AUTO_INCREMENT, comment_post_ID bigint unsigned NOT NULL DEFAULT 0, comment_content text NOT NULL, comment_approved varchar(20) NOT NULL DEFAULT '1', comment_parent bigint unsigned NOT NULL DEFAULT 0, user_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (comment_ID))");
        }
        // live: post 1 'hello' (will clash), category news (term 1)
        $this->db->exec("INSERT INTO wp_posts (ID, post_title, post_name, post_type) VALUES (1,'Live hello','hello','post')");
        $this->db->exec("INSERT INTO wp_terms (term_id, name, slug) VALUES (1,'News','news')");
        $this->db->exec("INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description) VALUES (1,1,'category','')");
        // package: hello (clash), about (new, parent = hello, author = package user 6 bob), image 20, post 30 with thumbnail 20, a lost parent 99
        $this->db->exec("INSERT INTO ssi_posts (ID, post_title, post_name, post_type, post_parent, post_author, guid) VALUES
            (10,'Pkg hello','hello','post',0,5,'g10'),(11,'About','about','page',10,6,'g11'),(20,'Img','img','attachment',0,6,'http://pkg/img.jpg'),
            (30,'Has image','has-image','post',0,6,'g30'),(31,'Orphan','orphan','post',99,6,'g31')");
        $this->db->exec("INSERT INTO ssi_postmeta (post_id, meta_key, meta_value) VALUES (30,'_thumbnail_id','20'),(11,'color','red'),(10,'pkgmeta','x')");
        $this->db->exec("INSERT INTO ssi_terms (term_id, name, slug) VALUES (7,'News','news'),(8,'Fresh','fresh')");
        $this->db->exec("INSERT INTO ssi_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description) VALUES (7,7,'category',''),(8,8,'category','')");
        $this->db->exec("INSERT INTO ssi_term_relationships (object_id, term_taxonomy_id) VALUES (30,7),(30,8),(11,8)");
        $this->db->exec("INSERT INTO ssi_comments (comment_ID, comment_post_ID, comment_content, comment_parent, user_id) VALUES (50,30,'first',0,6),(51,30,'reply',50,0)");
    }

    public function testContentMergeRemapsEveryReferenceAndFlagsUnknownOnes(): void
    {
        $this->contentTables();
        $r = DbMerge::apply($this->db, 'ssi_', 'wp_', 'ssb_', new MergePolicy([]));
        $this->assertTrue($r['content']);
        $this->assertSame('Live hello', $this->db->value("SELECT post_title FROM wp_posts WHERE post_name='hello'"));
        $about = (int) $this->db->value("SELECT ID FROM wp_posts WHERE post_name='about'");
        $hello = (int) $this->db->value("SELECT ID FROM wp_posts WHERE post_name='hello'");
        $this->assertSame($hello, (int) $this->db->value("SELECT post_parent FROM wp_posts WHERE ID={$about}"), 'parent of a skipped (clashing) post points at the live post');
        $bob = (int) $this->db->value("SELECT ID FROM wp_users WHERE user_login='bob'");
        $this->assertSame($bob, (int) $this->db->value("SELECT post_author FROM wp_posts WHERE ID={$about}"));
        $img = (int) $this->db->value("SELECT ID FROM wp_posts WHERE post_name='img'");
        $has = (int) $this->db->value("SELECT ID FROM wp_posts WHERE post_name='has-image'");
        $this->assertSame((string) $img, $this->db->value("SELECT meta_value FROM wp_postmeta WHERE post_id={$has} AND meta_key='_thumbnail_id'"));
        $this->assertSame('red', $this->db->value("SELECT meta_value FROM wp_postmeta WHERE post_id={$about} AND meta_key='color'"));
        $this->assertSame('0', $this->db->value("SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='pkgmeta'"), 'meta of a skipped post is not copied');
        // terms: "News" matched, "Fresh" added
        $this->assertSame('2', $this->db->value('SELECT COUNT(*) FROM wp_terms'));
        $fresh = (int) $this->db->value("SELECT term_taxonomy_id FROM wp_term_taxonomy tt JOIN wp_terms t ON t.term_id=tt.term_id WHERE t.slug='fresh'");
        $this->assertSame('2', $this->db->value("SELECT COUNT(*) FROM wp_term_relationships WHERE object_id={$has}"));
        $this->assertSame('1', $this->db->value("SELECT COUNT(*) FROM wp_term_relationships WHERE object_id={$about} AND term_taxonomy_id={$fresh}"));
        // comments keep their thread
        $this->assertSame('2', $this->db->value("SELECT COUNT(*) FROM wp_comments WHERE comment_post_ID={$has}"));
        $first = (int) $this->db->value("SELECT comment_ID FROM wp_comments WHERE comment_content='first'");
        $this->assertSame($first, (int) $this->db->value("SELECT comment_parent FROM wp_comments WHERE comment_content='reply'"));
        // unknown parent is cleared and reported, never guessed
        $orphan = (int) $this->db->value("SELECT ID FROM wp_posts WHERE post_name='orphan'");
        $this->assertSame('0', $this->db->value("SELECT post_parent FROM wp_posts WHERE ID={$orphan}"));
        $this->assertStringContainsString('parent 99', json_encode($r['flags']));
        $this->assertSame(4, $r['stats']['posts_added']);
        $this->assertSame(1, $r['stats']['posts_skipped']);

        DbMerge::undo($this->db, $r['copies']);
        $this->assertSame('1', $this->db->value('SELECT COUNT(*) FROM wp_posts'));
        $this->assertSame('1', $this->db->value('SELECT COUNT(*) FROM wp_terms'));
        $this->assertSame([], $this->db->tables('ssb_'));
    }

    public function testOverwriteAndRenameClashingPosts(): void
    {
        $this->contentTables();
        DbMerge::apply($this->db, 'ssi_', 'wp_', 'ssb_', new MergePolicy(['posts' => 'overwrite']));
        $this->assertSame('Pkg hello', $this->db->value('SELECT post_title FROM wp_posts WHERE ID = 1'));
        $this->assertSame('x', $this->db->value("SELECT meta_value FROM wp_postmeta WHERE post_id = 1 AND meta_key='pkgmeta'"));
    }

    public function testRenameKeepsBothWithFreeSlug(): void
    {
        $this->contentTables();
        DbMerge::apply($this->db, 'ssi_', 'wp_', 'ssb_', new MergePolicy(['posts' => 'rename']));
        $this->assertSame('Live hello', $this->db->value("SELECT post_title FROM wp_posts WHERE post_name = 'hello'"));
        $this->assertSame('Pkg hello', $this->db->value("SELECT post_title FROM wp_posts WHERE post_name = 'hello-2'"));
    }

    public function testContentCanBeSkipped(): void
    {
        $this->contentTables();
        $r = DbMerge::apply($this->db, 'ssi_', 'wp_', 'ssb_', new MergePolicy(['content' => 'skip']));
        $this->assertFalse($r['content']);
        $this->assertSame('1', $this->db->value('SELECT COUNT(*) FROM wp_posts'));
    }
}

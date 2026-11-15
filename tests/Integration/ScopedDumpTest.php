<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Database\Dumper;
use SiteShifter\Package\Scope;

class ScopedDumpTest extends DatabaseTestCase
{
    private function seed(): void
    {
        $this->createWordPressTables();
        $this->db->exec('CREATE TABLE wp_comments (comment_ID bigint unsigned NOT NULL AUTO_INCREMENT, comment_post_ID bigint unsigned NOT NULL DEFAULT 0, comment_content text, PRIMARY KEY (comment_ID))');
        $this->db->exec('CREATE TABLE wp_commentmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, comment_id bigint unsigned NOT NULL DEFAULT 0, meta_value text, PRIMARY KEY (meta_id))');
        $this->db->exec('CREATE TABLE wp_term_relationships (object_id bigint unsigned NOT NULL, term_taxonomy_id bigint unsigned NOT NULL, PRIMARY KEY (object_id, term_taxonomy_id))');
        $this->db->exec('CREATE TABLE wp_users (ID bigint unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60), PRIMARY KEY (ID))');
        $this->db->exec('CREATE TABLE wp_terms (term_id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(200), PRIMARY KEY (term_id))');
        $this->db->exec("INSERT INTO wp_posts (ID, post_content, post_title, post_type) VALUES (1,'a','Post','post'),(2,'b','Page','page'),(3,'c','Img','attachment'),(4,'d','Rev','revision'),(5,'e','Tpl','elementor_library')");
        $this->db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1,'k','v'),(2,'k','v'),(3,'k','v'),(4,'k','v'),(5,'_elementor_data','x')");
        $this->db->exec("INSERT INTO wp_comments (comment_post_ID, comment_content) VALUES (1,'c1'),(2,'c2'),(3,'c3')");
        $this->db->exec("INSERT INTO wp_commentmeta (comment_id, meta_value) VALUES (1,'m1'),(2,'m2'),(3,'m3')");
        $this->db->exec('INSERT INTO wp_term_relationships VALUES (1,10),(2,10),(3,10)');
        $this->db->exec("INSERT INTO wp_users (user_login) VALUES ('a')");
        $this->db->exec("INSERT INTO wp_terms (name) VALUES ('t')");
    }

    private function dump(Scope $scope): string
    {
        $rules = $scope->tableRules('wp_', $this->db->tables('wp_'));
        $file = tempnam(sys_get_temp_dir(), 'scoped');
        (new Dumper($this->db, $rules['tables'], $file, 2, $rules['where']))->runAll();
        $sql = (string) file_get_contents($file);
        @unlink($file);

        return $sql;
    }

    private function inserted(string $sql, string $table): string
    {
        preg_match_all('/INSERT INTO `' . $table . '`[^;]*;/s', $sql, $m);

        return implode("\n", $m[0]);
    }

    public function testPostsPagesWithoutMediaKeepRelatedRowsOnly(): void
    {
        $this->seed();
        $sql = $this->dump(Scope::fromArray(['profile' => 'custom', 'types' => ['post', 'page'], 'media' => false, 'users' => false]));

        $posts = $this->inserted($sql, 'wp_posts');
        $this->assertStringContainsString("'Post'", $posts);
        $this->assertStringContainsString("'Page'", $posts);
        $this->assertStringNotContainsString("'Img'", $posts);
        $this->assertStringNotContainsString("'Rev'", $posts);
        $this->assertStringNotContainsString("'Tpl'", $posts);
        $this->assertSame(2, substr_count($this->inserted($sql, 'wp_postmeta'), "'k'"));
        $comments = $this->inserted($sql, 'wp_comments');
        $this->assertStringContainsString("'c1'", $comments);
        $this->assertStringContainsString("'c2'", $comments);
        $this->assertStringNotContainsString("'c3'", $comments);
        $meta = $this->inserted($sql, 'wp_commentmeta');
        $this->assertStringContainsString("'m2'", $meta);
        $this->assertStringNotContainsString("'m3'", $meta);
        $rel = $this->inserted($sql, 'wp_term_relationships');
        $this->assertStringContainsString("('1', '10')", $rel);
        $this->assertStringContainsString("('2', '10')", $rel);
        $this->assertStringNotContainsString("('3', '10')", $rel);
        $this->assertStringNotContainsString('wp_users', $sql);
        $this->assertStringNotContainsString('wp_options', $sql);
    }

    public function testStructureProfileKeepsTemplatesButNoContent(): void
    {
        $this->seed();
        $sql = $this->dump(Scope::fromArray(['profile' => 'structure']));
        $posts = $this->inserted($sql, 'wp_posts');
        $this->assertStringContainsString("'Tpl'", $posts);
        $this->assertStringNotContainsString("'Post'", $posts);
        $this->assertStringNotContainsString('wp_comments', $sql);
        $this->assertStringContainsString('wp_options', $sql);
    }
}

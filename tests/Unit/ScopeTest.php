<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Package\Scope;

class ScopeTest extends TestCase
{
    private const TABLES = ['wp_commentmeta', 'wp_comments', 'wp_options', 'wp_postmeta', 'wp_posts', 'wp_term_relationships', 'wp_term_taxonomy', 'wp_termmeta', 'wp_terms', 'wp_usermeta', 'wp_users', 'wp_woocommerce_orders'];

    public function testFullKeepsEverythingWithoutFilters(): void
    {
        $r = Scope::full()->tableRules('wp_', self::TABLES);
        $this->assertSame(self::TABLES, $r['tables']);
        $this->assertSame([], $r['where']);
        $this->assertSame([], Scope::full()->only());
        $this->assertSame(['a'], Scope::full()->excludes(['a']));
        $this->assertSame([], Scope::full()->warnings());
    }

    public function testSettingsOnlyKeepsOptionsAndNoFiles(): void
    {
        $s = Scope::fromArray(['profile' => 'settings']);
        $r = $s->tableRules('wp_', self::TABLES);
        $this->assertSame(['wp_options'], $r['tables']);
        $this->assertFalse($s->hasFiles());
        $this->assertSame(['.siteshifter-no-files'], $s->only());
    }

    public function testStructureKeepsOnlyStructuralPostsAndThemesPlugins(): void
    {
        $s = Scope::fromArray(['profile' => 'structure']);
        $r = $s->tableRules('wp_', self::TABLES);
        $this->assertContains('wp_options', $r['tables']);
        $this->assertContains('wp_posts', $r['tables']);
        $this->assertNotContains('wp_users', $r['tables']);
        $this->assertNotContains('wp_comments', $r['tables']);
        $this->assertNotContains('wp_woocommerce_orders', $r['tables']);
        $this->assertStringContainsString("'elementor_library'", $r['where']['wp_posts']);
        $this->assertStringNotContainsString("'post'", $r['where']['wp_posts']);
        $this->assertStringContainsString('IN (SELECT `ID` FROM `wp_posts` WHERE', $r['where']['wp_postmeta']);
        $this->assertStringContainsString('IN (SELECT `ID` FROM `wp_posts` WHERE', $r['where']['wp_term_relationships']);
        $this->assertContains('wp-content/themes', $s->only());
        $this->assertNotContains('wp-content/uploads', $s->only());
    }

    public function testContentOnlyLeavesCodeAndSettingsOut(): void
    {
        $s = Scope::fromArray(['profile' => 'content']);
        $r = $s->tableRules('wp_', self::TABLES);
        $this->assertNotContains('wp_options', $r['tables']);
        $this->assertContains('wp_comments', $r['tables']);
        $this->assertContains('wp_users', $r['tables']);
        $this->assertSame("`post_type` <> 'revision'", $r['where']['wp_posts']);
        $this->assertSame(['wp-content/uploads'], $s->only());
    }

    public function testCustomTypesFollowTheMediaSwitchAndKeepRelatedRowsConsistent(): void
    {
        $s = Scope::fromArray(['profile' => 'custom', 'types' => ['post', 'page'], 'media' => false, 'users' => false, 'comments' => true]);
        $r = $s->tableRules('wp_', self::TABLES);
        $this->assertSame("`post_type` IN ('post', 'page')", $r['where']['wp_posts']);
        $this->assertContains('wp_comments', $r['tables']);
        $this->assertStringContainsString("`comment_post_ID` IN (SELECT `ID` FROM `wp_posts` WHERE `post_type` IN ('post', 'page'))", $r['where']['wp_comments']);
        $this->assertStringContainsString('`comment_id` IN (SELECT `comment_ID` FROM `wp_comments`', $r['where']['wp_commentmeta']);
        $this->assertNotContains('wp_users', $r['tables']);
        $this->assertContains('wp-content/uploads', $s->excludes([]));

        $withMedia = Scope::fromArray(['profile' => 'custom', 'types' => ['post'], 'media' => true]);
        $this->assertSame("`post_type` IN ('post', 'attachment')", $withMedia->tableRules('wp_', self::TABLES)['where']['wp_posts']);
    }

    public function testNoCommentsMeansNoCommentTablesAndNoPostsMeansNoPostRelatedTables(): void
    {
        $r = Scope::fromArray(['profile' => 'custom', 'comments' => false])->tableRules('wp_', self::TABLES);
        $this->assertNotContains('wp_comments', $r['tables']);
        $this->assertNotContains('wp_commentmeta', $r['tables']);

        $r = Scope::fromArray(['profile' => 'custom', 'types' => [], 'media' => false])->tableRules('wp_', self::TABLES);
        foreach (['wp_posts', 'wp_postmeta', 'wp_comments', 'wp_terms', 'wp_term_relationships'] as $t) {
            $this->assertNotContains($t, $r['tables']);
        }
    }

    public function testWarningsExplainWhatMustBeDoneByHand(): void
    {
        $codes = array_column(Scope::fromArray(['profile' => 'custom', 'media' => false, 'users' => false, 'themes' => false])->warnings(), 'code');
        $this->assertContains('media_excluded', $codes);
        $this->assertContains('users_excluded', $codes);
        $this->assertContains('code_excluded', $codes);
        $d = Scope::fromArray(['profile' => 'custom', 'media' => false])->describe(['skipped' => ['x'], 'filtered' => ['y']]);
        $this->assertTrue($d['manual_uploads']);
        $this->assertSame(['x'], $d['tables_skipped']);
    }

    public function testBadInputIsRejectedOrCleaned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromArray(['profile' => 'everything']);
    }

    public function testTypeNamesAreSanitised(): void
    {
        $s = Scope::fromArray(['profile' => 'custom', 'types' => ["post", "x'; DROP TABLE wp_posts;--", 'ok_type']]);
        $this->assertSame(['post', 'ok_type'], $s->toArray()['types']);
    }
}

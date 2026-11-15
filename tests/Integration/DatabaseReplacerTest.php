<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Database\DatabaseReplacer;
use SiteShifter\Database\Verifier;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Replace\Replacer;

class DatabaseReplacerTest extends DatabaseTestCase
{
    private function seed(): void
    {
        $this->createWordPressTables();
        $q = function ($v) { return $this->db->quote($v); };
        $this->db->exec("INSERT INTO wp_options (option_name, option_value) VALUES
            ('siteurl', 'http://old.test'), ('home', 'http://old.test'),
            ('widget_text', " . $q(serialize([2 => ['text' => '<a href="http://old.test/contact">İletişim</a>']])) . "),
            ('admin_email', 'info@old.test'),
            ('encoded_opt', " . $q(base64_encode(serialize(['u' => 'http://old.test/x']))) . ")");
        for ($i = 1; $i <= 25; $i++) {
            $this->db->exec("INSERT INTO wp_posts (post_content, post_title, guid) VALUES ("
                . $q("<img src=\"http://old.test/wp-content/uploads/{$i}.jpg\">") . ", 'Post {$i}', 'http://old.test/?p={$i}')");
            $this->db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$i}, '_elementor_data', "
                . $q(json_encode([['settings' => ['link' => ['url' => "http://old.test/p{$i}"]]]])) . ")");
        }
        $this->db->exec("INSERT INTO wp_icl_strings VALUES ('en', 'Visit http://old.test'), ('tr', 'Ziyaret http://old.test')");
    }

    private function replacer(): Replacer
    {
        return new Replacer((new ReplacementMap())->addUrl('http://old.test', 'https://new.com'));
    }

    public function testReplacesAcrossAllTablesAndResumesInSmallBatches(): void
    {
        $this->seed();
        $tables = $this->db->tables('wp_');
        $job = new DatabaseReplacer($this->db, $this->replacer(), $tables, ['wp_posts' => ['guid']], 7);

        $state = DatabaseReplacer::initialState();
        $calls = 0;
        while (!$state['done']) {
            // A deadline in the past still processes nothing; give each call exactly one batch.
            $state = $job->run($state, microtime(true) + 0.0001);
            $state = json_decode(json_encode($state), true); // state must survive a round-trip (stored between requests)
            $this->assertLessThan(100, ++$calls);
        }
        $this->assertGreaterThan(4, $calls, 'work should have been split into several calls');

        $this->assertSame('https://new.com', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='home'"));
        $widget = unserialize($this->db->value("SELECT option_value FROM wp_options WHERE option_name='widget_text'"));
        $this->assertSame('<a href="https://new.com/contact">İletişim</a>', $widget[2]['text']);
        $this->assertSame('info@old.test', $this->db->value("SELECT option_value FROM wp_options WHERE option_name='admin_email'"));
        $this->assertSame('http://old.test/?p=3', $this->db->value('SELECT guid FROM wp_posts WHERE ID=3'), 'guid is skipped');
        $this->assertStringContainsString('https://new.com/wp-content/uploads/25.jpg', $this->db->value('SELECT post_content FROM wp_posts WHERE ID=25'));
        $data = json_decode($this->db->value("SELECT meta_value FROM wp_postmeta WHERE post_id=12"), true);
        $this->assertSame('https://new.com/p12', $data[0]['settings']['link']['url']);
        $this->assertSame('0', $this->db->value("SELECT COUNT(*) FROM wp_icl_strings WHERE value LIKE '%old.test%'"), 'table without primary key');
        $this->assertSame(25, $state['stats']['wp_postmeta']['changed']);
    }

    public function testVerifierReportsLeftoversIncludingBase64(): void
    {
        $this->seed();
        $tables = $this->db->tables('wp_');
        (new DatabaseReplacer($this->db, $this->replacer(), $tables, ['wp_posts' => ['guid']]))->runAll();

        $found = (new Verifier($this->db))->leftovers($tables, ['old.test'], ['wp_posts' => ['guid']]);
        $byKey = [];
        foreach ($found as $f) {
            $byKey[$f['table'] . '.' . $f['column'] . ':' . $f['kind']] = $f['count'];
        }
        $this->assertSame(['wp_options.option_value:email' => 1, 'wp_options.option_value:base64' => 1], $byKey);
    }

    public function testRowCountComparisonAcrossPrefixes(): void
    {
        $this->seed();
        $counts = (new Verifier($this->db))->rowCounts($this->db->tables('wp_'));
        $this->assertSame(25, $counts['wp_posts']);

        $target = ['abc_options' => 5, 'abc_posts' => 24, 'abc_postmeta' => 25];
        $diff = Verifier::compareRowCounts($counts, $target, 'wp_', 'abc_');
        $this->assertSame([
            ['table' => 'abc_icl_strings', 'source' => 2, 'target' => null],
            ['table' => 'abc_posts', 'source' => 25, 'target' => 24],
        ], self::sorted($diff));
    }

    private static function sorted(array $rows): array
    {
        usort($rows, function ($a, $b) { return strcmp($a['table'], $b['table']); });
        return $rows;
    }
}

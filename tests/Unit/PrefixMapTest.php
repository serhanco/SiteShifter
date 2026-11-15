<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Database\PrefixMap;

class PrefixMapTest extends TestCase
{
    public function testRenamesTablesInStatementHeadsAndReferences(): void
    {
        $m = new PrefixMap('wp_', 'ssi1_');
        $this->assertSame('DROP TABLE IF EXISTS `ssi1_posts`', $m->transform('DROP TABLE IF EXISTS `wp_posts`'));
        $this->assertSame('INSERT INTO `ssi1_options` VALUES (1,\'`wp_posts`\')', $m->transform('INSERT INTO `wp_options` VALUES (1,\'`wp_posts`\')'));
        $this->assertStringContainsString('REFERENCES `ssi1_posts`', $m->transform('CREATE TABLE `wp_x` (a int, FOREIGN KEY (a) REFERENCES `wp_posts` (ID))'));
        $this->assertSame('SET NAMES utf8mb4', $m->transform('SET NAMES utf8mb4'));
        $this->assertSame('other_table', $m->table('other_table'));
    }

    public function testRejectsUnsafePrefixes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrefixMap('wp_', 'a`b');
    }
}

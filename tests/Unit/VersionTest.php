<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function testEveryPlaceThatNamesTheVersionAgrees(): void
    {
        $root = dirname(__DIR__, 2);
        $plugin = file_get_contents($root . '/plugin/siteshifter.php');
        preg_match('/Version:\s+(\S+)/', $plugin, $header);
        preg_match("/define\\('SITESHIFTER_VERSION', '([^']+)'/", $plugin, $const);
        preg_match('/Stable tag:\s*(\S+)/', file_get_contents($root . '/plugin/readme.txt'), $stable);
        preg_match("/VERSION = '([^']+)'/", file_get_contents($root . '/src/Cli/Application.php'), $cli);
        preg_match("/SS_VERSION = '([^']+)'/", file_get_contents($root . '/installer/ui.php'), $installer);
        $this->assertSame($header[1], $const[1]);
        $this->assertSame($header[1], $stable[1]);
        $this->assertSame($header[1], $cli[1]);
        $this->assertSame($header[1], $installer[1]);
        $this->assertStringContainsString('= ' . $header[1] . ' =', file_get_contents($root . '/plugin/readme.txt'));
    }
}

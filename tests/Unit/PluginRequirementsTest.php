<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PluginRequirementsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once dirname(__DIR__, 2) . '/plugin/includes/Requirements.php';
    }

    public function testSupportedSiteHasNoProblems(): void
    {
        $this->assertSame([], \SiteShifter\Plugin\Requirements::problems('8.3.0', '6.8', ['mysqli', 'zip']));
    }

    public function testOldPhpAndWordPressAreReportedNotFatal(): void
    {
        $p = \SiteShifter\Plugin\Requirements::problems('7.3.9', '5.7', ['mysqli', 'zip']);
        $this->assertCount(2, $p);
        $this->assertStringContainsString('PHP 7.4', $p[0]);
        $this->assertStringContainsString('WordPress 5.8', $p[1]);
    }

    public function testMissingExtensionsAreReported(): void
    {
        $p = \SiteShifter\Plugin\Requirements::problems('8.2.0', '6.0', []);
        $this->assertCount(2, $p);
        $this->assertStringContainsString('mysqli', $p[0]);
    }
}

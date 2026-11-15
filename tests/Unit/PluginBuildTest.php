<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The plugin bundles the core under SiteShifter\Core so two copies never clash. */
class PluginBuildTest extends TestCase
{
    public function testBuiltPluginPrefixesCoreAndHasNoCli(): void
    {
        $root = dirname(__DIR__, 2);
        exec('php ' . escapeshellarg($root . '/bin/build-plugin.php') . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        $dir = $root . '/dist/siteshifter';
        $this->assertFileExists($dir . '/siteshifter.php');
        $this->assertDirectoryDoesNotExist($dir . '/core/Cli');

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir . '/core', \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $count++;
            $code = file_get_contents($f->getPathname());
            $this->assertSame(0, preg_match('/(?<![A-Za-z0-9_])SiteShifter\\\\(?!Core\\\\)(?=[A-Z])/', $code), $f->getPathname() . ' still has an unprefixed reference');
            $this->assertMatchesRegularExpression('/^namespace SiteShifter\\\\Core\\\\/m', $code, $f->getPathname());
        }
        $this->assertGreaterThan(20, $count);
    }

    public function testPluginCodeKeepsItsOwnNamespaceAndPointsAtThePrefixedCore(): void
    {
        $root = dirname(__DIR__, 2);
        exec('php ' . escapeshellarg($root . '/bin/build-plugin.php') . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $dir = $root . '/dist/siteshifter/includes';
        $this->assertStringContainsString('namespace SiteShifter\\Plugin;', file_get_contents($dir . '/Plugin.php'));
        $this->assertStringContainsString('use SiteShifter\\Core\\Database\\Dumper;', file_get_contents($dir . '/Jobs/AddressChangeTask.php'));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $this->assertSame(0, preg_match('/(?<![A-Za-z0-9_])SiteShifter\\\\(?!Plugin\\b|Core\\b)(?=[A-Z])/', file_get_contents($f->getPathname())), $f->getPathname());
            }
        }
    }

    public function testPluginHeaderMatchesReadmeVersion(): void
    {
        $root = dirname(__DIR__, 2) . '/plugin';
        preg_match('/^ \* Version:\s*(\S+)/m', file_get_contents($root . '/siteshifter.php'), $a);
        preg_match('/^Stable tag:\s*(\S+)/m', file_get_contents($root . '/readme.txt'), $b);
        $this->assertSame($a[1], $b[1]);
    }
}

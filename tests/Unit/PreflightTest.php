<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Preflight\Preflight;
use SiteShifter\Preflight\Probe;
use SiteShifter\Preflight\Result;

class FakeProbe extends Probe
{
    public $php = '8.2.10';
    public $extensions = ['mysqli', 'json', 'zip', 'mbstring', 'curl', 'openssl', 'gd'];
    public $ini = ['memory_limit' => '256M', 'max_execution_time' => '30', 'upload_max_filesize' => '64M', 'post_max_size' => '64M'];
    public $canRaise = true;
    public $free = 10 * 1024 ** 3;
    public $writable = true;
    public $files = [];
    public $software = 'Apache';
    public $dns = ['new.com' => ['1.2.3.4'], 'www.new.com' => ['1.2.3.4']];
    public $serverIp = '1.2.3.4';
    public $tls = null;

    public function phpVersion(): string { return $this->php; }
    public function extensionLoaded(string $name): bool { return in_array($name, $this->extensions, true); }
    public function ini(string $key) { return $this->ini[$key] ?? false; }
    public function canSetIni(string $key, string $value): bool { return $this->canRaise; }
    public function diskFree(string $dir) { return $this->free; }
    public function isWritable(string $path): bool { return $this->writable; }
    public function exists(string $path): bool { return isset($this->files[$path]); }
    public function serverSoftware(): string { return $this->software; }
    public function serverAddress(): string { return $this->serverIp; }
    public function resolve(string $host): array { return $this->dns[$host] ?? []; }
    public function tlsError(string $host, int $port = 443, int $timeout = 5): ?string { return $this->tls; }
}

class PreflightTest extends TestCase
{
    private function level(FakeProbe $p, string $id, array $ctx = []): string
    {
        $report = (new Preflight($p))->run($ctx + ['target_dir' => '/site']);
        $result = $report->get($id);
        $this->assertNotNull($result, "missing result {$id}");

        return $result->level;
    }

    public function testHealthyServerHasNoBlockers(): void
    {
        $report = (new Preflight(new FakeProbe()))->run(['target_dir' => '/site', 'new_url' => 'https://new.com', 'unpacked_bytes' => 1024 ** 3]);
        $this->assertFalse($report->hasBlockers(), $report->toText());
        $this->assertSame([], $report->byLevel(Result::WARN), $report->toText());
    }

    public function testPhpBelowSiteRequirementBlocks(): void
    {
        $p = new FakeProbe();
        $p->php = '7.4.33';
        $this->assertSame(Result::BLOCK, $this->level($p, 'php_version', ['manifest' => ['php' => ['requires' => '8.0', 'version' => '8.1.2']]]));
    }

    public function testOlderPhpThanSourceWarns(): void
    {
        $p = new FakeProbe();
        $p->php = '8.0.30';
        $this->assertSame(Result::WARN, $this->level($p, 'php_version', ['manifest' => ['php' => ['version' => '8.2.1']]]));
    }

    public function testMissingZipBlocksOnlyWhenAZipMustBeExtracted(): void
    {
        $p = new FakeProbe();
        $p->extensions = array_diff($p->extensions, ['zip']);
        $this->assertSame(Result::WARN, $this->level($p, 'php_extensions'));
        $this->assertSame(Result::BLOCK, $this->level($p, 'php_extensions', ['needs_zip' => true]));
    }

    public function testLowMemory(): void
    {
        $p = new FakeProbe();
        $p->ini['memory_limit'] = '32M';
        $this->assertSame(Result::WARN, $this->level($p, 'memory_limit'));
        $p->canRaise = false;
        $this->assertSame(Result::BLOCK, $this->level($p, 'memory_limit'));
        $p->ini['memory_limit'] = '128M';
        $this->assertSame(Result::WARN, $this->level($p, 'memory_limit'));
        $p->ini['memory_limit'] = '-1';
        $this->assertSame(Result::OK, $this->level($p, 'memory_limit'));
    }

    public function testShortTimeLimitIsNeverABlocker(): void
    {
        $p = new FakeProbe();
        $p->ini['max_execution_time'] = '10';
        $this->assertSame(Result::OK, $this->level($p, 'max_execution_time'));
    }

    public function testDiskSpaceAndPermissions(): void
    {
        $p = new FakeProbe();
        $p->free = 500 * 1024 ** 2;
        $this->assertSame(Result::BLOCK, $this->level($p, 'disk_space', ['unpacked_bytes' => 2 * 1024 ** 3]));
        $p->writable = false;
        $this->assertSame(Result::BLOCK, $this->level($p, 'writable'));
    }

    public function testUploadLimitOnlyForBrowserUploads(): void
    {
        $p = new FakeProbe();
        $ctx = ['package_bytes' => 500 * 1024 ** 2];
        $this->assertNull((new Preflight($p))->run($ctx)->get('upload_limit'));
        $this->assertSame(Result::WARN, $this->level($p, 'upload_limit', $ctx + ['browser_upload' => true]));
    }

    public function testExistingWordPressWarns(): void
    {
        $p = new FakeProbe();
        $p->files['/site/wp-config.php'] = true;
        $this->assertSame(Result::WARN, $this->level($p, 'existing_site'));
    }

    public function testHardCodedPathsAndCacheDropIns(): void
    {
        $files = [
            '.user.ini' => "auto_prepend_file = '/home/old/public_html/wordfence-waf.php'",
            'wp-content/object-cache.php' => '<?php // redis',
            'wp-config.php' => "<?php define('WP_HOME', 'http://old.test');",
        ];
        $report = (new Preflight(new FakeProbe()))->site([
            'manifest' => ['site' => ['abspath' => '/home/old/public_html/']],
            'file_reader' => function ($f) use ($files) { return $files[$f] ?? null; },
        ]);
        $r = $report->get('hardcoded_paths');
        $this->assertSame(Result::WARN, $r->level);
        $this->assertStringContainsString('.user.ini', $r->message);
        $this->assertStringContainsString('object-cache.php', $r->message);
        $this->assertStringContainsString('WP_HOME', $r->message);
    }

    public function testHtaccessOnNginxWarns(): void
    {
        $p = new FakeProbe();
        $p->software = 'nginx/1.24';
        $report = (new Preflight($p))->site(['file_reader' => function ($f) { return $f === '.htaccess' ? 'RewriteEngine On' : null; }]);
        $this->assertSame(Result::WARN, $report->get('webserver')->level);
    }

    public function testMultisiteBlocks(): void
    {
        $this->assertSame(Result::BLOCK, $this->level(new FakeProbe(), 'multisite', ['manifest' => ['site' => ['multisite' => true]]]));
    }

    public function testDnsAndSsl(): void
    {
        $p = new FakeProbe();
        $this->assertSame(Result::WARN, $this->level($p, 'dns', ['new_url' => 'https://unknown.com']));
        $p->serverIp = '9.9.9.9';
        $this->assertSame(Result::WARN, $this->level($p, 'dns', ['new_url' => 'https://new.com']));
        $p->tls = 'certificate verify failed';
        $this->assertSame(Result::WARN, $this->level($p, 'ssl', ['new_url' => 'https://new.com']));
    }

    public function testKnownLicencesAreListed(): void
    {
        $report = (new Preflight(new FakeProbe()))->licences([
            'plugins' => ['active' => ['elementor-pro/elementor-pro.php', 'akismet/akismet.php', 'sitepress-multilingual-cms/sitepress.php']],
            'themes' => ['Avada'],
        ]);
        $ids = array_map(function ($r) { return $r->id; }, $report->all());
        $this->assertContains('licence:elementor-pro/elementor-pro.php', $ids);
        $this->assertContains('licence:sitepress-multilingual-cms/sitepress.php', $ids);
        $this->assertContains('licence:theme:Avada', $ids);
        $this->assertNotContains('licence:akismet/akismet.php', $ids);
    }

    public function testCollationPlanConvertsMySql8ForMariaDb(): void
    {
        $available = array_flip(['utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_bin', 'utf8mb4_turkish_ci']);
        $plan = Preflight::collationPlan(['utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', 'utf8mb4_tr_0900_ai_ci', 'weird_ci'], $available);
        $this->assertSame(['utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci', 'utf8mb4_tr_0900_ai_ci' => 'utf8mb4_turkish_ci'], $plan['convert']);
        $this->assertSame(['weird_ci'], $plan['unsupported']);

        $old = array_flip(['utf8mb4_general_ci']);
        $this->assertSame(['utf8mb4_0900_ai_ci' => 'utf8mb4_general_ci'], Preflight::collationPlan(['utf8mb4_0900_ai_ci'], $old)['convert']);
    }

    public function testByteParsing(): void
    {
        $this->assertSame(256 * 1024 * 1024, Preflight::bytes('256M'));
        $this->assertSame(1024 ** 3, Preflight::bytes('1G'));
        $this->assertSame(-1, Preflight::bytes('-1'));
        $this->assertSame('1.5 GB', Preflight::human((int) (1.5 * 1024 ** 3)));
    }

    public function testPhpMajorJumpWarnsAndNamesPlugins(): void
    {
        $p = new FakeProbe();
        $p->php = '8.2.10';
        $this->assertSame(Result::WARN, $this->level($p, 'php_major_jump', ['manifest' => ['php' => ['version' => '7.4.33']]]));

        $p->php = '7.4.33';
        $report = (new Preflight($p))->server(['manifest' => ['php' => ['requires' => '8.1'], 'plugins' => ['details' => [
            ['name' => 'WooCommerce', 'requires_php' => '8.1'], ['name' => 'Akismet', 'requires_php' => '7.2'],
        ]]]]);
        $this->assertSame(Result::BLOCK, $report->get('php_version')->level);
        $this->assertStringContainsString('WooCommerce (PHP 8.1+)', $report->get('php_version')->message);
        $this->assertStringNotContainsString('Akismet', $report->get('php_version')->message);
    }

    public function testDatabaseServerComparison(): void
    {
        $this->assertSame(Result::OK, Preflight::compareDbServers('8.0.36', '8.0.40')->level);
        $this->assertSame(Result::WARN, Preflight::compareDbServers('8.0.36', '5.7.44')->level);
        $this->assertSame(Result::WARN, Preflight::compareDbServers('10.11.6-MariaDB', '10.3.39-MariaDB')->level);
        $mysqlToMaria = Preflight::compareDbServers('8.0.36', '10.11.6-MariaDB-log');
        $this->assertSame(Result::WARN, $mysqlToMaria->level);
        $this->assertStringContainsString('MySQL 8.0.36 to MariaDB 10.11.6', $mysqlToMaria->message);
        $this->assertSame(['mariadb', '10.6.16'], Preflight::dbFlavor('5.5.5-10.6.16-MariaDB-cll-lve'));
    }

    public function testWordPressVersionChecks(): void
    {
        $p = new Preflight(new FakeProbe());
        $manifest = ['site' => ['wp_version' => '6.9.1'], 'db' => ['db_version' => '60421'], 'plugins' => ['details' => [
            ['name' => 'Future Plugin', 'requires_wp' => '7.2'], ['name' => 'Old Plugin', 'requires_wp' => '5.0'],
        ]]];

        $newer = $p->wordpress(['manifest' => $manifest, 'target_wp_version' => '7.1', 'target_wp_db_version' => 61000]);
        $this->assertSame(Result::WARN, $newer->get('wp_version')->level);
        $this->assertStringContainsString('6.9.1 to 7.1', $newer->get('wp_version')->message);
        $this->assertStringContainsString('Future Plugin (WordPress 7.2+)', $newer->get('wp_plugin_requirements')->message);

        $older = $p->wordpress(['manifest' => $manifest, 'target_wp_version' => '6.8.3', 'target_wp_db_version' => 60000]);
        $this->assertSame(Result::BLOCK, $older->get('wp_version')->level);

        $same = $p->wordpress(['manifest' => $manifest, 'target_wp_version' => '6.9.4', 'target_wp_db_version' => 60421]);
        $this->assertSame(Result::OK, $same->get('wp_version')->level);

        $this->assertSame([], $p->wordpress(['manifest' => $manifest])->all(), 'unknown target: no claim');
    }
}

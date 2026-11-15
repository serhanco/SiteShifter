<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Support\SiteCheck;

class SiteCheckTest extends TestCase
{
    private function resp(array $o = []): array
    {
        return $o + ['status' => 200, 'headers' => [], 'body' => '', 'error' => null, 'chain' => ['https://a.com'], 'final_url' => 'https://a.com', 'tls_error' => null];
    }

    private function ids(array $notes): array
    {
        return array_column($notes, 'id');
    }

    public function testFlexibleLoopBehindCloudflare(): void
    {
        $n = SiteCheck::diagnose($this->resp(['error' => 'redirect loop', 'headers' => ['cf-ray' => 'x']]), 'https://a.com');
        $this->assertSame(['loop'], $this->ids($n));
        $this->assertStringContainsString('Full', $n[0]['fix']);
    }

    public function testCloudflare526And522(): void
    {
        $this->assertSame(['cf_526'], $this->ids(SiteCheck::diagnose($this->resp(['status' => 526]), 'https://a.com')));
        $this->assertSame(['cf_522'], $this->ids(SiteCheck::diagnose($this->resp(['status' => 522]), 'https://a.com')));
    }

    public function testUnreachableAndTls(): void
    {
        $this->assertSame(['unreachable'], $this->ids(SiteCheck::diagnose($this->resp(['status' => 0, 'error' => 'timeout']), 'https://a.com')));
        $this->assertSame(['tls'], $this->ids(SiteCheck::diagnose($this->resp(['status' => 0, 'tls_error' => 'bad cert']), 'https://a.com')));
    }

    public function testMixedContentHttpOpenAndCloudflareHints(): void
    {
        $r = $this->resp(['headers' => ['cf-ray' => 'x', 'cf-cache-status' => 'HIT'], 'body' => '<img src="http://www.a.com/x.png"><a href="https://a.com">']);
        $ids = $this->ids(SiteCheck::diagnose($r, 'https://a.com', $this->resp(['final_url' => 'http://a.com'])));
        foreach (['mixed', 'http_open', 'cf_cache', 'cf_settings'] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function testCleanSiteHasNoProblems(): void
    {
        $this->assertSame([], SiteCheck::diagnose($this->resp(['body' => '<a href="https://a.com">']), 'https://a.com', $this->resp(['final_url' => 'https://a.com'])));
    }

    public function testDowngradeAndServerError(): void
    {
        $this->assertContains('downgrade', $this->ids(SiteCheck::diagnose($this->resp(['final_url' => 'http://a.com']), 'https://a.com')));
        $this->assertSame(['http_500'], $this->ids(SiteCheck::diagnose($this->resp(['status' => 500]), 'https://a.com')));
    }

    public function testFetchDetectsLoopAndHeaders(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped();
        }
        $dir = sys_get_temp_dir() . '/sc' . getmypid();
        mkdir($dir);
        file_put_contents($dir . '/r.php', '<?php if (isset($_GET["ok"])) { header("X-T: 1"); echo "hi"; } else { header("Location: /r.php", true, 302); }');
        $port = 18000 + getmypid() % 1000;
        $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        usleep(600000);
        try {
            $loop = SiteCheck::fetch("http://127.0.0.1:$port/r.php", 3);
            $this->assertSame('redirect loop', $loop['error']);
            $ok = SiteCheck::fetch("http://127.0.0.1:$port/r.php?ok=1", 3);
            $this->assertSame(200, $ok['status']);
            $this->assertSame('1', $ok['headers']['x-t']);
        } finally {
            proc_terminate($p);
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }
}

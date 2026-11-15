<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Installer\ConfigWriter;
use SiteShifter\Preflight\Preflight;
use SiteShifter\Replace\Replacer;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Support\Https;

class HttpsTest extends TestCase
{
    /** @dataProvider requests */
    public function testSecureRequestDetection(array $server, bool $expected): void
    {
        $this->assertSame($expected, Https::isSecureRequest($server));
    }

    public function requests(): array
    {
        return [
            'plain http' => [['SERVER_PORT' => '80'], false],
            'direct https' => [['HTTPS' => 'on'], true],
            'https off' => [['HTTPS' => 'off'], false],
            'port 443' => [['SERVER_PORT' => 443], true],
            'forwarded proto' => [['HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'forwarded list' => [['HTTP_X_FORWARDED_PROTO' => 'https,http'], true],
            'forwarded http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
            'cloudflare visitor' => [['HTTP_CF_VISITOR' => '{"scheme":"https"}'], true],
            'cloudflare http visitor' => [['HTTP_CF_VISITOR' => '{"scheme":"http"}'], false],
            'forwarded ssl' => [['HTTP_X_FORWARDED_SSL' => 'on'], true],
            'front end https' => [['HTTP_FRONT_END_HTTPS' => 'on'], true],
        ];
    }

    public function testCdnDetection(): void
    {
        $this->assertSame('Cloudflare', Https::cdnFor(['188.114.97.3', '188.114.96.3']));
        $this->assertSame('Cloudflare', Https::cdnFor(['104.21.5.9']));
        $this->assertNull(Https::cdnFor(['92.205.184.182']));
        $this->assertNull(Https::cdnFor(['188.114.97.3', '92.205.184.182']));
        $this->assertNull(Https::cdnFor([]));
    }

    public function testProxyBlockIsInsertedOnceAfterThePhpTag(): void
    {
        $config = "<?php\ndefine( 'DB_NAME', 'x' );\n";
        $fixed = Https::addProxyFix($config);
        $this->assertStringStartsWith("<?php\n" . Https::MARKER_BEGIN, $fixed);
        $this->assertSame($fixed, Https::addProxyFix($fixed), 'must not be added twice');
        $this->assertSame(0, substr_count(Https::addProxyFix("<?php\n\$_SERVER['HTTPS']='on'; // HTTP_X_FORWARDED_PROTO handled\n"), Https::MARKER_BEGIN), 'an existing fix is respected');
        $this->assertNotFalse(@eval('return true;' . substr($fixed, 5) . ' '), 'the block is valid PHP');
    }

    public function testProxyBlockMakesWordPressSeeHttps(): void
    {
        $block = Https::proxySnippet();
        $run = static function (array $server) use ($block) {
            $_SERVER = $server;
            eval($block);

            return $_SERVER['HTTPS'] ?? null;
        };
        $saved = $_SERVER;
        $this->assertSame('on', $run(['HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertSame('on', $run(['HTTP_CF_VISITOR' => '{"scheme":"https"}']));
        $this->assertNull($run(['SERVER_PORT' => '80']));
        $this->assertSame('on', $run(['HTTPS' => 'on']));
        $_SERVER = $saved;
    }

    public function testConfigWriterAddsTheBlockOnlyForHttpsAndWhenAllowed(): void
    {
        $db = ['host' => 'localhost', 'user' => 'u', 'pass' => 'p', 'name' => 'n'];
        $source = "<?php\ndefine( 'DB_NAME', 'old' );\ndefine( 'DB_USER', 'old' );\ndefine( 'DB_PASSWORD', 'old' );\ndefine( 'DB_HOST', 'old' );\n\$table_prefix = 'wp_';\n";
        $writer = new ConfigWriter();
        $this->assertStringContainsString(Https::MARKER_BEGIN, $writer->write($source, $db, 'wp_', 'https://new.com', '/tmp'));
        $this->assertStringNotContainsString(Https::MARKER_BEGIN, $writer->write($source, $db, 'wp_', 'http://new.com', '/tmp'));
        $this->assertStringNotContainsString(Https::MARKER_BEGIN, $writer->write($source, $db, 'wp_', 'https://new.com', '/tmp', false));
        $this->assertStringContainsString(Https::MARKER_BEGIN, $writer->write(null, $db, 'wp_', 'https://new.com', '/tmp'), 'also for a fresh file');
    }

    public function testAddressChecks(): void
    {
        $p = new Preflight(new FakeProbe());
        $this->assertSame('warn', $p->address('https://old.com', 'http://new.com')->get('scheme')->level);
        $this->assertSame('info', $p->address('http://old.com', 'https://new.com')->get('scheme')->level);
        $this->assertNull($p->address('https://old.com', 'https://new.com')->get('scheme'));
        $this->assertNull($p->address('https://old.com', 'http://localhost:8080')->get('scheme'), 'local sites may use http');

        $this->assertSame('warn', $p->address('https://www.new.com', 'https://new.com')->get('www')->level);
        $this->assertSame('warn', $p->address('https://new.com', 'https://www.new.com')->get('www')->level);
        $this->assertNull($p->address('https://new.com', 'https://new.com')->get('www'));
        $this->assertNull($p->address('https://old.com', 'https://new.com')->get('www'), 'a domain change is not a www change');
    }

    public function testAlternateHost(): void
    {
        $this->assertSame('example.com', Preflight::alternateHost('www.example.com'));
        $this->assertSame('www.example.com', Preflight::alternateHost('example.com'));
        $this->assertSame('www.example.co.uk', Preflight::alternateHost('example.co.uk'));
        $this->assertNull(Preflight::alternateHost('blog.example.com'));
        $this->assertNull(Preflight::alternateHost('localhost'));
        $this->assertNull(Preflight::alternateHost('127.0.0.1'));
    }

    public function testCloudflareIsRecognisedAndTheOtherSpellingChecked(): void
    {
        $p = new FakeProbe();
        $p->dns = ['new.com' => ['188.114.97.3'], 'www.new.com' => ['188.114.97.3']];
        $report = (new Preflight($p))->url('https://new.com');
        $this->assertSame('ok', $report->get('dns')->level);
        $this->assertSame('info', $report->get('cdn')->level);
        $this->assertNull($report->get('dns_alt'));

        $p->dns = ['new.com' => ['188.114.97.3']];
        $this->assertSame('warn', (new Preflight($p))->url('https://new.com')->get('dns_alt')->level);

        $p->dns = ['new.com' => ['1.2.3.4'], 'www.new.com' => ['1.2.3.4']];
        $p->tls = 'certificate verify failed';
        $this->assertSame('warn', (new Preflight($p))->url('https://new.com')->get('ssl_alt')->level);
    }

    public function testHttpAndWwwSpellingsAreAllMovedToTheNewAddress(): void
    {
        $map = new ReplacementMap();
        $map->addUrl('https://old.com', 'https://www.new.com');
        $replacer = new Replacer($map);
        $text = 'http://old.com/a https://old.com/b http://www.old.com/c https:\/\/old.com\/d //old.com/e';
        $this->assertSame('https://www.new.com/a https://www.new.com/b https://www.new.com/c https:\/\/www.new.com\/d //www.new.com/e', $replacer->replace($text));

        // Same host, only the scheme changes: http links become https.
        $map = new ReplacementMap();
        $map->addUrl('https://same.com', 'https://same.com');
        $this->assertSame('https://same.com/x https://same.com/y', (new Replacer($map))->replace('http://same.com/x https://same.com/y'));
    }
}

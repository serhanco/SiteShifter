<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Replace\Replacer;

class ReplacerTest extends TestCase
{
    private function replacer(string $old = 'http://old.test', string $new = 'https://new.com'): Replacer
    {
        $map = (new ReplacementMap())
            ->addUrl($old, $new)
            ->addPath('/home/olduser/public_html', '/var/www/new.com');

        return new Replacer($map);
    }

    public function testPlainUrlsAllSchemesBecomeNewUrl(): void
    {
        $r = $this->replacer();
        $this->assertSame(
            '<a href="https://new.com/about">x</a> <img src="https://new.com/a.jpg"> //new.com/b.css',
            $r->replace('<a href="http://old.test/about">x</a> <img src="https://old.test/a.jpg"> //old.test/b.css')
        );
    }

    public function testWwwVariantIsMapped(): void
    {
        $this->assertSame('https://new.com/x', $this->replacer()->replace('http://www.old.test/x'));
    }

    public function testDoesNotTouchLongerHostnames(): void
    {
        $r = $this->replacer();
        $input = 'https://old.test.tr/x https://old.tester.com https://old.test-cdn.com';
        $this->assertSame($input, $r->replace($input));
    }

    public function testEmailsAreLeftAlone(): void
    {
        $input = 'Write to info@old.test';
        $this->assertSame($input, $this->replacer()->replace($input));
    }

    public function testJsonEscapedUrlsLikeElementor(): void
    {
        $data = json_encode([['settings' => ['image' => ['url' => 'http://old.test/wp-content/uploads/a.jpg', 'id' => 42]]]]);
        $out = $this->replacer()->replace($data);
        $decoded = json_decode($out, true);
        $this->assertSame('https://new.com/wp-content/uploads/a.jpg', $decoded[0]['settings']['image']['url']);
        $this->assertSame(42, $decoded[0]['settings']['image']['id']);
        $this->assertStringNotContainsString('old.test', $out);
    }

    public function testUrlEncodedUrls(): void
    {
        $out = $this->replacer()->replace('share?u=http%3A%2F%2Fold.test%2Fpost');
        $this->assertSame('share?u=https%3A%2F%2Fnew.com%2Fpost', $out);
    }

    public function testSerializedArrayLengthsAreFixed(): void
    {
        $value = serialize([
            'url' => 'http://old.test/link',
            'title' => 'Hello',
            'nested' => ['img' => 'http://old.test/i.png', 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null],
        ]);
        $out = $this->replacer()->replace($value);
        $this->assertSame([
            'url' => 'https://new.com/link',
            'title' => 'Hello',
            'nested' => ['img' => 'https://new.com/i.png', 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null],
        ], unserialize($out));
    }

    public function testDoubleSerializedValues(): void
    {
        $inner = serialize(['home' => 'http://old.test']);
        $outer = serialize(['widget' => $inner]);
        $out = unserialize($this->replacer()->replace($outer));
        $this->assertSame(['home' => 'https://new.com'], unserialize($out['widget']));
    }

    public function testSerializedObjectsAreNotInstantiated(): void
    {
        $o = new \stdClass();
        $o->link = 'http://old.test/x';
        $value = str_replace('stdClass', 'Missing_Plugin_Class', serialize($o));
        $value = str_replace('O:8:"', 'O:20:"', $value);

        $out = $this->replacer()->replace($value);

        $this->assertStringContainsString('O:20:"Missing_Plugin_Class"', $out);
        $this->assertStringContainsString('s:17:"https://new.com/x"', $out);
    }

    public function testSerializedJsonInsideSerialized(): void
    {
        $value = serialize(['json' => json_encode(['u' => 'http://old.test/a'])]);
        $out = unserialize($this->replacer()->replace($value));
        $this->assertSame(['u' => 'https://new.com/a'], json_decode($out['json'], true));
    }

    public function testMultibyteContentInSerializedStrings(): void
    {
        $value = serialize(['t' => 'Şirketimiz için çözüm: http://old.test/ürün']);
        $out = unserialize($this->replacer()->replace($value));
        $this->assertSame(['t' => 'Şirketimiz için çözüm: https://new.com/ürün'], $out);
    }

    public function testServerPathsAreReplaced(): void
    {
        $value = serialize(['cache' => '/home/olduser/public_html/wp-content/cache']);
        $this->assertSame(['cache' => '/var/www/new.com/wp-content/cache'], unserialize($this->replacer()->replace($value)));
    }

    public function testNewUrlContainingOldUrlIsNotReplacedTwice(): void
    {
        $r = $this->replacer('https://old.test', 'https://old.test/blog');
        $this->assertSame('https://old.test/blog/a', $r->replace('https://old.test/a'));
    }

    public function testLocalhostWithSubfolderAndPort(): void
    {
        $r = $this->replacer('http://localhost:8080/mysite', 'https://example.com');
        $this->assertSame(
            'https://example.com/wp-content/x.jpg http://localhost:8080/mysite2',
            $r->replace('http://localhost:8080/mysite/wp-content/x.jpg http://localhost:8080/mysite2')
        );
    }

    public function testBrokenSerializedFallsBackToPlainReplace(): void
    {
        $broken = 'a:1:{s:3:"url";s:99:"http://old.test";}';
        $this->assertSame('a:1:{s:3:"url";s:99:"https://new.com";}', $this->replacer()->replace($broken));
    }

    public function testUnchangedValueIsReturnedAsIs(): void
    {
        $value = serialize(['a' => 'nothing here']);
        $this->assertSame($value, $this->replacer()->replace($value));
        $this->assertFalse($this->replacer()->matches($value));
    }
}

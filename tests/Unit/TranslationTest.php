<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Support\PoFile;

class TranslationTest extends TestCase
{
    private function dir()
    {
        return dirname(__DIR__, 2) . '/plugin/languages';
    }

    public function testEveryTemplateStringIsTranslatedWithTheSamePlaceholders(): void
    {
        $pot = PoFile::parse(str_replace('msgstr ""', 'msgstr "x"', file_get_contents($this->dir() . '/siteshifter.pot')));
        $tr = PoFile::parse(file_get_contents($this->dir() . '/siteshifter-tr_TR.po'));
        unset($pot['']);
        $this->assertNotEmpty($pot);
        foreach (array_keys($pot) as $id) {
            $this->assertArrayHasKey($id, $tr, "Missing Turkish translation: {$id}");
            preg_match_all('/%(?:\d+\$)?[sd]/', $id, $a);
            preg_match_all('/%(?:\d+\$)?[sd]/', $tr[$id], $b);
            $this->assertSame($a[0], $b[0], "Placeholders differ: {$id}");
        }
    }

    public function testCompiledFileMatchesThePoFile(): void
    {
        $po = PoFile::parse(file_get_contents($this->dir() . '/siteshifter-tr_TR.po'));
        $mo = PoFile::readMo(file_get_contents($this->dir() . '/siteshifter-tr_TR.mo'));
        $this->assertSame($po, $mo, 'Run php bin/make-mo.php after changing the .po file');
        $this->assertSame('Dışa aktar', $mo['Export']);
    }

    public function testTemplateIsUpToDateWithTheSource(): void
    {
        $before = file_get_contents($this->dir() . '/siteshifter.pot');
        exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/make-pot.php'), $o, $code);
        $after = file_get_contents($this->dir() . '/siteshifter.pot');
        file_put_contents($this->dir() . '/siteshifter.pot', $before);
        $this->assertSame(0, $code);
        $this->assertSame($before, $after, 'Run php bin/make-pot.php and translate the new strings');
    }
}

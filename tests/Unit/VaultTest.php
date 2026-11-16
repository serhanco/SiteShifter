<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Transfer\Vault;

class VaultTest extends TestCase
{
    public function testSealedValuesRoundTripAndNeedTheSiteKeys(): void
    {
        $file = sys_get_temp_dir() . '/ss-vault-' . bin2hex(random_bytes(4)) . '.php';
        $v = new Vault($file, str_repeat('k', 64));
        $this->assertNull($v->get('google'));
        $v->set('google', ['refresh_token' => 'very-secret-token', 'email' => 'a@b.c']);
        $this->assertSame('very-secret-token', $v->get('google')['refresh_token']);
        $raw = file_get_contents($file);
        $this->assertStringStartsWith('<?php exit;', $raw);
        $this->assertStringNotContainsString('very-secret-token', $raw);
        $this->assertNull((new Vault($file, str_repeat('x', 64)))->get('google'), 'other keys cannot read it');
        $v->delete('google');
        $this->assertNull($v->get('google'));
        unlink($file);
        $this->expectException(\InvalidArgumentException::class);
        new Vault($file, 'short');
    }
}

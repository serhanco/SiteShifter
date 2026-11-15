<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\AddressChangeTask;

class AddressChangeProblemTest extends TestCase
{
    /** @dataProvider cases */
    public function testProblem(string $old, string $new, bool $expectProblem): void
    {
        $this->assertSame($expectProblem, AddressChangeTask::problem($old, $new) !== '');
    }

    public function cases(): array
    {
        return [
            'http to https, same host' => ['http://a.com', 'https://a.com', false],
            'new domain' => ['https://a.com', 'https://b.com', false],
            'to www' => ['https://a.com', 'https://www.a.com', false],
            'unchanged' => ['https://a.com', 'https://a.com/', true],
            'sub-folder of old address' => ['https://a.com', 'https://a.com/blog', true],
            'from sub-folder to root is fine' => ['https://a.com/blog', 'https://a.com', false],
            'no scheme' => ['https://a.com', 'b.com', true],
            'ftp' => ['https://a.com', 'ftp://b.com', true],
        ];
    }

    public function testInvalidStartLeavesNoJobBehind(): void
    {
        $base = sys_get_temp_dir() . '/ss-inv-' . bin2hex(random_bytes(4));
        $store = new \SiteShifter\Plugin\Jobs\JobStore($base);
        $api = new \SiteShifter\Plugin\Rest\JobApi(new \SiteShifter\Plugin\Jobs\Runner($store, [
            'address_change' => static function ($job, $dir) {
                return new AddressChangeTask($dir, 'wp_', static function () {
                    throw new \RuntimeException('no db in unit test');
                });
            },
        ]));
        $r = $api->handle('start', ['type' => 'address_change', 'params' => ['old_url' => 'https://a.com', 'new_url' => 'https://a.com']]);
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid', $r['code']);
        $this->assertSame([], $store->all());
    }
}

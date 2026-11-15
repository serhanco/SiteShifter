<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Plugin\Jobs\HostTestTask;
use SiteShifter\Plugin\Jobs\JobStore;
use SiteShifter\Plugin\Jobs\Runner;
use SiteShifter\Plugin\Rest\JobApi;

class JobApiTest extends TestCase
{
    public function testStartStepStatusAndErrorsAreStructured(): void
    {
        $base = sys_get_temp_dir() . '/ss-api-' . bin2hex(random_bytes(4));
        $api = new JobApi(new Runner(new JobStore($base), [
            'host_test' => static function ($job, $dir) {
                return new HostTestTask($dir);
            },
        ]));

        $bad = $api->handle('start', ['type' => 'rm_rf']);
        $this->assertFalse($bad['ok']);
        $this->assertSame('unknown_type', $bad['code']);

        $start = $api->handle('start', ['type' => 'host_test', 'params' => ['mb' => 1]]);
        $this->assertTrue($start['ok']);
        $id = $start['job']['id'];
        $step = $api->handle('step', ['id' => $id]);
        $this->assertSame('done', $step['job']['status']);
        $this->assertSame('done', $api->handle('status', ['id' => $id])['job']['status']);

        $missing = $api->handle('status', ['id' => str_repeat('a', 32)]);
        $this->assertSame('not_found', $missing['code']);
        $this->assertSame('unknown_op', $api->handle('drop', [])['code']);

        (new JobStore($base))->delete($id);
    }
}

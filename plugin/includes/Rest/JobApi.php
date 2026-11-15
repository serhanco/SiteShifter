<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Rest;

use SiteShifter\Plugin\Jobs\Budget;
use SiteShifter\Plugin\Jobs\Runner;

defined('ABSPATH') || exit;

/**
 * Transport-independent job operations. The REST routes and the admin-ajax
 * fallback both call this, so the two paths can never behave differently.
 * Permission and nonce checks happen in the transports, before this runs.
 */
class JobApi
{
    /** @var Runner */
    private $runner;

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * @param string $op     start|step|status|resume|cancel
     * @param array  $input  already sanitised: type, id, params
     * @return array response body; always has 'ok'
     */
    public function handle($op, array $input)
    {
        try {
            switch ($op) {
                case 'start':
                    $type = isset($input['type']) ? (string) $input['type'] : '';
                    if (!in_array($type, $this->runner->types(), true)) {
                        return $this->fail('unknown_type', 'Unknown job type.');
                    }
                    $params = isset($input['params']) && is_array($input['params']) ? $input['params'] : [];

                    return ['ok' => true, 'job' => $this->runner->start($type, $params)];
                case 'step':
                    $deadline = Budget::deadline(
                        microtime(true),
                        (int) ini_get('max_execution_time'),
                        isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null
                    );

                    return ['ok' => true, 'job' => $this->runner->step($this->id($input), $deadline)];
                case 'status':
                    return ['ok' => true, 'job' => $this->runner->get($this->id($input))];
                case 'resume':
                    return ['ok' => true, 'job' => $this->runner->resume($this->id($input))];
                case 'cancel':
                    return ['ok' => true, 'job' => $this->runner->cancel($this->id($input))];
            }

            return $this->fail('unknown_op', 'Unknown operation.');
        } catch (\InvalidArgumentException $e) {
            return $this->fail('not_found', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('server_error', $e->getMessage());
        }
    }

    private function id(array $input)
    {
        return isset($input['id']) ? (string) $input['id'] : '';
    }

    private function fail($code, $message)
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}

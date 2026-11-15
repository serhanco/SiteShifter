<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * One kind of long-running work (export, import, address change, host test).
 * The Runner calls run() once per HTTP request until $state['done'] is true.
 *
 * Contract that keeps work safe on shared hosting:
 * - run() returns soon after $deadline and always makes some progress;
 * - run() only appends to the files named by checkpointFiles(); the Runner
 *   cuts them back to their last saved size before every slice, so a slice
 *   that died half way never leaves duplicate data behind;
 * - everything needed to continue lives in $state (plain arrays, JSON-safe).
 */
interface Task
{
    /** @return array initial state for the given job parameters */
    public function initialState(array $params);

    /** @return array the new state */
    public function run(array $state, $deadline);

    /** @return array{percent:int,phase:string} */
    public function progress(array $state);

    /** @return string[] absolute paths the task appends to */
    public function checkpointFiles(array $state);
}

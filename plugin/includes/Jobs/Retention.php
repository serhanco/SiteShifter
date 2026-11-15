<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

defined('ABSPATH') || exit;

/**
 * How long packages are kept. Off by default: nothing is deleted unless the
 * administrator turns it on.
 */
class Retention
{
    /** @var PackageLibrary */
    private $library;

    /** @var callable function (): mixed */
    private $read;

    /** @var callable function (array $value): void */
    private $write;

    public function __construct(PackageLibrary $library, $read, $write)
    {
        $this->library = $library;
        $this->read = $read;
        $this->write = $write;
    }

    /** @return array{enabled:bool,max_count:int,max_days:int} */
    public function get()
    {
        $v = call_user_func($this->read);
        $v = is_array($v) ? $v : [];

        return ['enabled' => !empty($v['enabled']), 'max_count' => max(0, min(1000, (int) ($v['max_count'] ?? 5))), 'max_days' => max(0, min(3650, (int) ($v['max_days'] ?? 30)))];
    }

    /** @return array the saved settings */
    public function set(array $in)
    {
        $new = ['enabled' => !empty($in['enabled']), 'max_count' => (int) ($in['max_count'] ?? 5), 'max_days' => (int) ($in['max_days'] ?? 30)];
        call_user_func($this->write, $new);

        return $this->get();
    }

    /** @return string[] ids deleted; [] when the clean-up is off */
    public function apply($now = null)
    {
        $s = $this->get();

        return $s['enabled'] ? $this->library->prune($s['max_count'], $s['max_days'], $now) : [];
    }
}

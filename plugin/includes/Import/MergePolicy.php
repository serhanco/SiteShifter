<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

defined('ABSPATH') || exit;

/**
 * The person's choices for what happens when the package and the site both have
 * the same thing. Unknown keys and values are rejected, never guessed.
 */
class MergePolicy
{
    const CHOICES = [
        'themes' => ['keep_newer', 'overwrite', 'skip'],
        'plugins' => ['keep_newer', 'overwrite', 'skip'],
        'media' => ['skip', 'overwrite', 'rename'],
        'other_files' => ['skip', 'overwrite'],
        'settings' => ['keep', 'overwrite'],
        'users' => ['map', 'skip'],
        'content' => ['merge', 'skip'],
        'posts' => ['skip', 'overwrite', 'rename'],
    ];

    /** @var array */
    private $values;

    /** @param array $given e.g. ['themes' => 'overwrite'] */
    public function __construct(array $given = [])
    {
        $this->values = [];
        foreach (self::CHOICES as $key => $allowed) {
            $v = isset($given[$key]) ? (string) $given[$key] : $allowed[0];
            if (!in_array($v, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown choice '{$v}' for '{$key}'.");
            }
            $this->values[$key] = $v;
        }
        foreach ($given as $key => $_) {
            if (!isset(self::CHOICES[$key])) {
                throw new \InvalidArgumentException("Unknown merge setting '{$key}'.");
            }
        }
    }

    public function get($key)
    {
        return $this->values[$key];
    }

    public function toArray()
    {
        return $this->values;
    }
}

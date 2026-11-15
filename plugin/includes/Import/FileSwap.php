<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

defined('ABSPATH') || exit;

/**
 * Puts staged wp-content folders live with renames only (instant, atomic per
 * folder, no second copy of the files) and keeps what they replace aside so it
 * can be put back. Themes and plugins are replaced child by child so that this
 * plugin, which is running right now, is never moved.
 */
class FileSwap
{
    /** Folders replaced child by child. */
    const BY_CHILD = ['plugins', 'themes'];

    /** @var string */
    private $live;

    /** @var string */
    private $stage;

    /** @var string */
    private $aside;

    /** @var string[] paths relative to wp-content that are never moved */
    private $protected;

    public function __construct($liveContentDir, $stageContentDir, $asideDir, array $protected = ['plugins/siteshifter'])
    {
        $this->live = rtrim(str_replace('\\', '/', $liveContentDir), '/');
        $this->stage = rtrim(str_replace('\\', '/', $stageContentDir), '/');
        $this->aside = rtrim(str_replace('\\', '/', $asideDir), '/');
        $this->protected = $protected;
    }

    /** @return array[] renames [from, to] in the order they are applied */
    public function plan()
    {
        $ops = [];
        foreach (self::children($this->stage) as $top) {
            if (!is_dir($this->stage . '/' . $top) || in_array($top, ['siteshifter-work'], true)) {
                continue; // loose files (index.php) are not part of the swap
            }
            $liveTop = $this->live . '/' . $top;
            if (in_array($top, self::BY_CHILD, true) && is_dir($liveTop)) {
                foreach (self::children($liveTop) as $child) {
                    if (!in_array($top . '/' . $child, $this->protected, true)) {
                        $ops[] = [$liveTop . '/' . $child, $this->aside . '/' . $top . '/' . $child];
                    }
                }
                foreach (self::children($this->stage . '/' . $top) as $child) {
                    if (!in_array($top . '/' . $child, $this->protected, true)) {
                        $ops[] = [$this->stage . '/' . $top . '/' . $child, $liveTop . '/' . $child];
                    }
                }
                continue;
            }
            if (is_dir($liveTop)) {
                $ops[] = [$liveTop, $this->aside . '/' . $top];
            }
            $ops[] = [$this->stage . '/' . $top, $liveTop];
        }

        return $ops;
    }

    /**
     * Performs the renames. If one fails, those already done are reverted and the error is thrown.
     *
     * @param array[] $ops
     *
     * @return array[] the renames that were done (for undo)
     */
    public function apply(array $ops)
    {
        $done = [];
        try {
            foreach ($ops as $op) {
                self::move($op[0], $op[1]);
                $done[] = $op;
            }
        } catch (\Throwable $e) {
            $this->undo($done);
            throw $e;
        }

        return $done;
    }

    /** Reverses renames, last first; tolerates what is already back. */
    public function undo(array $done)
    {
        foreach (array_reverse($done) as $op) {
            if (file_exists($op[1]) && !file_exists($op[0])) {
                self::move($op[1], $op[0]);
            }
        }
    }

    private static function move($from, $to)
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create folder {$dir} — check folder permissions.");
        }
        if (!@rename($from, $to)) {
            throw new \RuntimeException("Cannot move {$from} — check folder permissions.");
        }
    }

    /** @return string[] */
    private static function children($dir)
    {
        return array_values(array_diff((array) @scandir($dir), ['.', '..']));
    }
}

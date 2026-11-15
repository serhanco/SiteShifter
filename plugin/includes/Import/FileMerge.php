<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

defined('ABSPATH') || exit;

/**
 * Merge mode for files: nothing on the site is deleted. Each staged theme, plugin
 * folder and file is added when the site does not have it, and otherwise handled by
 * the person's policy. The plan is a list of renames, so FileSwap applies and undoes it.
 */
class FileMerge
{
    /** @var string */
    private $live;
    private $stage;
    private $aside;

    /** @var MergePolicy */
    private $policy;

    /** @var string[] */
    private $protected;

    public function __construct($liveContentDir, $stageContentDir, $asideDir, MergePolicy $policy, array $protected = ['plugins/siteshifter'])
    {
        $this->live = rtrim(str_replace('\\', '/', $liveContentDir), '/');
        $this->stage = rtrim(str_replace('\\', '/', $stageContentDir), '/');
        $this->aside = rtrim(str_replace('\\', '/', $asideDir), '/');
        $this->policy = $policy;
        $this->protected = $protected;
    }

    /**
     * @return array{ops:array[],report:array[]} ops are [from, to] renames; report rows describe each decision
     */
    public function plan()
    {
        $ops = [];
        $report = [];
        foreach (self::children($this->stage) as $top) {
            $s = $this->stage . '/' . $top;
            if (!is_dir($s) || $top === 'siteshifter-work') {
                continue;
            }
            if ($top === 'themes' || $top === 'plugins') {
                foreach (self::children($s) as $child) {
                    $this->folderItem($top, $child, $ops, $report);
                }
            } elseif ($top === 'uploads') {
                $this->walk($s, 'uploads', $this->policy->get('media'), $ops, $report, 'media');
            } else {
                $this->walk($s, $top, $this->policy->get('other_files'), $ops, $report, 'other');
            }
        }

        return ['ops' => $ops, 'report' => $report];
    }

    private function folderItem($top, $child, array &$ops, array &$report)
    {
        $rel = $top . '/' . $child;
        $kind = $top;
        if (in_array($rel, $this->protected, true)) {
            $report[] = ['kind' => $kind, 'item' => $child, 'action' => 'protected'];

            return;
        }
        $from = $this->stage . '/' . $rel;
        $to = $this->live . '/' . $rel;
        if (!file_exists($to)) {
            $ops[] = [$from, $to];
            $report[] = ['kind' => $kind, 'item' => $child, 'action' => 'add'];

            return;
        }
        $policy = $this->policy->get($top);
        $new = self::version($from, $top);
        $old = self::version($to, $top);
        if ($policy === 'keep_newer') {
            $policy = ($new !== '' && ($old === '' || version_compare($new, $old, '>'))) ? 'overwrite' : 'skip';
        }
        if ($policy === 'overwrite') {
            $ops[] = [$to, $this->aside . '/' . $rel];
            $ops[] = [$from, $to];
            $report[] = ['kind' => $kind, 'item' => $child, 'action' => 'overwrite', 'old' => $old, 'new' => $new];
        } else {
            $report[] = ['kind' => $kind, 'item' => $child, 'action' => 'skip', 'old' => $old, 'new' => $new];
        }
    }

    /** Files below $dir (relative path $rel under wp-content), one decision per file; whole new folders move in one rename. */
    private function walk($dir, $rel, $policy, array &$ops, array &$report, $kind)
    {
        foreach (self::children($dir) as $name) {
            $from = $dir . '/' . $name;
            $r = $rel . '/' . $name;
            $to = $this->live . '/' . $r;
            if (is_dir($from) && !is_link($from)) {
                if (!file_exists($to)) {
                    $ops[] = [$from, $to];
                    $report[] = ['kind' => $kind, 'item' => $r . '/', 'action' => 'add'];
                } elseif (is_dir($to)) {
                    $this->walk($from, $r, $policy, $ops, $report, $kind);
                }
                continue;
            }
            if (!file_exists($to)) {
                $ops[] = [$from, $to];
                $report[] = ['kind' => $kind, 'item' => $r, 'action' => 'add'];
            } elseif ($policy === 'overwrite') {
                $ops[] = [$to, $this->aside . '/' . $r];
                $ops[] = [$from, $to];
                $report[] = ['kind' => $kind, 'item' => $r, 'action' => 'overwrite'];
            } elseif ($policy === 'rename') {
                $new = self::freeName($to, $ops);
                $ops[] = [$from, $new];
                $report[] = ['kind' => $kind, 'item' => $r, 'action' => 'rename', 'as' => basename($new)];
            } else {
                $report[] = ['kind' => $kind, 'item' => $r, 'action' => 'skip'];
            }
        }
    }

    /** photo.jpg -> photo-2.jpg, photo-3.jpg ... first name that is free on the site and not yet planned. */
    private static function freeName($path, array $ops)
    {
        $taken = array_column($ops, 1);
        $info = pathinfo($path);
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        for ($i = 2; ; $i++) {
            $candidate = $info['dirname'] . '/' . $info['filename'] . '-' . $i . $ext;
            if (!file_exists($candidate) && !in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /** Version from the theme's style.css or the plugin's main file header; '' when unknown. */
    public static function version($dir, $top)
    {
        $files = $top === 'themes' ? [$dir . '/style.css'] : (array) glob($dir . '/*.php');
        if (!is_dir($dir)) {
            $files = [$dir];
        }
        foreach ($files as $f) {
            $head = is_file($f) ? (string) @file_get_contents($f, false, null, 0, 8192) : '';
            if ($head !== '' && ($top === 'themes' || stripos($head, 'Plugin Name:') !== false)
                && preg_match('/^[ \t\/*#@]*Version:\s*(\S+)/mi', $head, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    /** @return string[] */
    private static function children($dir)
    {
        return array_values(array_diff((array) @scandir($dir), ['.', '..']));
    }
}

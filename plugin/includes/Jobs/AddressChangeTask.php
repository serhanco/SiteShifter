<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Database\Connection;
use SiteShifter\Database\DatabaseReplacer;
use SiteShifter\Database\Dumper;
use SiteShifter\Database\Verifier;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Replace\Replacer;

defined('ABSPATH') || exit;

/**
 * Changes the site address in the database (http to https, new domain, www).
 * Phases: backup (full SQL dump = the rollback point) -> replace (serialized-safe,
 * every table) -> verify (leftover scan) -> done. Each slice is resumable and
 * the replace step is idempotent, so a repeated slice does no harm.
 */
class AddressChangeTask implements Task
{
    /** @var string job folder */
    private $dir;

    /** @var callable function (): Connection */
    private $connect;

    /** @var string */
    private $prefix;

    public function __construct($dir, $prefix, $connect)
    {
        $this->dir = $dir;
        $this->prefix = $prefix;
        $this->connect = $connect;
    }

    public function backupFile()
    {
        return $this->dir . '/backup.sql';
    }

    /** Why these two addresses cannot be swapped safely, or '' when fine. */
    public static function problem($old, $new)
    {
        $o = parse_url(trim($old));
        $n = parse_url(trim($new));
        if (empty($o['host']) || empty($n['host']) || !in_array(strtolower($n['scheme'] ?? ''), ['http', 'https'], true)) {
            return 'Both addresses must start with http:// or https:// and contain a host name.';
        }
        $oldRest = strtolower($o['host'] . (isset($o['port']) ? ':' . $o['port'] : '') . rtrim($o['path'] ?? '', '/'));
        $newRest = strtolower($n['host'] . (isset($n['port']) ? ':' . $n['port'] : '') . rtrim($n['path'] ?? '', '/'));
        if ($oldRest === $newRest && strtolower($o['scheme'] ?? '') === strtolower($n['scheme'] ?? '')) {
            return 'The new address is the same as the current one.';
        }
        if (strpos($newRest . '/', $oldRest . '/') === 0 && $newRest !== $oldRest) {
            return 'Moving the site into a sub-folder of its current address is not supported yet.';
        }

        return '';
    }

    public function initialState(array $params)
    {
        $old = rtrim((string) ($params['old_url'] ?? ''), '/');
        $new = rtrim((string) ($params['new_url'] ?? ''), '/');
        $problem = self::problem($old, $new);
        if ($problem !== '') {
            throw new \InvalidArgumentException($problem);
        }

        return [
            'old' => $old,
            'new' => $new,
            'phase' => 'backup',
            'backup' => Dumper::initialState(),
            'replace' => DatabaseReplacer::initialState(),
            'verify' => ['table' => 0, 'left' => []],
            'done' => false,
        ];
    }

    public function run(array $state, $deadline)
    {
        $db = call_user_func($this->connect);
        $tables = $db->tables($this->prefix);

        $didWork = false; // every slice must make progress, even with the deadline already past
        if ($state['phase'] === 'backup') {
            $didWork = true;
            $state['backup'] = (new Dumper($db, $tables, $this->backupFile()))->run($state['backup'], $deadline);
            if ($state['backup']['done']) {
                $state['phase'] = 'replace';
            }
        }
        if ($state['phase'] === 'replace' && (!$didWork || microtime(true) < $deadline)) {
            $didWork = true;
            $map = (new ReplacementMap())->addUrl($state['old'], $state['new']);
            $replacer = new DatabaseReplacer($db, new Replacer($map), $tables, [$this->prefix . 'posts' => ['guid']]);
            $state['replace'] = $replacer->run($state['replace'], $deadline);
            if ($state['replace']['done']) {
                $state['phase'] = 'verify';
            }
        }
        if ($state['phase'] === 'verify') {
            $verifier = new Verifier($db);
            $state['verify']['total'] = count($tables);
            $host = preg_replace('#^https?://#i', '', $state['old']);
            $first = true;
            while ($state['verify']['table'] < count($tables) && ($first || microtime(true) < $deadline)) {
                $first = false;
                $table = $tables[$state['verify']['table']];
                foreach ($verifier->leftovers([$table], [$host], [$this->prefix . 'posts' => ['guid']]) as $l) {
                    $state['verify']['left'][] = $l;
                }
                $state['verify']['table']++;
            }
            if ($state['verify']['table'] >= count($tables)) {
                $state['phase'] = 'done';
                $state['done'] = true;
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }
        }

        return $state;
    }

    public function progress(array $state)
    {
        if ($state['done']) {
            $changed = 0;
            foreach ($state['replace']['stats'] as $st) {
                $changed += (int) $st['changed'];
            }
            $left = [];
            foreach (array_slice($state['verify']['left'], 0, 20) as $l) {
                $left[] = ['table' => $l['table'], 'column' => $l['column'], 'kind' => $l['kind'], 'count' => $l['count']];
            }

            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => [
                'old' => $state['old'],
                'new' => $state['new'],
                'rows_changed' => $changed,
                'leftovers' => $left,
                'leftover_total' => count($state['verify']['left']),
            ]];
        }
        // backup 25%, replace 55%, verify 20%
        if ($state['phase'] === 'backup') {
            return ['percent' => (int) floor(Dumper::percent($state['backup']) * 0.25), 'phase' => 'backup', 'detail' => 'backup'];
        }
        if ($state['phase'] === 'replace') {
            return ['percent' => 25 + (int) floor(DatabaseReplacer::percent($state['replace']) * 0.55), 'phase' => 'replace', 'detail' => 'replace'];
        }
        $total = max(1, (int) ($state['verify']['total'] ?? 0));

        return ['percent' => 80 + (int) min(19, floor(20 * $state['verify']['table'] / max(1, $total))), 'phase' => 'verify', 'detail' => 'verify'];
    }

    public function checkpointFiles(array $state)
    {
        return [$this->backupFile()];
    }
}

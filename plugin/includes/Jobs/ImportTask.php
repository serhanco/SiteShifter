<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Database\Connection;
use SiteShifter\Database\DatabaseReplacer;
use SiteShifter\Database\Importer;
use SiteShifter\Database\PrefixMap;
use SiteShifter\Database\Verifier;
use SiteShifter\Package\Extractor;
use SiteShifter\Package\Manifest;
use SiteShifter\Plugin\Import\DbSwap;
use SiteShifter\Plugin\Import\DbMerge;
use SiteShifter\Plugin\Import\FileMerge;
use SiteShifter\Plugin\Import\FileSwap;
use SiteShifter\Plugin\Import\MergePolicy;
use SiteShifter\Preflight\Preflight;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Replace\Replacer;

defined('ABSPATH') || exit;

/**
 * Imports a SiteShifter package in Replace mode without ever breaking the site
 * that is running the import: everything is built next to the live site (database
 * tables under a staging prefix, files in a staging folder), checked, and only then
 * put live in one quick swap. Before the swap nothing of the live site has changed.
 *
 * Phases: unpack -> extract -> import -> rewrite -> finalize -> verify -> swap -> done
 */
class ImportTask implements Task
{
    const WEIGHTS = ['unpack' => 3, 'extract' => 30, 'import' => 35, 'rewrite' => 20, 'finalize' => 2, 'verify' => 5, 'swap' => 5, 'done' => 0];

    /** Drop-ins that point at server-specific services: kept aside, re-enable after the move. */
    const DISABLED_DROPINS = ['wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/db.php'];

    /** @var string */
    private $dir;

    /** @var array */
    private $env;

    /**
     * @param string $dir job folder
     * @param array  $env zip, content_dir, abspath, home, siteurl, prefix, plugin_file, connect (callable), inspect (callable), after_swap (callable|null)
     */
    public function __construct($dir, array $env)
    {
        $this->dir = rtrim($dir, '/');
        $this->env = $env;
    }

    public function initialState(array $params)
    {
        $check = call_user_func($this->env['inspect'], $this->env['zip']);
        if (empty($check['ok']) || !empty($check['blockers'])) {
            $why = [];
            foreach ((array) ($check['rows'] ?? []) as $r) {
                if ($r['level'] === 'block') {
                    $why[] = $r['title'] . ': ' . $r['message'];
                }
            }
            throw new \InvalidArgumentException('The package cannot be imported: ' . implode(' ', $why));
        }
        $mode = isset($params['mode']) && $params['mode'] === 'merge' ? 'merge' : 'replace';
        $policy = new MergePolicy(isset($params['policy']) && is_array($params['policy']) ? $params['policy'] : []);
        $manifest = json_decode((string) Extractor::readEntry($this->env['zip'], ['strip' => '', 'target' => ''], Manifest::FILE), true);
        $id = basename($this->dir);
        $tag = substr($id, 0, 6);

        return [
            'phase' => 'unpack',
            'mode' => $mode,
            'policy' => $policy->toArray(),
            'manifest' => [
                'site' => $manifest['site'],
                'db' => ['tables' => $manifest['db']['tables'] ?? [], 'collations' => $manifest['db']['collations'] ?? [], 'db_version' => $manifest['db']['db_version'] ?? null],
                'scope' => $manifest['scope'] ?? [],
            ],
            'full' => empty($manifest['scope']['profile']) || $manifest['scope']['profile'] === 'full',
            'stage_prefix' => 'ssi' . $tag . '_',
            'backup_prefix' => 'ssb' . $tag . '_',
            'extract' => Extractor::initialState(),
            'import' => Importer::initialState(),
            'replace' => DatabaseReplacer::initialState(),
            'verify' => null,
            'imported_rows' => [],
            'result' => null,
            'done' => false,
        ];
    }

    public function run(array $state, $deadline)
    {
        $first = true; // always make progress, even when the deadline has already passed
        while (!$state['done'] && ($first || microtime(true) < $deadline)) {
            $first = false;
            $method = 'phase' . ucfirst($state['phase']);
            if ($this->$method($state, $deadline)) {
                $names = array_keys(self::WEIGHTS);
                $state['phase'] = $names[array_search($state['phase'], $names, true) + 1];
                $state['done'] = $state['phase'] === 'done';
            }
        }

        return $state;
    }

    public function progress(array $state)
    {
        $phase = $state['phase'];
        $p = 0;
        $detail = '';
        switch ($phase) {
            case 'extract':
                $p = Extractor::percent($state['extract']);
                $detail = !empty($state['extract']['total']) ? number_format($state['extract']['index']) . ' / ' . number_format($state['extract']['total']) : '';
                break;
            case 'import':
                $p = Importer::percent($state['import']);
                break;
            case 'rewrite':
                $p = DatabaseReplacer::percent($state['replace']);
                break;
            case 'verify':
                $v = $state['verify'] ?: ['index' => 0, 'total' => 0];
                $p = \SiteShifter\Support\Progress::percent($v['index'], $v['total']);
                break;
            case 'done':
                $p = 100;
        }
        $done = 0;
        foreach (self::WEIGHTS as $name => $w) {
            if ($name === $phase) {
                $done += $w * $p / 100;
                break;
            }
            $done += $w;
        }
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => $state['result']];
        }

        return ['percent' => (int) min(99, floor(100 * $done / array_sum(self::WEIGHTS))), 'phase' => $phase, 'detail' => $detail];
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }

    // ------------------------------------------------------------- phases

    private function phaseUnpack(array &$state)
    {
        @mkdir($this->dir . '/stage', 0755, true);
        $target = $this->dir . '/database.sql';
        $zip = new \ZipArchive();
        if ($zip->open($this->env['zip']) !== true) {
            throw new \RuntimeException('Cannot open the package (zip error). The file may be incomplete — upload it again.');
        }
        $in = $zip->getStream(Manifest::SQL);
        $out = $in === false ? false : @fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Cannot write ' . $target . ' — check folder permissions.');
        }
        if (stream_copy_to_stream($in, $out) === false) {
            throw new \RuntimeException('Writing the database file failed — disk is full?');
        }
        fclose($in);
        fclose($out);
        $zip->close();

        return true;
    }

    private function phaseExtract(array &$state, $deadline)
    {
        $layout = ['strip' => 'files/', 'target' => '', 'rename' => [], 'skip_prefixes' => [], 'skip_regex' => '#^(?!wp-content/)#'];
        foreach (self::DISABLED_DROPINS as $dropin) {
            $layout['rename'][$dropin] = $dropin . '.siteshifter-disabled';
        }
        $layout['skip_prefixes'] = array_merge(
            array_map(static function ($e) {
                return $e . '/';
            }, \SiteShifter\Package\Archiver::DEFAULT_EXCLUDES),
            ['wp-content/' . $this->protectedPlugin() . '/', 'wp-content/siteshifter-work/']
        );
        $state['extract'] = (new Extractor($this->env['zip'], $this->dir . '/stage', $layout))->run($state['extract'], $deadline);

        return $state['extract']['done'];
    }

    private function phaseImport(array &$state, $deadline)
    {
        $db = call_user_func($this->env['connect']);
        $marker = $this->dir . '/import.running';
        if (is_file($marker)) {
            // A slice died half way: its statements are in the database but not in the saved
            // position. The staging tables are private, so start the import over.
            DbSwap::drop($db, $db->tables($state['stage_prefix']));
            $state['import'] = Importer::initialState();
        }
        file_put_contents($marker, '1');
        $available = array_flip(array_column($db->rows('SHOW COLLATION'), 'Collation'));
        $plan = Preflight::collationPlan($state['manifest']['db']['collations'], $available);
        $map = new PrefixMap($state['manifest']['site']['table_prefix'], $state['stage_prefix']);
        $state['import'] = (new Importer($db, $this->dir . '/database.sql', $plan['convert']))->withPrefixMap($map)->run($state['import'], $deadline);
        @unlink($marker);
        if ($state['import']['done']) {
            $state['imported_rows'] = (new Verifier($db))->rowCounts($db->tables($state['stage_prefix']));
            @unlink($this->dir . '/database.sql');
        }

        return $state['import']['done'];
    }

    private function phaseRewrite(array &$state, $deadline)
    {
        $db = call_user_func($this->env['connect']);
        $stage = $state['stage_prefix'];
        $job = new DatabaseReplacer($db, new Replacer($this->replacementMap($state)), $db->tables($stage), [$stage . 'posts' => ['guid']]);
        $state['replace'] = $job->run($state['replace'], $deadline);

        return $state['replace']['done'];
    }

    private function phaseFinalize(array &$state)
    {
        $db = call_user_func($this->env['connect']);
        $stage = $state['stage_prefix'];
        $src = $state['manifest']['site']['table_prefix'];
        $final = $this->env['prefix'];
        $tables = array_flip($db->tables($stage));
        if (isset($tables[$stage . 'options'])) {
            $o = Connection::ident($stage . 'options');
            (new PrefixMap($src, $stage))->fixData($db, $stage . 'options', $stage . 'usermeta', $final);
            $db->exec("UPDATE {$o} SET option_value = " . $db->quote($this->env['home']) . " WHERE option_name = 'home'");
            $db->exec("UPDATE {$o} SET option_value = " . $db->quote($this->env['siteurl']) . " WHERE option_name = 'siteurl'");
            // Caches that hold old URLs and are rebuilt automatically.
            $db->exec("DELETE FROM {$o} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'");
            $db->exec("DELETE FROM {$o} WHERE option_name IN ('_elementor_global_css', 'elementor-custom-breakpoints-files', '_elementor_assets_data')");
            $this->keepThisPluginActive($db, $o);
        }
        if (isset($tables[$stage . 'postmeta'])) {
            $db->exec('DELETE FROM ' . Connection::ident($stage . 'postmeta') . " WHERE meta_key = '_elementor_css'");
        }
        self::removeFiles($this->dir . '/stage/wp-content/uploads/elementor/css', '/\.css$/');
        self::removeFiles($this->dir . '/stage/wp-content/wp-rocket-config', '/\.php$/');

        return true;
    }

    /** The imported settings may not list this plugin; without it the next request could not continue or undo. */
    private function keepThisPluginActive(Connection $db, $optionsTable)
    {
        $raw = $db->value("SELECT option_value FROM {$optionsTable} WHERE option_name = 'active_plugins' LIMIT 1");
        $list = $raw === null ? [] : @unserialize((string) $raw, ['allowed_classes' => false]);
        $list = is_array($list) ? $list : [];
        if (!in_array($this->env['plugin_file'], $list, true)) {
            $list[] = $this->env['plugin_file'];
            $new = serialize(array_values($list));
            if ($raw === null) {
                $db->exec("INSERT INTO {$optionsTable} (option_name, option_value, autoload) VALUES ('active_plugins', " . $db->quote($new) . ", 'yes')");
            } else {
                $db->exec("UPDATE {$optionsTable} SET option_value = " . $db->quote($new) . " WHERE option_name = 'active_plugins'");
            }
        }
    }

    private function phaseVerify(array &$state, $deadline)
    {
        $db = call_user_func($this->env['connect']);
        $stage = $state['stage_prefix'];
        $tables = $db->tables($stage);
        $verifier = new Verifier($db);
        $manifest = $state['manifest'];
        $newHost = (string) parse_url($this->env['home'], PHP_URL_HOST);
        $hosts = array_values(array_filter(array_unique(Manifest::oldHosts($manifest)), static function ($h) use ($newHost) {
            return $h !== $newHost;
        }));
        if ($state['verify'] === null) {
            $state['verify'] = ['index' => 0, 'total' => count($tables), 'leftovers' => []];
        }
        while ($state['verify']['index'] < count($tables)) {
            if ($hosts !== []) {
                $state['verify']['leftovers'] = array_merge($state['verify']['leftovers'], $verifier->leftovers([$tables[$state['verify']['index']]], $hosts, [$stage . 'posts' => ['guid']]));
            }
            $state['verify']['index']++;
            if (microtime(true) >= $deadline) {
                return false;
            }
        }
        $src = $manifest['site']['table_prefix'];
        $source = $manifest['db']['tables'];
        $state['result'] = [
            'leftovers' => $state['verify']['leftovers'],
            'row_mismatches' => $source === [] ? [] : Verifier::compareRowCounts($source, $state['imported_rows'], $src, $stage),
            'replace_stats' => $state['replace']['stats'] ?? null,
            'tables' => count($tables),
            'swapped' => false,
        ];

        return true;
    }

    /** The only step that touches the live site, and it is a few renames. */
    private function phaseSwap(array &$state)
    {
        if ($state['mode'] === 'merge') {
            return $this->mergeSwap($state);
        }
        $db = call_user_func($this->env['connect']);
        $stage = $state['stage_prefix'];
        $files = new FileSwap($this->env['content_dir'], $this->dir . '/stage/wp-content', $this->dir . '/aside', [$this->protectedPlugin()]);
        $prior = $this->readSwap();

        if ($prior && $db->tables($stage) === [] && !empty($prior['pairs'])) {
            // An earlier attempt got as far as the database rename and stopped before saying so: do not redo it.
            $plan = ['pairs' => $prior['pairs'], 'backups' => $prior['backups']];
        } else {
            $plan = DbSwap::plan($db, $this->env['prefix'], $stage, $state['backup_prefix'], $state['full']);
            // An earlier attempt may already have moved the files: keep them in the record for undo.
            $done = $prior && $prior['state'] === 'swapping' ? (array) $prior['files'] : [];
            $record = ['pairs' => $plan['pairs'], 'backups' => $plan['backups'], 'stage_prefix' => $stage, 'files' => $done, 'state' => 'swapping'];
            $this->writeSwap($record); // written first: whatever happens next, undo knows what was done
            $done = array_merge($done, $files->apply($files->plan()));
            $record['files'] = $done;
            $this->writeSwap($record);
            try {
                DbSwap::apply($db, $plan['pairs']);
            } catch (\Throwable $e) {
                $files->undo($done);
                $record['state'] = 'failed';
                $record['files'] = [];
                $this->writeSwap($record);
                throw $e;
            }
        }
        $record = ['pairs' => $plan['pairs'], 'backups' => $plan['backups'], 'stage_prefix' => $stage, 'files' => isset($done) ? $done : (array) $prior['files'], 'state' => 'live'];
        $this->writeSwap($record);
        if (!empty($this->env['after_swap'])) {
            call_user_func($this->env['after_swap']);
        }
        $state['result']['swapped'] = true;
        $state['result']['can_undo'] = true;
        $state['result']['backup_tables'] = count($plan['backups']);

        return true;
    }

    /** Merge mode: nothing is removed; files and the few database rows are added or replaced by policy. */
    private function mergeSwap(array &$state)
    {
        $db = call_user_func($this->env['connect']);
        $stage = $state['stage_prefix'];
        $prior = $this->readSwap();
        if (!$prior || $prior['state'] !== 'live') {
            $swap = new FileSwap('', '', '');
            if ($prior && $prior['state'] === 'swapping') {
                $swap->undo((array) $prior['files']); // an earlier attempt died: start from the original state
            }
            $merge = new FileMerge($this->env['content_dir'], $this->dir . '/stage/wp-content', $this->dir . '/aside', new MergePolicy($state['policy']), [$this->protectedPlugin()]);
            $plan = $merge->plan();
            $record = ['mode' => 'merge', 'pairs' => [], 'backups' => [], 'copies' => [], 'stage_prefix' => $stage, 'files' => [], 'state' => 'swapping', 'files_report' => $plan['report']];
            $this->writeSwap($record);
            $record['files'] = $swap->apply($plan['ops']);
            $this->writeSwap($record);
            try {
                $r = DbMerge::apply($db, $stage, $this->env['prefix'], $state['backup_prefix'], new MergePolicy($state['policy']));
            } catch (\Throwable $e) {
                $swap->undo($record['files']);
                $record['state'] = 'failed';
                $record['files'] = [];
                $this->writeSwap($record);
                throw $e;
            }
            $record['copies'] = $r['copies'];
            $record['backups'] = array_column($r['copies'], 1);
            $record['stats'] = $r['stats'];
            $record['user_map'] = $r['user_map'];
            $record['flags'] = $r['flags'];
            $record['content'] = $r['content'];
            $record['state'] = 'live';
            $this->writeSwap($record);
            $prior = $record;
        }
        if (!empty($this->env['after_swap'])) {
            call_user_func($this->env['after_swap']);
        }
        $counts = [];
        foreach ((array) $prior['files_report'] as $row) {
            $counts[$row['action']] = ($counts[$row['action']] ?? 0) + 1;
        }
        $state['result']['swapped'] = true;
        $state['result']['can_undo'] = true;
        $state['result']['mode'] = 'merge';
        $state['result']['merge'] = ['files' => $counts, 'database' => $prior['stats'] ?? [], 'content_merged' => !empty($prior['content']), 'flags' => $prior['flags'] ?? []];

        return true;
    }

    private function protectedPlugin()
    {
        return 'plugins/' . dirname($this->env['plugin_file']);
    }

    private function readSwap()
    {
        $file = $this->dir . '/swap.json';
        $r = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($r) ? $r : null;
    }

    private function replacementMap(array $state)
    {
        $site = $state['manifest']['site'];
        $map = new ReplacementMap();
        $oldHome = (string) ($site['home'] ?: $site['url']);
        $oldSiteurl = (string) ($site['url'] ?: $oldHome);
        if ($oldSiteurl !== $oldHome) {
            $map->addUrl($oldSiteurl, $this->env['siteurl']);
        }
        $map->addUrl($oldHome, $this->env['home']);
        if (!empty($site['abspath'])) {
            $map->addPath($site['abspath'], rtrim($this->env['abspath'], '/') . '/');
        }

        return $map;
    }

    private function writeSwap(array $record)
    {
        if (file_put_contents($this->dir . '/swap.json', json_encode($record, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot save the swap record (disk is full?).');
        }
    }

    private static function removeFiles($dir, $pattern)
    {
        foreach ((array) @scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && preg_match($pattern, $f) && is_file($dir . '/' . $f)) {
                @unlink($dir . '/' . $f);
            }
        }
    }
}

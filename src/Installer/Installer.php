<?php

namespace SiteShifter\Installer;

use SiteShifter\Database\Connection;
use SiteShifter\Database\DatabaseReplacer;
use SiteShifter\Database\Importer;
use SiteShifter\Database\SqlInspector;
use SiteShifter\Database\Verifier;
use SiteShifter\Package\Extractor;
use SiteShifter\Package\Manifest;
use SiteShifter\Preflight\Preflight;
use SiteShifter\Preflight\Report;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Replace\Replacer;

/**
 * Installs a site from a SiteShifter package or from a hand-made export
 * (a zip of public_html or wp-content plus a .sql dump), step by step.
 *
 * Every step is resumable: run() does as much as fits before the deadline
 * and returns the state, which the caller stores and passes back. The
 * web installer calls it over AJAX; the CLI calls it in a loop.
 *
 * Settings:
 *  - dir          site root on this server
 *  - zip          path of the package or files zip (optional if files are already in place)
 *  - sql          path of a .sql file (optional when the zip contains one)
 *  - db           [host, user, pass, name]
 *  - new_url      the new site address
 *  - old_url      override for the old address (defaults to the one in the dump)
 *  - old_path     override for the old server path (guessed from the dump when missing)
 *  - core_zip     a local WordPress zip to use instead of downloading one
 */
class Installer
{
    public const STEPS = ['backup', 'extract', 'core', 'config', 'import', 'replace', 'finalize', 'verify', 'done'];

    /** Drop-ins that point at server-specific services; moved aside, re-enable after the move. */
    public const DISABLED_DROPINS = ['wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/db.php'];

    /** @var array */
    private $settings;

    /** @var string */
    private $dir;

    /** @var string */
    private $work;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->dir = rtrim(str_replace('\\', '/', $settings['dir']), '/');
        $this->work = $this->dir . '/siteshifter-work';
    }

    /**
     * Reads the sources and returns everything the pre-flight check and the
     * UI need: layout of the zip, the manifest (real or inferred from the
     * SQL), sizes.
     */
    public function inspect(): array
    {
        $zip = $this->settings['zip'] ?? null;
        $layout = null;
        $manifest = null;
        $sqlInZip = null;
        $sizes = ['package' => 0, 'unpacked' => 0];

        if ($zip) {
            $names = Extractor::entryNames($zip);
            $layout = Extractor::detectLayout($names);
            $sizes['package'] = (int) filesize($zip);
            $sizes['unpacked'] = Extractor::uncompressedSize($zip);
            if (in_array(Manifest::FILE, $names, true)) {
                $manifest = json_decode((string) Extractor::readEntry($zip, ['strip' => '', 'target' => ''], Manifest::FILE), true);
            }
            if (in_array(Manifest::SQL, $names, true)) {
                $sqlInZip = Manifest::SQL;
            } elseif ($layout['sql'] !== []) {
                $sqlInZip = $layout['sql'][0];
            }
        }

        $sql = $this->settings['sql'] ?? null;
        if ($manifest === null) {
            $sqlFile = $sql ?: ($sqlInZip ? $this->extractSql($zip, $sqlInZip) : null);
            if ($sqlFile === null) {
                throw new \RuntimeException('No database export found. Upload a .sql file, or a zip that contains one.');
            }
            $manifest = (new SqlInspector())->inspect($sqlFile);
            $manifest['site']['abspath'] = $this->settings['old_path'] ?? self::guessOldPath($sqlFile);
            $manifest['generator'] = 'manual';
        }

        return [
            'type' => ($manifest['generator'] ?? '') === 'SiteShifter' ? 'package' : 'manual',
            'layout' => $layout,
            'sql_in_zip' => $sqlInZip,
            'manifest' => $manifest,
            'sizes' => $sizes,
        ];
    }

    public function preflight(array $inspection, ?Preflight $preflight = null): Report
    {
        $preflight = $preflight ?: new Preflight();
        $db = null;
        $error = null;
        try {
            $db = $this->connect();
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        $zip = $this->settings['zip'] ?? null;
        $layout = $inspection['layout'];

        $core = $this->targetCore($inspection);
        $report = $preflight->run([
            'target_wp_version' => $core['wp_version'],
            'target_wp_db_version' => $core['wp_db_version'],
            'target_dir' => $this->dir,
            'new_url' => $this->settings['new_url'] ?? '',
            'package_bytes' => $inspection['sizes']['package'],
            'unpacked_bytes' => $inspection['sizes']['unpacked'] + ($layout && !$layout['has_core'] ? 80 * 1048576 : 0),
            'needs_zip' => $zip !== null,
            'manifest' => $inspection['manifest'],
            'db' => $db,
            'db_error' => $error,
            'file_reader' => ($zip && $layout) ? static function (string $file) use ($zip, $layout) {
                return Extractor::readEntry($zip, $layout, $file);
            } : null,
        ]);

        if (empty($inspection['manifest']['site']['url']) && empty($this->settings['old_url'])) {
            $report->add(new \SiteShifter\Preflight\Result('old_url', 'block', 'Old site address', 'The old address could not be read from the database export.', 'Enter the old site address manually.'));
        }
        if (empty($inspection['manifest']['site']['table_prefix'])) {
            $report->add(new \SiteShifter\Preflight\Result('table_prefix', 'block', 'Table prefix', 'No WordPress tables (options, posts, postmeta) were found in the export.', 'Check that the .sql file is the WordPress database.'));
        }
        if ($layout && !$layout['has_core']) {
            $report->add(new \SiteShifter\Preflight\Result('core', 'info', 'WordPress core', 'The zip has no WordPress core files; they will be downloaded from wordpress.org' . (!empty($inspection['manifest']['site']['wp_version']) ? ' (version ' . $inspection['manifest']['site']['wp_version'] . ')' : '') . '.'));
        }

        return $report;
    }

    /**
     * The WordPress core that will run the site: the one in the zip, an
     * existing one in the folder (kept), or the version to be downloaded.
     *
     * @return array{wp_version:?string,wp_db_version:?int,source:string}
     */
    public function targetCore(array $inspection): array
    {
        $zip = $this->settings['zip'] ?? null;
        $layout = $inspection['layout'];
        if ($zip && $layout && $layout['has_core']) {
            $info = Manifest::parseVersionFile((string) Extractor::readEntry($zip, $layout, 'wp-includes/version.php'));

            return $info + ['source' => 'package'];
        }
        if (is_file($this->dir . '/wp-includes/version.php')) {
            return Manifest::versionFile($this->dir) + ['source' => 'existing'];
        }
        if (!empty($this->settings['core_zip'])) {
            $code = Extractor::readEntry($this->settings['core_zip'], ['strip' => 'wordpress/', 'target' => ''], 'wp-includes/version.php');

            return Manifest::parseVersionFile((string) $code) + ['source' => 'core_zip'];
        }

        return ['wp_version' => $inspection['manifest']['site']['wp_version'] ?? null, 'wp_db_version' => null, 'source' => 'download'];
    }

    public static function initialState(array $inspection): array
    {
        return [
            'step' => 'backup',
            'inspection' => $inspection,
            'extract' => Extractor::initialState(),
            'import' => Importer::initialState(),
            'replace' => DatabaseReplacer::initialState(),
            'backup_tables' => [],
            'log' => [],
            'result' => null,
            'done' => false,
        ];
    }

    public function run(array $state, float $deadline): array
    {
        while (!$state['done'] && microtime(true) < $deadline) {
            $method = 'step' . ucfirst($state['step']);
            $finished = $this->$method($state, $deadline);
            if ($finished) {
                $state['log'][] = 'Finished: ' . $state['step'];
                $next = array_search($state['step'], self::STEPS, true) + 1;
                $state['step'] = self::STEPS[$next];
                if ($state['step'] === 'done') {
                    $state['done'] = true;
                }
            }
        }

        return $state;
    }

    public function runAll(array $state): array
    {
        return $this->run($state, INF);
    }

    /** Step progress for the UI: [step, percent]. */
    public static function progress(array $state): array
    {
        $index = array_search($state['step'], self::STEPS, true);
        $within = 0.0;
        if ($state['step'] === 'extract' && !empty($state['extract']['total'])) {
            $within = $state['extract']['index'] / $state['extract']['total'];
        } elseif ($state['step'] === 'import' && !empty($state['import']['size'])) {
            $within = $state['import']['offset'] / $state['import']['size'];
        }

        return [$state['step'], (int) round(100 * ($index + $within) / (count(self::STEPS) - 1))];
    }

    // ------------------------------------------------------------------ steps

    private function stepBackup(array &$state): bool
    {
        $this->ensureWorkDir();
        $prefix = $state['inspection']['manifest']['site']['table_prefix'];
        $state['backup_tables'] = Importer::backupTables($this->connect(), $prefix, 'ssb' . date('ymdHi') . '_');
        if (is_file($this->dir . '/wp-config.php')) {
            copy($this->dir . '/wp-config.php', $this->work . '/wp-config.backup.php');
        }

        return true;
    }

    private function stepExtract(array &$state, float $deadline): bool
    {
        $zip = $this->settings['zip'] ?? null;
        if (!$zip) {
            return true;
        }
        $layout = $state['inspection']['layout'];
        $layout['rename'] = [];
        $layout['skip_regex'] = '#^[^/]*\.sql(\.gz)?$#i'; // never leave a database dump in the public folder
        // Hand-made zips often include caches and other backup tools' archives.
        $layout['skip_prefixes'] = array_map(static function ($e) {
            return $e . '/';
        }, \SiteShifter\Package\Archiver::DEFAULT_EXCLUDES);
        foreach (self::DISABLED_DROPINS as $dropin) {
            $layout['rename'][$dropin] = $dropin . '.siteshifter-disabled';
        }
        // wp-config.php is written by the config step from the packaged one.
        $state['extract'] = (new Extractor($zip, $this->dir, $layout, ['wp-config.php']))->run($state['extract'], $deadline);

        return $state['extract']['done'];
    }

    private function stepCore(array &$state): bool
    {
        if (is_file($this->dir . '/wp-includes/version.php')) {
            return true;
        }
        $version = $state['inspection']['manifest']['site']['wp_version'] ?? null;
        $coreZip = $this->settings['core_zip'] ?? null;
        if (!$coreZip) {
            $coreZip = $this->work . '/wordpress.zip';
            $urls = ['https://downloads.wordpress.org/latest.zip'];
            if ($version) {
                array_unshift($urls, "https://downloads.wordpress.org/release/wordpress-{$version}.zip");
            }
            $ok = false;
            foreach ($urls as $url) {
                if (self::download($url, $coreZip)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $this->fail('Could not download WordPress from wordpress.org. Upload the WordPress core files (wp-admin, wp-includes and the root .php files) to the site folder and continue.');
            }
        }
        $layout = ['strip' => 'wordpress/', 'target' => '', 'skip_prefixes' => ['wp-content/']];
        (new Extractor($coreZip, $this->dir, $layout))->run(Extractor::initialState(), INF);
        $installed = Manifest::versionFile($this->dir);
        $sourceDb = (int) ($state['inspection']['manifest']['db']['db_version'] ?? 0);
        if ($sourceDb && $installed['wp_db_version'] && $sourceDb > $installed['wp_db_version']) {
            $this->fail('The downloaded WordPress (' . $installed['wp_version'] . ') is older than the site\'s database. Upload the matching WordPress files to the folder and continue.');
        }
        $state['log'][] = 'WordPress core installed: ' . $installed['wp_version'];
        $state['wp_upgraded_from'] = $version !== null && $version !== $installed['wp_version'] ? $version : null;

        return true;
    }

    private function stepConfig(array &$state): bool
    {
        $manifest = $state['inspection']['manifest'];
        $zip = $this->settings['zip'] ?? null;
        $source = null;
        if ($zip && $state['inspection']['layout']['has_config']) {
            $source = Extractor::readEntry($zip, $state['inspection']['layout'], 'wp-config.php');
        }
        $config = (new ConfigWriter())->write($source, $this->settings['db'], $manifest['site']['table_prefix'], $this->settings['new_url'], $this->dir);
        if (file_put_contents($this->dir . '/wp-config.php', $config) === false) {
            $this->fail('Could not write wp-config.php.');
        }

        return true;
    }

    private function stepImport(array &$state, float $deadline): bool
    {
        $sql = $this->sqlFile($state);
        $db = $this->connect();
        $available = array_flip(array_column($db->rows('SHOW COLLATION'), 'Collation'));
        $plan = Preflight::collationPlan($state['inspection']['manifest']['db']['collations'] ?? [], $available);
        $state['import'] = (new Importer($db, $sql, $plan['convert']))->run($state['import'], $deadline);
        if ($state['import']['done']) {
            // Counted now, before finalize removes caches (transients, Elementor CSS).
            $prefix = $state['inspection']['manifest']['site']['table_prefix'];
            $state['imported_rows'] = (new Verifier($db))->rowCounts($db->tables($prefix));
        }

        return $state['import']['done'];
    }

    private function stepReplace(array &$state, float $deadline): bool
    {
        $db = $this->connect();
        $prefix = $state['inspection']['manifest']['site']['table_prefix'];
        $job = new DatabaseReplacer($db, new Replacer($this->replacementMap($state)), $db->tables($prefix), [$prefix . 'posts' => ['guid']]);
        $state['replace'] = $job->run($state['replace'], $deadline);
        if ($state['replace']['done']) {
            // Make sure the two key options are exact, whatever the dump contained.
            [$home, $siteurl] = $this->newUrls($state);
            $t = Connection::ident($prefix . 'options');
            $db->exec("UPDATE {$t} SET option_value = " . $db->quote($home) . " WHERE option_name = 'home'");
            $db->exec("UPDATE {$t} SET option_value = " . $db->quote($siteurl) . " WHERE option_name = 'siteurl'");
        }

        return $state['replace']['done'];
    }

    private function stepFinalize(array &$state): bool
    {
        $db = $this->connect();
        $prefix = $state['inspection']['manifest']['site']['table_prefix'];
        $options = Connection::ident($prefix . 'options');
        $postmeta = Connection::ident($prefix . 'postmeta');

        // Caches that hold old URLs or paths and are rebuilt automatically.
        $db->exec("DELETE FROM {$options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'");
        $db->exec("DELETE FROM {$postmeta} WHERE meta_key = '_elementor_css'");
        $db->exec("DELETE FROM {$options} WHERE option_name IN ('_elementor_global_css', 'elementor-custom-breakpoints-files', '_elementor_assets_data')");
        self::removeFiles($this->dir . '/wp-content/uploads/elementor/css', '/\.css$/');
        self::removeFiles($this->dir . '/wp-content/wp-rocket-config', '/\.php$/');

        $oldPath = $state['inspection']['manifest']['site']['abspath'] ?? null;
        $state['log'] = array_merge($state['log'], (new ServerFiles($this->dir))->fix($oldPath, $this->settings['new_url']));

        return true;
    }

    private function stepVerify(array &$state): bool
    {
        $db = $this->connect();
        $manifest = $state['inspection']['manifest'];
        $prefix = $manifest['site']['table_prefix'];
        $tables = $db->tables($prefix);
        $verifier = new Verifier($db);
        $hosts = Manifest::oldHosts($manifest);
        if (!empty($this->settings['old_url']) && preg_match('#^https?://(.+?)/*$#i', $this->settings['old_url'], $m)) {
            $hosts[] = strtolower($m[1]);
        }
        $newHost = (string) parse_url($this->settings['new_url'], PHP_URL_HOST);
        $hosts = array_values(array_filter(array_unique($hosts), static function ($h) use ($newHost) {
            return $h !== $newHost; // http→https on the same domain: nothing to look for
        }));

        $source = $manifest['db']['tables'] ?? [];
        $state['result'] = [
            'leftovers' => $hosts === [] ? [] : $verifier->leftovers($tables, $hosts, [$prefix . 'posts' => ['guid']]),
            'row_mismatches' => $source === [] ? [] : Verifier::compareRowCounts($source, $state['imported_rows'] ?? $verifier->rowCounts($tables), $prefix, $prefix),
            'replace_stats' => $state['replace']['stats'],
            'licences' => (new Preflight())->licences($manifest)->toArray(),
            'disabled_dropins' => array_values(array_filter(self::DISABLED_DROPINS, function ($d) {
                return is_file($this->dir . '/' . $d . '.siteshifter-disabled');
            })),
            'backup_tables' => $state['backup_tables'],
            'needs_db_upgrade' => $this->needsDbUpgrade($manifest),
        ];

        return true;
    }

    /** True when the core is newer than the imported database; WordPress then asks for /wp-admin/upgrade.php. */
    private function needsDbUpgrade(array $manifest): bool
    {
        $core = Manifest::versionFile($this->dir)['wp_db_version'];
        $db = (int) ($manifest['db']['db_version'] ?? 0);

        return $core !== null && $db > 0 && $core > $db;
    }

    private function stepDone(array &$state): bool
    {
        return true;
    }

    // --------------------------------------------------------------- helpers

    /** Removes installer leftovers: work folder, uploaded package, SQL. */
    public function cleanup(bool $removeUploads = true): void
    {
        self::removeTree($this->work);
        if ($removeUploads) {
            foreach (['zip', 'sql'] as $key) {
                if (!empty($this->settings[$key]) && is_file($this->settings[$key])) {
                    @unlink($this->settings[$key]);
                }
            }
        }
    }

    /** Puts the tables that were there before back, if the install has to be abandoned. */
    public function rollback(array $state): void
    {
        if ($state['backup_tables'] !== []) {
            Importer::restoreTables($this->connect(), $state['backup_tables']);
        }
        if (is_file($this->work . '/wp-config.backup.php')) {
            copy($this->work . '/wp-config.backup.php', $this->dir . '/wp-config.php');
        }
    }

    public function replacementMap(array $state): ReplacementMap
    {
        $manifest = $state['inspection']['manifest'];
        $map = new ReplacementMap();
        [$newHome, $newSiteurl] = $this->newUrls($state);
        $oldHome = $this->settings['old_url'] ?? ($manifest['site']['home'] ?: $manifest['site']['url']);
        $oldSiteurl = $manifest['site']['url'] ?: $oldHome;
        if ($oldSiteurl !== $oldHome) {
            $map->addUrl($oldSiteurl, $newSiteurl);
        }
        $map->addUrl($oldHome, $newHome);
        if (!empty($manifest['site']['abspath'])) {
            $map->addPath($manifest['site']['abspath'], $this->dir);
        }

        return $map;
    }

    /** @return array{0:string,1:string} new home, new siteurl */
    private function newUrls(array $state): array
    {
        $manifest = $state['inspection']['manifest'];
        $home = rtrim($this->settings['new_url'], '/');
        $oldHome = rtrim($this->settings['old_url'] ?? ($manifest['site']['home'] ?: (string) $manifest['site']['url']), '/');
        $oldSiteurl = rtrim((string) ($manifest['site']['url'] ?: $oldHome), '/');
        $siteurl = $home;
        $oldHomeBare = preg_replace('#^https?://#', '', $oldHome);
        $oldSiteBare = preg_replace('#^https?://#', '', $oldSiteurl);
        if ($oldSiteBare !== $oldHomeBare && strpos($oldSiteBare, $oldHomeBare . '/') === 0) {
            $siteurl = $home . substr($oldSiteBare, strlen($oldHomeBare)); // WordPress in a subfolder, e.g. /wp
        }

        return [$home, $siteurl];
    }

    private function sqlFile(array $state): string
    {
        if (!empty($this->settings['sql'])) {
            return $this->settings['sql'];
        }
        $inZip = $state['inspection']['sql_in_zip'] ?? null;
        if ($inZip && !empty($this->settings['zip'])) {
            return $this->extractSql($this->settings['zip'], $inZip);
        }
        $this->fail('No database export to import.');
    }

    private function extractSql(string $zip, string $entry): string
    {
        $this->ensureWorkDir();
        $target = $this->work . '/' . basename($entry);
        if (is_file($target)) {
            return $target;
        }
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) {
            $this->fail("Cannot open {$zip}");
        }
        $in = $z->getStream($entry);
        $out = fopen($target, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $z->close();
        if (preg_match('/\.gz$/', $target)) {
            $plain = substr($target, 0, -3);
            $gz = gzopen($target, 'rb');
            $out = fopen($plain, 'wb');
            while (!gzeof($gz)) {
                fwrite($out, gzread($gz, 1048576));
            }
            gzclose($gz);
            fclose($out);
            $target = $plain;
        }

        return $target;
    }

    /** @var Connection|null */
    private $connection;

    private function connect(): Connection
    {
        if ($this->connection === null) {
            $this->connection = Connection::fromConfig($this->settings['db']);
        }

        return $this->connection;
    }

    private function ensureWorkDir(): void
    {
        if (!is_dir($this->work) && !@mkdir($this->work, 0755, true)) {
            $this->fail("Cannot create {$this->work}");
        }
        @file_put_contents($this->work . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($this->work . '/index.php', '<?php // Silence.');
    }

    /**
     * Finds the old server path from absolute paths that WordPress and
     * plugins store in options (recently_edited, upload_path, cache paths).
     */
    public static function guessOldPath(string $sqlFile): ?string
    {
        $fh = @fopen($sqlFile, 'rb');
        if (!$fh) {
            return null;
        }
        $counts = [];
        $read = 0;
        while (!feof($fh) && $read < 256 * 1048576) {
            $chunk = (string) fread($fh, 4 * 1048576);
            $read += strlen($chunk);
            if (preg_match_all('#((?:/[A-Za-z0-9._~@+-]+)+|(?<![A-Za-z0-9])[A-Za-z]:(?:\\\\\\\\|\\\\|/(?!/))[^\'"\s;]*?)(?:/|\\\\\\\\|\\\\)wp-(?:content|includes|admin)\b#', $chunk, $m)) {
                foreach ($m[1] as $path) {
                    $path = str_replace('\\\\', '/', $path);
                    $counts[$path] = ($counts[$path] ?? 0) + 1;
                }
            }
        }
        fclose($fh);
        // Ignore URL paths ("//old.com/wp-content" splits as "/old.com").
        $counts = array_filter($counts, static function ($path) {
            return !preg_match('#^/[^/]*\.[a-z]{2,}(/|$)#i', $path) || preg_match('#^/(home|var|srv|usr|opt|Users|Applications|data|www)/#', $path);
        }, ARRAY_FILTER_USE_KEY);
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return (string) key($counts);
    }

    private static function download(string $url, string $target): bool
    {
        $out = @fopen($target, 'wb');
        if (!$out) {
            return false;
        }
        $ok = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_FAILONERROR => true]);
            $ok = curl_exec($ch) !== false;
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $in = @fopen($url, 'rb');
            $ok = $in && stream_copy_to_stream($in, $out) > 0;
            if ($in) {
                fclose($in);
            }
        }
        fclose($out);

        return $ok && filesize($target) > 1000000;
    }

    private static function removeFiles(string $dir, string $pattern): void
    {
        foreach ((array) @scandir($dir) as $file) {
            if ($file !== '.' && $file !== '..' && preg_match($pattern, (string) $file) && is_file("{$dir}/{$file}")) {
                @unlink("{$dir}/{$file}");
            }
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return never */
    private function fail(string $message)
    {
        throw new \RuntimeException($message);
    }
}

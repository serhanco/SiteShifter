<?php

namespace SiteShifter\Preflight;

use SiteShifter\Database\Connection;

/**
 * Runs every check that can make a migration fail, before anything is
 * changed. Each check adds an OK, WARN or BLOCK line with a suggested fix.
 *
 * Context keys (all optional):
 *  - target_dir      string   where the site will be installed
 *  - new_url         string   the destination site URL
 *  - package_bytes   int      size of the uploaded package/zip + SQL
 *  - unpacked_bytes  int      estimated size after extraction
 *  - needs_zip       bool     a zip has to be extracted
 *  - manifest        array    source site facts (see Package\Manifest)
 *  - file_reader     callable(string $relativePath): ?string — reads a file from the package
 *  - db              Connection|null, db_error string
 */
class Preflight
{
    /** Minimum PHP for current WordPress releases. */
    public const WP_MIN_PHP = '7.2.24';
    public const RECOMMENDED_MEMORY = 256 * 1024 * 1024;
    public const MIN_MEMORY = 64 * 1024 * 1024;

    /** MySQL 8 collations that MariaDB and MySQL 5.x do not know, with safe replacements. */
    public const COLLATION_FALLBACKS = [
        'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci',
        'utf8mb4_0900_as_ci' => 'utf8mb4_unicode_520_ci',
        'utf8mb4_0900_as_cs' => 'utf8mb4_bin',
        'utf8mb4_0900_bin' => 'utf8mb4_bin',
        'utf8mb4_tr_0900_ai_ci' => 'utf8mb4_turkish_ci',
        'utf8mb4_tr_0900_as_cs' => 'utf8mb4_turkish_ci',
        'utf8mb4_uca1400_ai_ci' => 'utf8mb4_unicode_520_ci',
        'utf8mb4_unicode_520_ci' => 'utf8mb4_unicode_ci',
        'utf8mb4_unicode_ci' => 'utf8mb4_general_ci',
    ];

    /** @var Probe */
    private $probe;

    public function __construct(?Probe $probe = null)
    {
        $this->probe = $probe ?: new Probe();
    }

    public function run(array $ctx): Report
    {
        $report = new Report();
        $report->merge($this->server($ctx));
        if (array_key_exists('db', $ctx) || isset($ctx['db_error'])) {
            $report->merge($this->database($ctx['db'] ?? null, $ctx));
        }
        $report->merge($this->wordpress($ctx));
        $report->merge($this->site($ctx));
        if (!empty($ctx['new_url'])) {
            $report->merge($this->url($ctx['new_url']));
            $old = $ctx['old_url'] ?? ($ctx['manifest']['site']['home'] ?? ($ctx['manifest']['site']['url'] ?? ''));
            if ($old) {
                $report->merge($this->address((string) $old, $ctx['new_url']));
            }
        }
        $report->merge($this->licences($ctx['manifest'] ?? []));

        return $report;
    }

    public function server(array $ctx): Report
    {
        $r = new Report();
        $manifest = $ctx['manifest'] ?? [];
        $php = $this->probe->phpVersion();

        // PHP version: the site's own requirement first, then WordPress's.
        $required = self::maxVersion(self::WP_MIN_PHP, $manifest['php']['requires'] ?? '0');
        $source = $manifest['php']['version'] ?? null;
        $needing = [];
        foreach ($manifest['plugins']['details'] ?? [] as $plugin) {
            if (!empty($plugin['requires_php']) && version_compare($php, $plugin['requires_php'], '<')) {
                $needing[] = $plugin['name'] . ' (PHP ' . $plugin['requires_php'] . '+)';
            }
        }
        if (version_compare($php, $required, '<')) {
            $r->add(new Result('php_version', Result::BLOCK, 'PHP version', "This server runs PHP {$php}, the site needs at least {$required}." . ($needing ? ' Needed by: ' . implode(', ', $needing) . '.' : ''), 'Select a newer PHP version in your hosting panel (cPanel: "Select PHP Version", hPanel: "PHP Configuration").'));
        } elseif ($source && version_compare(self::minor($php), self::minor($source), '<')) {
            $r->add(new Result('php_version', Result::WARN, 'PHP version', "The old server ran PHP {$source}, this one runs {$php}. Some plugins may rely on the newer version.", "Switch this server to PHP " . self::minor($source) . ' or newer if your panel allows it.'));
        } else {
            $r->add(new Result('php_version', Result::OK, 'PHP version', "PHP {$php}" . ($source ? " (old server: {$source})" : '')));
        }
        if ($source && (int) $php > (int) $source) {
            $r->add(new Result('php_major_jump', Result::WARN, 'PHP major version change', "The site moves from PHP {$source} to PHP {$php}. Plugins or themes that were not updated for PHP " . (int) $php . ' can show errors or a white screen.', 'Update all plugins and the theme on the old site before moving, or select PHP ' . self::minor($source) . ' on this server first and upgrade PHP after testing.'));
        }

        // Extensions.
        $extensions = [
            'mysqli' => [Result::BLOCK, 'needed to talk to the database'],
            'json' => [Result::BLOCK, 'needed to read the package'],
            'zip' => [!empty($ctx['needs_zip']) ? Result::BLOCK : Result::WARN, 'needed to unpack zip files'],
            'mbstring' => [Result::WARN, 'WordPress and many plugins expect it'],
            'curl' => [Result::WARN, 'needed for updates, licence checks and remote APIs'],
            'openssl' => [Result::WARN, 'needed for HTTPS requests'],
        ];
        $missing = [];
        $level = Result::OK;
        foreach ($extensions as $ext => [$severity, $why]) {
            if (!$this->probe->extensionLoaded($ext)) {
                $missing[] = "{$ext} ({$why})";
                $level = self::worst($level, $severity);
            }
        }
        if (!$this->probe->extensionLoaded('gd') && !$this->probe->extensionLoaded('imagick')) {
            $missing[] = 'gd or imagick (image resizing)';
            $level = self::worst($level, Result::WARN);
        }
        $r->add(new Result('php_extensions', $level, 'PHP extensions', $missing ? 'Missing: ' . implode(', ', $missing) : 'All required extensions are loaded.', 'Enable the missing extensions in your hosting panel\'s PHP settings.'));

        // Memory.
        $memory = self::bytes((string) $this->probe->ini('memory_limit'));
        if ($memory !== -1 && $memory < self::MIN_MEMORY) {
            $canRaise = $this->probe->canSetIni('memory_limit', '256M');
            $r->add(new Result('memory_limit', $canRaise ? Result::WARN : Result::BLOCK, 'PHP memory', 'memory_limit is ' . self::human($memory) . ($canRaise ? '; SiteShifter will raise it to 256M while it runs.' : ' and cannot be raised.'), 'Set memory_limit to at least 256M in your hosting panel or php.ini.'));
        } elseif ($memory !== -1 && $memory < self::RECOMMENDED_MEMORY) {
            $r->add(new Result('memory_limit', Result::WARN, 'PHP memory', 'memory_limit is ' . self::human($memory) . '; large pages or plugins may need 256M.', 'Raise memory_limit to 256M if the site is large.'));
        } else {
            $r->add(new Result('memory_limit', Result::OK, 'PHP memory', $memory === -1 ? 'unlimited' : self::human($memory)));
        }

        // Time limit: never a blocker because every step runs in resumable chunks.
        $time = (int) $this->probe->ini('max_execution_time');
        $r->add(new Result('max_execution_time', Result::OK, 'Time limit', $time === 0 ? 'unlimited' : "{$time} s — work is split into chunks that fit in this limit"));

        // Upload limits only matter when the package goes through the browser.
        if (!empty($ctx['browser_upload']) && !empty($ctx['package_bytes'])) {
            $limit = min(self::bytes((string) $this->probe->ini('upload_max_filesize')), self::bytes((string) $this->probe->ini('post_max_size')));
            if ($limit !== -1 && $limit < $ctx['package_bytes']) {
                $r->add(new Result('upload_limit', Result::WARN, 'Upload limit', 'The package (' . self::human((int) $ctx['package_bytes']) . ') is larger than the upload limit (' . self::human($limit) . ').', 'Upload the files by FTP / File Manager into the site folder instead; the installer will find them there.'));
            }
        }

        // Disk space: package + extracted files + temporary SQL work.
        $dir = $ctx['target_dir'] ?? null;
        if ($dir !== null) {
            $need = (int) ($ctx['unpacked_bytes'] ?? 0) + (int) (($ctx['package_bytes'] ?? 0) * 0.2);
            $free = $this->probe->diskFree($dir);
            if ($free === false) {
                $r->add(new Result('disk_space', Result::WARN, 'Disk space', 'Free space could not be measured on this host.', 'Make sure the account has at least ' . self::human($need) . ' free.'));
            } elseif ($need > 0 && $free < $need) {
                $r->add(new Result('disk_space', Result::BLOCK, 'Disk space', 'Needs about ' . self::human($need) . ', only ' . self::human((int) $free) . ' is free.', 'Free up space or upgrade the hosting plan, then run the check again.'));
            } else {
                $r->add(new Result('disk_space', Result::OK, 'Disk space', self::human((int) $free) . ' free'));
            }

            $r->add($this->probe->isWritable($dir)
                ? new Result('writable', Result::OK, 'Folder permissions', 'The site folder is writable.')
                : new Result('writable', Result::BLOCK, 'Folder permissions', "PHP cannot write to {$dir}.", 'Set the folder owner to the hosting user and permissions to 755.'));

            if ($this->probe->exists(rtrim($dir, '/') . '/wp-config.php')) {
                $r->add(new Result('existing_site', Result::WARN, 'Existing WordPress', 'A WordPress site already exists in this folder and will be replaced.', 'SiteShifter backs up wp-config.php and the existing tables first; download a full backup too if this site matters.'));
            }
        }

        return $r;
    }

    public function database(?Connection $db, array $ctx): Report
    {
        $r = new Report();
        if ($db === null) {
            $r->add(new Result('db_connect', Result::BLOCK, 'Database connection', $ctx['db_error'] ?? 'Not connected.', 'Check the database name, user and password in your hosting panel, and that the user is assigned to the database with all privileges.'));

            return $r;
        }
        $version = $db->serverVersion();
        $r->add(new Result('db_connect', Result::OK, 'Database connection', ($db->isMariaDb() ? 'MariaDB ' : 'MySQL ') . $version));

        $numeric = preg_replace('/[^0-9.].*$/', '', $version);
        $sourceServer = $ctx['manifest']['db']['server'] ?? null;
        if ($sourceServer) {
            $r->add(self::compareDbServers($sourceServer, $version));
        }
        if (!$db->isMariaDb() && version_compare($numeric, '5.5.3', '<')) {
            $r->add(new Result('db_utf8mb4', Result::BLOCK, 'utf8mb4 support', 'This database server is too old for utf8mb4 (emoji, some Turkish characters in plugins).', 'Ask the host for MySQL 5.7+ or MariaDB 10.4+.'));
        }

        // Privileges: try the statements the import really runs.
        $probeTable = 'siteshifter_probe_' . substr(md5((string) mt_rand()), 0, 6);
        try {
            $db->exec('CREATE TABLE ' . Connection::ident($probeTable) . ' (id int PRIMARY KEY, v text)');
            $db->exec('ALTER TABLE ' . Connection::ident($probeTable) . ' ADD COLUMN w int');
            $db->exec('INSERT INTO ' . Connection::ident($probeTable) . ' (id, v) VALUES (1, \'x\')');
            $db->exec('DROP TABLE ' . Connection::ident($probeTable));
            $r->add(new Result('db_privileges', Result::OK, 'Database privileges', 'CREATE, ALTER, INSERT and DROP work.'));
        } catch (\RuntimeException $e) {
            $r->add(new Result('db_privileges', Result::BLOCK, 'Database privileges', $e->getMessage(), 'Give the database user ALL PRIVILEGES on this database in your hosting panel.'));
        }

        // Collations used by the source must exist here, or be converted.
        $manifest = $ctx['manifest'] ?? [];
        $used = $manifest['db']['collations'] ?? [];
        if ($used !== []) {
            $available = array_flip(array_column($db->rows('SHOW COLLATION'), 'Collation'));
            $plan = self::collationPlan($used, $available);
            if ($plan['unsupported'] !== []) {
                $r->add(new Result('db_collation', Result::BLOCK, 'Character set', 'No safe replacement for: ' . implode(', ', $plan['unsupported']), 'Export the source database with a utf8mb4_unicode_ci collation, or use a newer database server.'));
            } elseif ($plan['convert'] !== []) {
                $pairs = [];
                foreach ($plan['convert'] as $from => $to) {
                    $pairs[] = "{$from} → {$to}";
                }
                $r->add(new Result('db_collation', Result::WARN, 'Character set', 'The source uses collations this server does not have; they will be converted during import: ' . implode(', ', $pairs) . '.', 'Nothing to do — sorting of some special characters may differ slightly.'));
            } else {
                $r->add(new Result('db_collation', Result::OK, 'Character set', 'All source collations are supported.'));
            }
        }

        // Largest row vs max_allowed_packet.
        $largest = (int) ($manifest['db']['largest_row'] ?? 0);
        $packet = (int) $db->value('SELECT @@max_allowed_packet');
        if ($largest > 0 && $packet > 0 && $largest > $packet * 0.9) {
            $r->add(new Result('db_packet', $largest > $packet ? Result::BLOCK : Result::WARN, 'Large rows', 'The largest row is ' . self::human($largest) . ', max_allowed_packet is ' . self::human($packet) . '.', 'Ask the host to raise max_allowed_packet above the largest row size; a row larger than it cannot be imported.'));
        }

        // Existing tables with the prefix that will be imported.
        $prefix = $ctx['table_prefix'] ?? ($manifest['site']['table_prefix'] ?? null);
        if ($prefix !== null) {
            $existing = $db->tables($prefix);
            if ($existing !== []) {
                $r->add(new Result('db_existing_tables', Result::WARN, 'Existing tables', count($existing) . " tables with prefix \"{$prefix}\" already exist and will be replaced.", 'SiteShifter renames them with a backup prefix first, so they can be restored.'));
            }
        }

        if (!empty($manifest['db']['foreign_tables'])) {
            $r->add(new Result('db_foreign_tables', Result::WARN, 'Tables without the site prefix', 'The source database also contains: ' . implode(', ', array_slice($manifest['db']['foreign_tables'], 0, 10)) . (count($manifest['db']['foreign_tables']) > 10 ? ', …' : '') . '. They belong to another app or a plugin with its own prefix.', 'They are not migrated. If a plugin needs them, re-export with "include all tables".'));
        }

        return $r;
    }

    public function site(array $ctx): Report
    {
        $r = new Report();
        $manifest = $ctx['manifest'] ?? [];
        $read = $ctx['file_reader'] ?? null;

        if (!empty($manifest['site']['multisite'])) {
            $r->add(new Result('multisite', Result::BLOCK, 'Multisite', 'This is a WordPress Multisite network.', 'Multisite moves are not supported yet; use WP-CLI search-replace with --network for now.'));
        }

        if (is_callable($read)) {
            $oldPath = $manifest['site']['abspath'] ?? null;
            $suspects = [
                '.user.ini' => 'PHP settings file (often Wordfence\'s auto_prepend_file)',
                'wordfence-waf.php' => 'Wordfence firewall loader',
                'wp-content/advanced-cache.php' => 'page cache drop-in',
                'wp-content/object-cache.php' => 'object cache drop-in (Redis/Memcached)',
                'wp-content/db.php' => 'database drop-in',
                'wp-config.php' => 'WordPress configuration',
            ];
            $hits = [];
            foreach ($suspects as $file => $label) {
                $content = $read($file);
                if ($content === null || $content === '') {
                    continue;
                }
                if ($file === 'wp-content/object-cache.php' || $file === 'wp-content/advanced-cache.php') {
                    $hits[] = "{$file} ({$label}) — will be disabled during the move and can be re-enabled after";
                } elseif ($oldPath && strpos($content, rtrim($oldPath, '/')) !== false) {
                    $hits[] = "{$file} ({$label}) contains the old server path";
                }
                if ($file === 'wp-config.php' && preg_match("/define\\(\\s*['\"](WP_HOME|WP_SITEURL)['\"]/", $content)) {
                    $hits[] = 'wp-config.php hard-codes WP_HOME/WP_SITEURL — will be rewritten to the new URL';
                }
            }
            $r->add($hits === []
                ? new Result('hardcoded_paths', Result::OK, 'Hard-coded paths', 'No cache or firewall files with old paths found.')
                : new Result('hardcoded_paths', Result::WARN, 'Hard-coded paths', implode('; ', $hits) . '.', 'SiteShifter rewrites or disables these automatically; re-run your cache/firewall plugin\'s setup afterwards.'));

            $htaccess = $read('.htaccess');
            $software = strtolower($this->probe->serverSoftware());
            if ($htaccess !== null && $htaccess !== '' && strpos($software, 'nginx') !== false) {
                $r->add(new Result('webserver', Result::WARN, 'Web server', 'The site has an .htaccess file but this server runs nginx, which ignores it.', 'Add the WordPress rewrite rule (try_files $uri $uri/ /index.php?$args;) and any redirect rules to the nginx config.'));
            }
        }

        return $r;
    }

    public function url(string $newUrl): Report
    {
        $r = new Report();
        $parts = parse_url($newUrl);
        $host = $parts['host'] ?? '';
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $r;
        }
        $ips = $this->probe->resolve($host);
        $serverIp = $this->probe->serverAddress();
        $cdn = \SiteShifter\Support\Https::cdnFor($ips);
        if ($ips === []) {
            $r->add(new Result('dns', Result::WARN, 'Domain DNS', "{$host} does not resolve yet.", 'Point the domain\'s A record to this server. The site can be installed now and will open once DNS has propagated.'));
        } elseif ($cdn !== null) {
            $r->add(new Result('dns', Result::OK, 'Domain DNS', "{$host} is served through {$cdn}."));
            $r->add(new Result('cdn', Result::INFO, $cdn . ' proxy', "Visitors reach this site through {$cdn}, which talks to this server in its own SSL mode.", 'Set the SSL/TLS mode to Full (strict) only after this server has a valid certificate for the domain (AutoSSL / Let\'s Encrypt); until then use Full, or visitors get error 526. SiteShifter also makes WordPress trust the proxy\'s https header.'));
        } elseif ($serverIp !== '' && !in_array($serverIp, $ips, true)) {
            $r->add(new Result('dns', Result::WARN, 'Domain DNS', "{$host} points to " . implode(', ', $ips) . ", this server is {$serverIp}.", 'If you use a CDN or reverse proxy this is expected; otherwise update the A record.'));
        } else {
            $r->add(new Result('dns', Result::OK, 'Domain DNS', "{$host} → " . implode(', ', $ips)));
        }

        // The other spelling (www.example.com <-> example.com) should work too.
        $alt = self::alternateHost($host);
        if ($alt !== null && $ips !== []) {
            if ($this->probe->resolve($alt) === []) {
                $r->add(new Result('dns_alt', Result::WARN, 'Domain DNS (' . $alt . ')', "{$alt} does not resolve, so visitors who type it get an error.", "Add a DNS record for {$alt} (CNAME to {$host}) and a redirect to {$host}."));
            } elseif (($parts['scheme'] ?? '') === 'https' && $this->probe->tlsError($alt) !== null) {
                $r->add(new Result('ssl_alt', Result::WARN, 'SSL certificate (' . $alt . ')', "The certificate does not cover {$alt}.", "Issue a certificate that includes both {$host} and {$alt} so the redirect between them works over https."));
            }
        }

        if (($parts['scheme'] ?? '') === 'https' && $ips !== []) {
            $error = $this->probe->tlsError($host);
            $r->add($error === null
                ? new Result('ssl', Result::OK, 'SSL certificate', "Valid certificate for {$host}.")
                : new Result('ssl', Result::WARN, 'SSL certificate', "No valid certificate for {$host} yet ({$error}).", 'Issue a free SSL certificate in your hosting panel (Let\'s Encrypt / AutoSSL) before switching visitors to https.'));
        }

        return $r;
    }

    /**
     * Differences between the old and the new address that need a decision:
     * a downgrade from https to http, and a switch between www and non-www.
     */
    public function address(string $oldUrl, string $newUrl): Report
    {
        $r = new Report();
        $old = parse_url(trim($oldUrl));
        $new = parse_url(trim($newUrl));
        if (empty($old['host']) || empty($new['host'])) {
            return $r;
        }
        $oldScheme = strtolower($old['scheme'] ?? '');
        $newScheme = strtolower($new['scheme'] ?? '');
        $oldHost = strtolower($old['host']);
        $newHost = strtolower($new['host']);

        if ($oldScheme === 'https' && $newScheme === 'http' && !self::isLocalHost($newHost)) {
            $r->add(new Result('scheme', Result::WARN, 'Address scheme', 'The site used https, but the new address is http. Every link would switch to http and browsers would show "Not secure".', 'Use https:// in the new address (all http links are upgraded for you), unless you really want an unencrypted site.'));
        } elseif ($oldScheme === 'http' && $newScheme === 'https') {
            $r->add(new Result('scheme', Result::INFO, 'Address scheme', 'The old site used http, the new address is https: every http link in the database is upgraded to https.'));
        }

        $oldBare = preg_replace('/^www\./', '', $oldHost);
        $newBare = preg_replace('/^www\./', '', $newHost);
        if ($oldBare === $newBare && $oldHost !== $newHost && !self::isLocalHost($newHost)) {
            $from = $oldHost === $oldBare ? 'without www' : 'with www';
            $to = $newHost === $newBare ? 'without www' : 'with www';
            $other = $oldHost;
            $r->add(new Result('www', Result::WARN, 'www / non-www', "The old site was {$from} ({$oldHost}) and the new address is {$to} ({$newHost}). Every link is converted to {$newHost}.", "Keep {$other} working with a permanent (301) redirect to {$newHost} and make sure the SSL certificate covers both, otherwise old links, bookmarks and search results break."));
        }

        return $r;
    }

    /** www.example.com <-> example.com; null for sub-domains and addresses without a clear counterpart. */
    public static function alternateHost(string $host): ?string
    {
        $host = strtolower($host);
        if (self::isLocalHost($host)) {
            return null;
        }
        if (strpos($host, 'www.') === 0) {
            return substr($host, 4);
        }
        $labels = explode('.', $host);
        $apex = count($labels) === 2
            || (count($labels) === 3 && strlen($labels[2]) === 2 && in_array($labels[1], ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac'], true));

        return $apex ? 'www.' . $host : null;
    }

    private static function isLocalHost(string $host): bool
    {
        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || substr($host, -6) === '.local' || substr($host, -5) === '.test' || substr($host, -10) === '.localhost';
    }

    /**
     * WordPress core version on the destination vs the source.
     * Context: target_wp_version, target_wp_db_version — the core that
     * will run the site after the move (an existing install in plugin
     * mode, or the downloaded core in installer mode).
     */
    public function wordpress(array $ctx): Report
    {
        $r = new Report();
        $manifest = $ctx['manifest'] ?? [];
        $source = $manifest['site']['wp_version'] ?? null;
        $sourceDb = isset($manifest['db']['db_version']) ? (int) $manifest['db']['db_version'] : null;
        $target = $ctx['target_wp_version'] ?? null;
        $targetDb = isset($ctx['target_wp_db_version']) ? (int) $ctx['target_wp_db_version'] : null;
        if ($target === null) {
            return $r;
        }

        if (($sourceDb && $targetDb && $sourceDb > $targetDb) || ($source && version_compare(self::minor($target), self::minor($source), '<'))) {
            $r->add(new Result('wp_version', Result::BLOCK, 'WordPress version', 'The destination runs WordPress ' . $target . ', older than the source' . ($source ? " ({$source})" : '') . '. A database from a newer WordPress can break on an older core.', 'Update WordPress on the destination to ' . ($source ?: 'the latest version') . ' or newer first.'));
        } elseif ($source && version_compare(self::minor($target), self::minor($source), '>')) {
            $r->add(new Result('wp_version', Result::WARN, 'WordPress version', "The site moves from WordPress {$source} to {$target}. The database will be upgraded automatically after the move; plugins and the theme should be tested on the new version.", 'Take note of plugins that have not been updated recently and test the main pages after the move.'));
        } else {
            $r->add(new Result('wp_version', Result::OK, 'WordPress version', $target . ($source ? " (old site: {$source})" : '')));
        }

        $tooNew = [];
        foreach ($manifest['plugins']['details'] ?? [] as $plugin) {
            if (!empty($plugin['requires_wp']) && version_compare(self::minor($target), self::minor($plugin['requires_wp']), '<')) {
                $tooNew[] = $plugin['name'] . ' (WordPress ' . $plugin['requires_wp'] . '+)';
            }
        }
        if ($tooNew !== []) {
            $r->add(new Result('wp_plugin_requirements', Result::WARN, 'Plugin requirements', 'These plugins need a newer WordPress than ' . $target . ': ' . implode(', ', $tooNew) . '.', 'Update WordPress on the destination before moving.'));
        }

        return $r;
    }

    /**
     * Compares database server versions: a downgrade or a switch between
     * MySQL and MariaDB is a warning with the specific known differences.
     */
    public static function compareDbServers(string $source, string $target): Result
    {
        [$sFlavor, $sVersion] = self::dbFlavor($source);
        [$tFlavor, $tVersion] = self::dbFlavor($target);
        $label = static function ($f, $v) {
            return ($f === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $v;
        };
        $from = $label($sFlavor, $sVersion);
        $to = $label($tFlavor, $tVersion);

        if ($sFlavor !== $tFlavor) {
            $notes = $sFlavor === 'mysql'
                ? 'MySQL 8 collations (utf8mb4_0900_*) are converted automatically; JSON columns become LONGTEXT with a JSON check, which WordPress and common plugins handle fine.'
                : 'MariaDB-only options (Aria engine, PAGE_CHECKSUM, uca1400 collations) are converted automatically.';

            return new Result('db_version', Result::WARN, 'Database server', "The database moves from {$from} to {$to}. {$notes}", 'Nothing to do in most cases; test plugins that use their own tables (forms, shops, LMS) after the move.');
        }
        if (version_compare(self::minor($tVersion), self::minor($sVersion), '<')) {
            return new Result('db_version', Result::WARN, 'Database server', "The database moves to an older server: {$from} → {$to}. Newer collations and features are converted where possible.", 'If the import reports an error, ask the host for ' . $from . ' or newer.');
        }

        return new Result('db_version', Result::OK, 'Database server', "{$from} → {$to}");
    }

    /** @return array{0:string,1:string} flavor, numeric version */
    public static function dbFlavor(string $version): array
    {
        $flavor = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        // "5.5.5-10.11.6-MariaDB" is how older clients report MariaDB.
        if (preg_match('/^5\.5\.5-(\d+\.\d+(\.\d+)?)/', $version, $m)) {
            return ['mariadb', $m[1]];
        }
        $numeric = preg_match('/^(\d+\.\d+(\.\d+)?)/', $version, $m) ? $m[1] : '0';

        return [$flavor, $numeric];
    }

    public function licences(array $manifest): Report
    {
        $r = new Report();
        $found = KnownPlugins::match($manifest['plugins']['active'] ?? [], $manifest['themes'] ?? []);
        foreach ($found as $item) {
            $r->add(new Result('licence:' . $item['key'], Result::INFO, $item['name'] . ' licence', 'Bound to the domain.', $item['action']));
        }
        $r->add(new Result('domain_services', Result::INFO, 'Domain-bound services', 'reCAPTCHA keys, Google Maps API key restrictions, CDN / Cloudflare, SMTP sender domain, Search Console and Analytics are set per domain.', 'Add the new domain in each service you use.'));

        return $r;
    }

    /**
     * @param string[]            $used      collations found in the source
     * @param array<string,mixed> $available collation => anything
     * @return array{convert:array<string,string>,unsupported:string[]}
     */
    public static function collationPlan(array $used, array $available): array
    {
        $convert = [];
        $unsupported = [];
        foreach (array_unique($used) as $collation) {
            if (isset($available[$collation])) {
                continue;
            }
            $candidate = $collation;
            $seen = [];
            while (isset(self::COLLATION_FALLBACKS[$candidate]) && !isset($seen[$candidate])) {
                $seen[$candidate] = true;
                $candidate = self::COLLATION_FALLBACKS[$candidate];
                if (isset($available[$candidate])) {
                    $convert[$collation] = $candidate;
                    continue 2;
                }
            }
            $unsupported[] = $collation;
        }

        return ['convert' => $convert, 'unsupported' => $unsupported];
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return (int) $number;
    }

    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$i];
    }

    private static function worst(string $a, string $b): string
    {
        $rank = [Result::OK => 0, Result::INFO => 0, Result::WARN => 1, Result::BLOCK => 2];

        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    private static function minor(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    private static function maxVersion(string $a, string $b): string
    {
        return version_compare($a, $b, '>=') ? $a : $b;
    }
}

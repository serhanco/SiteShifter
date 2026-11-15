<?php

namespace SiteShifter\Cli;

use SiteShifter\Database\Connection;
use SiteShifter\Database\DatabaseReplacer;
use SiteShifter\Installer\Installer;
use SiteShifter\Package\PackageBuilder;
use SiteShifter\Replace\ReplacementMap;
use SiteShifter\Replace\Replacer;
use SiteShifter\Support\WpConfigReader;

/**
 * Command-line interface: bin/siteshifter <command> [--options].
 * The same commands are exposed to WP-CLI as "wp siteshifter <command>".
 */
class Application
{
    public const VERSION = '0.1.0';

    /** @var callable(string):void */
    private $out;

    /** @var bool progress may use terminal control codes (only with the default output) */
    private $ttyOut;

    public function __construct(?callable $out = null)
    {
        $this->ttyOut = $out === null;
        $this->out = $out ?: static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = self::options(array_slice($argv, 2));
        try {
            switch ($command) {
                case 'export':
                    return $this->export($options);
                case 'preflight':
                    return $this->install($options, true);
                case 'install':
                    return $this->install($options, false);
                case 'replace':
                    return $this->replace($options);
                case 'version':
                case '--version':
                    $this->line('SiteShifter ' . self::VERSION . ' by Serhan Demirel (https://serhandemirel.com)');
                    return 0;
                default:
                    $this->help();
                    return $command === 'help' ? 0 : 1;
            }
        } catch (\Throwable $e) {
            $this->line('Error: ' . $e->getMessage());

            return 1;
        }
    }

    private function export(array $o): int
    {
        $path = rtrim($o['path'] ?? getcwd(), '/');
        $config = WpConfigReader::read($path . '/wp-config.php');
        $db = $this->db($o, $config);
        $out = $o['out'] ?? ($path . '/siteshifter-' . date('Ymd-His') . '.zip');
        $work = $o['work'] ?? (sys_get_temp_dir() . '/siteshifter-' . getmypid());
        $builder = new PackageBuilder($db, $path, $o['prefix'] ?? $config['prefix'], $work, $out, [
            'scope' => $o['scope'] ?? 'root',
            'include_foreign_tables' => isset($o['all-tables']),
        ]);
        $this->line("Packaging {$path} …");
        $state = PackageBuilder::initialState();
        $labels = ['dump' => 'Exporting the database', 'files' => 'Adding files', 'finish' => 'Writing the package', 'done' => 'Done'];
        while (!$state['done']) {
            $state = $builder->run($state, microtime(true) + 1);
            $p = PackageBuilder::progress($state);
            $this->progress(sprintf('[%3d%%] %s — %d%%', $p['percent'], $labels[$p['phase']], $p['phase_percent']));
        }
        $this->progressEnd();
        Installer::removeTree($work);
        $m = $state['manifest'];
        $this->line(sprintf('Package written: %s (%s)', $out, \SiteShifter\Preflight\Preflight::human((int) filesize($out))));
        $this->line(sprintf('  %d files, %d tables, site %s', $m['files']['count'], count($m['db']['tables']), $m['site']['home']));

        return 0;
    }

    private function install(array $o, bool $checkOnly): int
    {
        if (!isset($o['db-pass']) && isset($o['db-password'])) {
            $o['db-pass'] = $o['db-password'];
        }
        foreach (['dir', 'url', 'db-name', 'db-user'] as $required) {
            if (empty($o[$required])) {
                throw new \InvalidArgumentException("--{$required} is required");
            }
        }
        $installer = new Installer([
            'dir' => $o['dir'],
            'zip' => $o['zip'] ?? null,
            'sql' => $o['sql'] ?? null,
            'db' => ['host' => $o['db-host'] ?? 'localhost', 'user' => $o['db-user'], 'pass' => $o['db-pass'] ?? '', 'name' => $o['db-name']],
            'new_url' => $o['url'],
            'old_url' => $o['old-url'] ?? null,
            'old_path' => $o['old-path'] ?? null,
            'core_zip' => $o['core-zip'] ?? null,
            'proxy_https' => !isset($o['no-proxy-https']),
        ]);
        $inspection = $installer->inspect();
        $report = $installer->preflight($inspection);
        $this->line('Pre-flight report (' . $inspection['type'] . ' mode)');
        $this->line(rtrim($report->toText()));
        if ($report->hasBlockers()) {
            $this->line('Blocked: fix the items marked [BLOCK] and run again.');

            return 2;
        }
        if ($checkOnly) {
            return 0;
        }
        if ($report->byLevel('warn') !== [] && !isset($o['yes'])) {
            $this->line('There are warnings. Re-run with --yes to continue anyway.');

            return 3;
        }
        $state = Installer::initialState($inspection);
        try {
            $labels = ['backup' => 'Backing up existing tables', 'extract' => 'Extracting files', 'core' => 'Installing WordPress core', 'config' => 'Writing wp-config.php', 'import' => 'Importing the database', 'replace' => 'Replacing the old address', 'finalize' => 'Clearing caches, fixing server files', 'verify' => 'Verifying', 'done' => 'Done'];
            while (!$state['done']) {
                $state = $installer->run($state, microtime(true) + 1);
                [$step, $overall, $stepPercent, $detail] = Installer::progress($state);
                $this->progress(sprintf('[%3d%%] %s — %d%%%s', $overall, $labels[$step] ?? $step, $stepPercent, $detail !== '' ? " ({$detail})" : ''));
            }
            $this->progressEnd();
        } catch (\Throwable $e) {
            $this->line('Install failed: ' . $e->getMessage());
            if (!isset($o['keep-on-error'])) {
                $installer->rollback($state);
                $this->line('Previous tables and wp-config.php were restored.');
            }

            return 1;
        }
        $this->printResult($state['result']);
        $installer->cleanup(isset($o['delete-uploads']));

        return 0;
    }

    private function replace(array $o): int
    {
        if (empty($o['from']) || empty($o['to'])) {
            throw new \InvalidArgumentException('--from and --to are required');
        }
        $path = rtrim($o['path'] ?? getcwd(), '/');
        $config = WpConfigReader::read($path . '/wp-config.php');
        $db = $this->db($o, $config);
        $map = (new ReplacementMap())->addUrl($o['from'], $o['to']);
        if (!empty($o['from-path']) && !empty($o['to-path'])) {
            $map->addPath($o['from-path'], $o['to-path']);
        }
        $prefix = $o['prefix'] ?? $config['prefix'];
        $state = (new DatabaseReplacer($db, new Replacer($map), $db->tables($prefix), [$prefix . 'posts' => ['guid']]))->runAll();
        foreach ($state['stats'] as $table => $s) {
            if ($s['changed'] > 0) {
                $this->line(sprintf('  %-40s %6d rows changed', $table, $s['changed']));
            }
        }

        return 0;
    }

    private function printResult(array $result): void
    {
        $this->line('');
        $this->line('Done. — SiteShifter by Serhan Demirel · https://serhandemirel.com');
        if ($result['row_mismatches'] === []) {
            $this->line('  Row counts: every table matches the source.');
        } else {
            $this->line('  Row count differences:');
            foreach ($result['row_mismatches'] as $m) {
                $this->line(sprintf('    %s: source %s, now %s', $m['table'], $m['source'] ?? '-', $m['target'] ?? '-'));
            }
        }
        $emails = array_filter($result['leftovers'], static function ($l) {
            return $l['kind'] === 'email';
        });
        $leftovers = array_filter($result['leftovers'], static function ($l) {
            return $l['kind'] !== 'email';
        });
        if ($leftovers === []) {
            $this->line('  Old address: no occurrences left.');
        } else {
            $this->line('  Old address still found in:');
            foreach ($leftovers as $l) {
                $this->line(sprintf('    %s.%s (%s, %d rows) e.g. …%s…', $l['table'], $l['column'], $l['kind'], $l['count'], str_replace(["\n", "\r"], ' ', $l['sample'])));
            }
        }
        foreach ($emails as $l) {
            $this->line(sprintf('  Note: %s.%s has %d e-mail address(es) on the old domain (left unchanged).', $l['table'], $l['column'], $l['count']));
        }
        foreach ($result['disabled_dropins'] as $d) {
            $this->line("  Disabled {$d} (renamed to .siteshifter-disabled) — re-enable it once its cache service is set up here.");
        }
        if (!empty($result['needs_db_upgrade'])) {
            $this->line('  WordPress is newer than the database: open /wp-admin/upgrade.php once.');
        }
        foreach ($result['licences'] as $l) {
            $this->line('  • ' . $l['title'] . ': ' . $l['fix']);
        }
    }

    private function db(array $o, array $config): Connection
    {
        return new Connection(
            $o['db-host'] ?? $config['host'],
            $o['db-user'] ?? $config['user'],
            $o['db-pass'] ?? $config['pass'],
            $o['db-name'] ?? $config['name'],
            $config['charset']
        );
    }

    public static function options(array $args): array
    {
        $o = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([^=]+)(?:=(.*))?$/s', $arg, $m)) {
                $o[$m[1]] = $m[2] ?? true;
            }
        }

        return $o;
    }

    private function help(): void
    {
        $this->line(<<<TXT
SiteShifter {$this->version()} — shift WordPress sites safely
by Serhan Demirel · https://serhandemirel.com

  export     --path=/var/www/site [--out=site.zip] [--scope=root|wp-content] [--all-tables]
  preflight  --dir=/new/site --url=https://new.com --db-name= --db-user= [--db-pass=] [--db-host=]
             (--zip=package.zip | --zip=files.zip --sql=dump.sql | --sql=dump.sql)
  install    same as preflight, plus [--yes] [--old-url=] [--old-path=] [--core-zip=] [--delete-uploads] [--no-proxy-https]
  replace    --path=/var/www/site --from=http://old.test --to=https://new.com [--from-path= --to-path=]
TXT
        );
    }

    private function version(): string
    {
        return self::VERSION;
    }

    /** @var string */
    private $lastProgress = '';

    /** Prints a progress line: rewritten in place on a terminal, one line per change otherwise. */
    private function progress(string $text): void
    {
        if ($text === $this->lastProgress) {
            return;
        }
        $this->lastProgress = $text;
        if ($this->isTty()) {
            fwrite(STDOUT, "\r\033[K  " . $text);
        } else {
            $this->line('  ' . $text);
        }
    }

    private function progressEnd(): void
    {
        if ($this->isTty() && $this->lastProgress !== '') {
            fwrite(STDOUT, PHP_EOL);
        }
        $this->lastProgress = '';
    }

    private function isTty(): bool
    {
        return $this->ttyOut && defined('STDOUT') && function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    private function line(string $text): void
    {
        ($this->out)($text);
    }
}

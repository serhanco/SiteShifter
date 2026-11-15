<?php
/**
 * Registers "wp siteshifter" with WP-CLI.
 *
 *   wp --require=/path/to/siteshifter/wp-cli.php siteshifter export
 *   wp --require=/path/to/siteshifter/wp-cli.php siteshifter replace --from=http://old.test --to=https://new.com
 *
 * SiteShifter by Serhan Demirel — https://serhandemirel.com
 */
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Shift WordPress sites safely — by Serhan Demirel (https://serhandemirel.com).
 */
class SiteShifter_WP_CLI_Command
{
    /**
     * Packages this site into one zip (files, database, manifest).
     *
     * ## OPTIONS
     *
     * [--out=<file>]
     * : Where to write the package. Default: siteshifter-<date>.zip in the site root.
     *
     * [--scope=<scope>]
     * : root (whole site) or wp-content.
     * ---
     * default: root
     * ---
     *
     * [--all-tables]
     * : Also include tables without the site's prefix.
     */
    public function export($args, $assoc)
    {
        $this->run('export', ['path' => untrailingslashit(ABSPATH)] + $assoc);
    }

    /**
     * Replaces the old address with a new one in every table, safely for serialized data.
     *
     * ## OPTIONS
     *
     * --from=<url>
     * : Old site URL.
     *
     * --to=<url>
     * : New site URL.
     *
     * [--from-path=<path>]
     * : Old server path.
     *
     * [--to-path=<path>]
     * : New server path.
     */
    public function replace($args, $assoc)
    {
        $this->run('replace', ['path' => untrailingslashit(ABSPATH)] + $assoc);
        wp_cache_flush();
    }

    /**
     * Runs the pre-flight checks for moving a package into this server.
     *
     * ## OPTIONS
     *
     * --zip=<file>
     * : Package or files zip.
     *
     * --url=<url>
     * : The new site address.
     *
     * [--sql=<file>]
     * : SQL export when the zip has none.
     */
    public function preflight($args, $assoc)
    {
        global $wpdb;
        $assoc += ['dir' => untrailingslashit(ABSPATH), 'db-name' => DB_NAME, 'db-user' => DB_USER, 'db-pass' => DB_PASSWORD, 'db-host' => DB_HOST];
        $this->run('preflight', $assoc);
    }

    private function run(string $command, array $assoc)
    {
        $argv = ['siteshifter', $command];
        foreach ($assoc as $key => $value) {
            $argv[] = $value === true ? "--{$key}" : "--{$key}={$value}";
        }
        $app = new SiteShifter\Cli\Application(static function (string $line) {
            WP_CLI::line($line);
        });
        $code = $app->run($argv);
        if ($code !== 0) {
            WP_CLI::halt($code);
        }
    }
}

WP_CLI::add_command('siteshifter', 'SiteShifter_WP_CLI_Command');

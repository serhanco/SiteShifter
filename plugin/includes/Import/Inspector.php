<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

use SiteShifter\Package\Extractor;
use SiteShifter\Package\Manifest;
use SiteShifter\Preflight\Preflight;
use SiteShifter\Preflight\Result;

defined('ABSPATH') || exit;

/**
 * Dry run for an import: reads a package without changing anything and
 * reports what would happen, what is incompatible, and what is left out.
 * Pure (no WordPress calls): the target's facts arrive through $target.
 */
class Inspector
{
    /**
     * @param string $zip    path of the package
     * @param array  $target facts about this site: prefix, home, abspath, wp_version, wp_db_version, db (Connection|null), db_error
     *
     * @return array{ok:bool,package:array|null,rows:array[],blockers:bool}
     */
    public function inspect($zip, array $target)
    {
        $rows = [];
        try {
            $names = Extractor::entryNames($zip);
        } catch (\Throwable $e) {
            return $this->result(null, [$this->row('zip', Result::BLOCK, 'Package file', $e->getMessage(), 'Upload the package again.')]);
        }
        if (!in_array(Manifest::FILE, $names, true) || !in_array(Manifest::SQL, $names, true)) {
            return $this->result(null, [$this->row(
                'package_type',
                Result::BLOCK,
                'Not a SiteShifter package',
                'The zip does not contain ' . Manifest::FILE . ' and ' . Manifest::SQL . '.',
                'Create the package with SiteShifter (Export). For a hand-made zip and SQL dump use installer.php or the command line.'
            )]);
        }
        $manifest = json_decode((string) Extractor::readEntry($zip, ['strip' => '', 'target' => ''], Manifest::FILE), true);
        if (!is_array($manifest) || empty($manifest['site'])) {
            return $this->result(null, [$this->row('manifest', Result::BLOCK, 'Package description unreadable', Manifest::FILE . ' is damaged.', 'Create the package again.')]);
        }

        $layout = Extractor::detectLayout($names);
        $unpacked = Extractor::uncompressedSize($zip);
        $report = (new Preflight())->run([
            'target_wp_version' => $target['wp_version'] ?? null,
            'target_wp_db_version' => $target['wp_db_version'] ?? null,
            'target_dir' => $target['abspath'] ?? null,
            'new_url' => $target['home'] ?? '',
            'old_url' => $manifest['site']['url'] ?? null,
            'package_bytes' => (int) filesize($zip),
            'unpacked_bytes' => $unpacked,
            'needs_zip' => true,
            'manifest' => $manifest,
            'db' => $target['db'] ?? null,
            'db_error' => $target['db_error'] ?? null,
        ]);
        $rows = $report->toArray();

        $rows = array_merge($rows, $this->plan($manifest, $target, $names, $layout));

        return $this->result($this->summary($manifest, $zip, $unpacked, count($names)), $rows);
    }

    private function plan(array $m, array $target, array $names, array $layout)
    {
        $rows = [];
        $scope = $m['scope'] ?? [];
        $partial = !empty($scope['profile']) && $scope['profile'] !== 'full';
        $old = rtrim((string) ($m['site']['home'] ?? $m['site']['url'] ?? ''), '/');
        $new = rtrim((string) ($target['home'] ?? ''), '/');

        $rows[] = $this->row('plan_replace', Result::INFO, 'What an import would do', 'Replace mode: this site\'s database tables and the themes, plugins and uploads in the package are replaced by the package\'s. '
            . 'The old tables and folders are kept aside so the import can be undone.'
            . ($partial ? ' This package is partial (' . $scope['profile'] . '): tables and folders it does not contain are left as they are.' : ''));

        if ($old !== '' && $new !== '' && strcasecmp($old, $new) !== 0) {
            $rows[] = $this->row('plan_address', Result::INFO, 'Address change', 'Addresses inside the content will change from ' . $old . ' to ' . $new . ' (serialized data, Elementor, ACF and WPML values included).');
        }
        if (!empty($m['site']['table_prefix']) && !empty($target['prefix']) && $m['site']['table_prefix'] !== $target['prefix']) {
            $rows[] = $this->row(
                'plan_prefix',
                Result::BLOCK,
                'Different table prefix',
                'The package uses the prefix "' . $m['site']['table_prefix'] . '" but this site uses "' . $target['prefix'] . '".',
                'Not supported yet in the plugin. Use installer.php or the command line, which rewrite the prefix, or give this site the same prefix.'
            );
        }
        if (!empty($m['site']['multisite'])) {
            $rows[] = $this->row('plan_multisite', Result::BLOCK, 'Multisite package', 'The package comes from a multisite network.', 'Network packages are not supported in this version.');
        }
        if (!empty($scope['manual_uploads'])) {
            $rows[] = $this->row('plan_uploads', Result::WARN, 'Uploads are not in this package', 'The media library files were left out when the package was made.', 'Copy wp-content/uploads from the old site to this one yourself (SFTP or rsync).');
        }
        foreach ((array) ($scope['warnings'] ?? []) as $code) {
            if ($code === 'users_excluded') {
                $rows[] = $this->row('plan_users', Result::WARN, 'Users are not in this package', 'Posts keep their author number, which may not exist here.', 'Choose an existing user to own the imported content.');
            }
        }
        $core = $layout['has_core'] ? ' The package also holds WordPress core files; they are not copied (this site\'s WordPress stays).' : '';
        $rows[] = $this->row('plan_files', Result::INFO, 'Files', (int) ($m['files']['count'] ?? 0) . ' files. wp-config.php and this plugin are never replaced.' . $core);

        return $rows;
    }

    private function summary(array $m, $zip, $unpacked, $entries)
    {
        return [
            'source' => (string) ($m['site']['home'] ?? ''),
            'created' => (string) ($m['created'] ?? ''),
            'wp_version' => $m['site']['wp_version'] ?? null,
            'php_version' => $m['php']['version'] ?? null,
            'db_server' => $m['db']['server'] ?? null,
            'tables' => isset($m['db']['tables']) ? count((array) $m['db']['tables']) : 0,
            'profile' => (string) ($m['scope']['profile'] ?? 'full'),
            'bytes' => (int) filesize($zip),
            'unpacked' => (int) $unpacked,
            'entries' => (int) $entries,
        ];
    }

    private function row($id, $level, $title, $message, $fix = '')
    {
        return (new Result($id, $level, $title, $message, $fix))->toArray();
    }

    private function result($package, array $rows)
    {
        $blockers = false;
        foreach ($rows as $r) {
            if ($r['level'] === Result::BLOCK) {
                $blockers = true;
            }
        }

        return ['ok' => $package !== null, 'package' => $package, 'rows' => $rows, 'blockers' => $blockers];
    }
}

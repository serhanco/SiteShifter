<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Package\Manifest;

defined('ABSPATH') || exit;

/**
 * Opens a finished package and proves it is complete: it reads back, the
 * manifest parses, every file counted at export time is inside, and the SQL
 * creates every table the manifest lists. A package that fails here is never
 * offered for download.
 */
class PackageCheck
{
    /**
     * @return array{ok:bool,problems:string[],files:int,tables:int,bytes:int}
     */
    public static function run($zipPath)
    {
        $out = ['ok' => false, 'problems' => [], 'files' => 0, 'tables' => 0, 'bytes' => 0];
        if (!is_file($zipPath)) {
            $out['problems'][] = 'The package file is missing.';

            return $out;
        }
        $out['bytes'] = (int) filesize($zipPath);
        $zip = new \ZipArchive();
        $rc = $zip->open($zipPath);
        if ($rc !== true) {
            $out['problems'][] = 'The package cannot be opened (zip error ' . $rc . ').';

            return $out;
        }
        try {
            $manifest = json_decode((string) $zip->getFromName(Manifest::FILE), true);
            if (!is_array($manifest) || empty($manifest['db']['tables'])) {
                $out['problems'][] = 'The package manifest is missing or unreadable.';

                return $out;
            }
            $sqlStat = $zip->statName(Manifest::SQL);
            if ($sqlStat === false || (int) $sqlStat['size'] === 0) {
                $out['problems'][] = 'The database export is missing or empty.';

                return $out;
            }

            $files = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (strpos($name, 'files/') === 0 && substr($name, -1) !== '/') {
                    $files++;
                }
            }
            $out['files'] = $files;
            $expected = (int) ($manifest['files']['count'] ?? 0);
            if ($files < $expected) {
                $out['problems'][] = sprintf('The package holds %d files but %d were counted at export time.', $files, $expected);
            }

            // Stream the SQL and make sure every listed table is created.
            $wanted = array_keys($manifest['db']['tables']);
            $created = [];
            $stream = $zip->getStream(Manifest::SQL);
            if ($stream === false) {
                $out['problems'][] = 'The database export cannot be read back.';
            } else {
                $carry = '';
                while (!feof($stream)) {
                    $chunk = $carry . (string) fread($stream, 1048576);
                    if (preg_match_all('/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/mi', $chunk, $m)) {
                        foreach ($m[1] as $t) {
                            $created[$t] = true;
                        }
                    }
                    $carry = substr($chunk, -200); // a statement can straddle two chunks
                }
                fclose($stream);
            }
            $out['tables'] = count($created);
            $missing = array_values(array_diff($wanted, array_keys($created)));
            if ($missing !== []) {
                $out['problems'][] = 'These tables are not in the database export: ' . implode(', ', array_slice($missing, 0, 5)) . (count($missing) > 5 ? ', …' : '') . '.';
            }
            $out['ok'] = $out['problems'] === [];

            return $out;
        } finally {
            $zip->close();
        }
    }
}

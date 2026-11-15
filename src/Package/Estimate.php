<?php

namespace SiteShifter\Package;

use SiteShifter\Preflight\Preflight;
use SiteShifter\Preflight\Result;
use SiteShifter\Support\SizeScan;

/**
 * Before an export starts: how big will it be, will it fit on the disk, and
 * is the media library so large that copying it by hand is the saner way?
 */
class Estimate
{
    public const DEFAULT_THRESHOLD = 2147483648; // 2 GB

    /**
     * @param array<string,int> $bytes     bytes per area (SizeScan::AREAS)
     * @param int               $dbBytes   size of the database tables
     * @param float|false       $free      free disk space where the package is written, false when unknown
     * @param int               $threshold uploads size above which copying by hand is suggested
     *
     * @return array{rows:array[],need:int,recommend_no_media:bool}
     */
    public static function report(array $bytes, int $dbBytes, Scope $scope, $free, int $threshold = self::DEFAULT_THRESHOLD): array
    {
        $o = $scope->toArray();
        $bytes += array_fill_keys(SizeScan::AREAS, 0);
        $h = static function (int $n): string {
            return Preflight::human($n);
        };

        $files = ($o['media'] ? $bytes['uploads'] : 0) + ($o['plugins'] ? $bytes['plugins'] : 0) + ($o['themes'] ? $bytes['themes'] : 0)
            + ($o['other_files'] ? $bytes['content_other'] + $bytes['core'] : 0);
        $db = $scope->profile() === 'settings' ? 0 : $dbBytes;
        // The SQL dump is written once as a file and once more inside the zip; keep a 5% margin.
        $need = (int) ceil(($files + $db * 1.5) * 1.05);

        $rows = [];
        $rows[] = (new Result('sizes', Result::INFO, 'Site size', 'Uploads ' . $h($bytes['uploads']) . ', plugins ' . $h($bytes['plugins']) . ', themes ' . $h($bytes['themes'])
            . ', other files ' . $h($bytes['content_other'] + $bytes['core']) . ', database ' . $h($dbBytes) . '.'))->toArray();

        $bigMedia = $o['media'] && $bytes['uploads'] > $threshold;
        $noMediaNeed = (int) ceil((($files - ($o['media'] ? $bytes['uploads'] : 0)) + $db * 1.5) * 1.05);
        $short = $free !== false && $free < $need;

        if ($bigMedia) {
            $rows[] = (new Result(
                'large_uploads',
                Result::WARN,
                'The uploads folder is large',
                'The uploads folder is ' . $h($bytes['uploads']) . ' (limit for packing it: ' . $h($threshold) . '). A package that big is slow to build, download and upload.',
                'Choose "without media" and copy wp-content/uploads to the new site yourself (SFTP or rsync). The report will list what to copy.'
            ))->toArray();
        }

        if ($free === false) {
            $rows[] = (new Result('disk_space', Result::WARN, 'Disk space', 'Free space could not be measured on this host. The export needs about ' . $h($need) . '.', 'Make sure the account has at least that much free.'))->toArray();
        } elseif ($short) {
            $fixes = $o['media'] && $bytes['uploads'] > 0 && $free >= $noMediaNeed
                ? 'Choose "without media" (needs about ' . $h($noMediaNeed) . ') and copy wp-content/uploads yourself, or free up space.'
                : 'Free up space (old packages, backups, caches) or ask your host to raise the disk quota.';
            $rows[] = (new Result('disk_space', Result::BLOCK, 'Disk space', 'The export needs about ' . $h($need) . ', only ' . $h((int) $free) . ' is free.', $fixes))->toArray();
        } else {
            $rows[] = (new Result('disk_space', Result::OK, 'Disk space', 'Needs about ' . $h($need) . ', ' . $h((int) $free) . ' is free.'))->toArray();
        }

        return [
            'rows' => $rows,
            'need' => $need,
            'recommend_no_media' => $o['media'] && $bytes['uploads'] > 0 && ($bigMedia || ($short && $free >= $noMediaNeed)),
        ];
    }
}

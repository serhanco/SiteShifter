<?php

namespace SiteShifter\Support;

use SiteShifter\Database\Connection;

/** Helpers for the live percentages shown by the installer, CLI and plugin. */
class Progress
{
    /**
     * Fast row estimate from information_schema (no full scans on big
     * tables). Only used for progress; never for verification.
     *
     * @param string[] $tables
     */
    public static function estimateRows(Connection $db, array $tables): int
    {
        if ($tables === []) {
            return 0;
        }
        $list = implode(', ', array_map([$db, 'quote'], $tables));
        $rows = (int) $db->value('SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $list . ')');

        return max($rows, 1);
    }

    /** A percentage that only reaches 100 when the work is really finished. */
    public static function percent(float $done, float $total, bool $finished = false): int
    {
        if ($finished) {
            return 100;
        }
        if ($total <= 0) {
            return 0;
        }

        return (int) max(0, min(99, floor(100 * $done / $total)));
    }
}

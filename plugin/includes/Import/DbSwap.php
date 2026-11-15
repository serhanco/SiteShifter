<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

use SiteShifter\Database\Connection;

defined('ABSPATH') || exit;

/**
 * Puts the staging tables live in one atomic RENAME TABLE statement, keeping
 * the tables they replace under a backup prefix so the import can be undone.
 */
class DbSwap
{
    /**
     * @param bool $full a full package replaces every table of the site, a partial one only the tables it contains
     *
     * @return array{pairs:array[],backups:string[]} pairs are [from, to] renames in the order they are applied
     */
    public static function plan(Connection $db, $targetPrefix, $stagePrefix, $backupPrefix, $full)
    {
        $live = $db->tables($targetPrefix);
        $liveSet = array_flip($live);
        $backups = [];
        $backupPairs = [];
        $livePairs = [];
        $covered = [];
        foreach ($db->tables($stagePrefix) as $staged) {
            $final = $targetPrefix . substr($staged, strlen($stagePrefix));
            $covered[$final] = true;
            if (isset($liveSet[$final])) {
                $bak = self::backupName($backupPrefix, $final);
                $backupPairs[] = [$final, $bak];
                $backups[] = $bak;
            }
            $livePairs[] = [$staged, $final];
        }
        if ($full) {
            foreach ($live as $table) {
                if (!isset($covered[$table]) && strpos($table, $stagePrefix) !== 0 && strpos($table, $backupPrefix) !== 0) {
                    $bak = self::backupName($backupPrefix, $table);
                    $backupPairs[] = [$table, $bak];
                    $backups[] = $bak;
                }
            }
        }

        return ['pairs' => array_merge($backupPairs, $livePairs), 'backups' => $backups];
    }

    public static function backupName($backupPrefix, $table)
    {
        return substr($backupPrefix . $table, 0, 64);
    }

    /** One statement: either every rename happens or none does. */
    public static function apply(Connection $db, array $pairs)
    {
        if ($pairs === []) {
            return;
        }
        $db->exec('RENAME TABLE ' . implode(', ', array_map(static function ($p) {
            return Connection::ident($p[0]) . ' TO ' . Connection::ident($p[1]);
        }, $pairs)));
    }

    /** The exact reverse of apply(). */
    public static function undo(Connection $db, array $pairs)
    {
        self::apply($db, array_map(static function ($p) {
            return [$p[1], $p[0]];
        }, array_reverse($pairs)));
    }

    /** Drops tables (backups after the person has confirmed the import, staging leftovers after an undo). */
    public static function drop(Connection $db, array $tables)
    {
        foreach ($tables as $t) {
            $db->exec('DROP TABLE IF EXISTS ' . Connection::ident($t));
        }
    }
}

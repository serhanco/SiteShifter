<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Import;

use SiteShifter\Database\Connection;

defined('ABSPATH') || exit;

/**
 * Merge mode for settings and users. Works from the staged tables (already imported
 * under the staging prefix and address-rewritten) and changes only options and users
 * of the live site. Copies of the live tables are made first, so undo puts them back.
 */
class DbMerge
{
    /** Never taken from a package in merge mode: they would change the address, the active plugins or who may do what here. */
    const KEEP_LIVE = ['siteurl', 'home', 'active_plugins', 'db_version', 'initial_db_version', 'admin_email', 'blogname'];

    /** @return string[] live table names (without prefix) the merge changes */
    public static function tables()
    {
        return ['options', 'users', 'usermeta'];
    }

    /**
     * Counts what apply() would do, changing nothing.
     *
     * @return array{options_new:int,options_changed:int,users_new:int,users_clash:int}
     */
    public static function dryRun(Connection $db, $stage, $live)
    {
        $o = Connection::ident($stage . 'options');
        $lo = Connection::ident($live . 'options');
        $keep = self::keepList($db);
        $new = (int) $db->value("SELECT COUNT(*) FROM {$o} s WHERE s.option_name NOT IN ({$keep}) AND NOT EXISTS (SELECT 1 FROM {$lo} l WHERE l.option_name = s.option_name)");
        $changed = (int) $db->value("SELECT COUNT(*) FROM {$o} s JOIN {$lo} l ON l.option_name = s.option_name WHERE s.option_name NOT IN ({$keep}) AND s.option_name <> " . $db->quote($live . 'user_roles') . ' AND s.option_value <> l.option_value');
        $u = Connection::ident($stage . 'users');
        $lu = Connection::ident($live . 'users');
        $clash = (int) $db->value("SELECT COUNT(*) FROM {$u} s WHERE EXISTS (SELECT 1 FROM {$lu} l WHERE l.user_email = s.user_email OR l.user_login = s.user_login)");
        $all = (int) $db->value("SELECT COUNT(*) FROM {$u}");

        return ['options_new' => $new, 'options_changed' => $changed, 'users_new' => $all - $clash, 'users_clash' => $clash];
    }

    /**
     * @param string $backupPrefix prefix for the copies of the live tables
     *
     * @return array{copies:array[],user_map:array,stats:array}  copies are [live, backup] pairs for undo
     */
    public static function apply(Connection $db, $stage, $live, $backupPrefix, MergePolicy $policy)
    {
        $copies = [];
        $existing = array_flip($db->tables($backupPrefix));
        $leftover = [];
        foreach (self::tables() as $t) {
            $bak = DbSwap::backupName($backupPrefix, $live . $t);
            if (isset($existing[$bak])) {
                $leftover[] = [$live . $t, $bak];
            }
        }
        if ($leftover !== []) {
            self::undo($db, $leftover); // an earlier attempt died half way: go back to the start first
        }
        try {
            return self::run($db, $stage, $live, $backupPrefix, $policy, $copies);
        } catch (\Throwable $e) {
            self::undo($db, $copies);
            throw $e;
        }
    }

    private static function run(Connection $db, $stage, $live, $backupPrefix, MergePolicy $policy, array &$copies)
    {
        foreach (self::tables() as $t) {
            if (!in_array($live . $t, $db->tables($live), true)) {
                continue;
            }
            $bak = DbSwap::backupName($backupPrefix, $live . $t);
            $db->exec('CREATE TABLE ' . Connection::ident($bak) . ' LIKE ' . Connection::ident($live . $t));
            $db->exec('INSERT INTO ' . Connection::ident($bak) . ' SELECT * FROM ' . Connection::ident($live . $t));
            $copies[] = [$live . $t, $bak];
        }
        $stats = self::options($db, $stage, $live, $policy);
        $map = self::users($db, $stage, $live, $policy, $stats);

        return ['copies' => $copies, 'user_map' => $map, 'stats' => $stats];
    }

    /** Puts the copied tables back. */
    public static function undo(Connection $db, array $copies)
    {
        foreach ($copies as $c) {
            $db->exec('TRUNCATE TABLE ' . Connection::ident($c[0]));
            $db->exec('INSERT INTO ' . Connection::ident($c[0]) . ' SELECT * FROM ' . Connection::ident($c[1]));
            $db->exec('DROP TABLE IF EXISTS ' . Connection::ident($c[1]));
        }
    }

    private static function keepList(Connection $db)
    {
        return implode(',', array_map([$db, 'quote'], self::KEEP_LIVE));
    }

    private static function options(Connection $db, $stage, $live, MergePolicy $policy)
    {
        $s = Connection::ident($stage . 'options');
        $l = Connection::ident($live . 'options');
        $keep = self::keepList($db);
        $role = $db->quote($live . 'user_roles');
        $stats = ['options_added' => 0, 'options_overwritten' => 0];
        $db->exec("INSERT INTO {$l} (option_name, option_value, autoload) SELECT s.option_name, s.option_value, s.autoload FROM {$s} s WHERE s.option_name NOT IN ({$keep}) AND NOT EXISTS (SELECT 1 FROM {$l} x WHERE x.option_name = s.option_name)");
        $stats['options_added'] = (int) $db->value('SELECT ROW_COUNT()');
        if ($policy->get('settings') === 'overwrite') {
            // roles stay as they are here: replacing them could lock this site's own users out
            $db->exec("UPDATE {$l} l JOIN {$s} s ON s.option_name = l.option_name SET l.option_value = s.option_value, l.autoload = s.autoload WHERE s.option_name NOT IN ({$keep}) AND s.option_name <> {$role} AND l.option_value <> s.option_value");
            $stats['options_overwritten'] = (int) $db->value('SELECT ROW_COUNT()');
        }

        return $stats;
    }

    /** @return array<int,int> package user ID => ID on this site (0 when the user was skipped) */
    private static function users(Connection $db, $stage, $live, MergePolicy $policy, array &$stats)
    {
        $su = $stage . 'users';
        $lu = $live . 'users';
        $sm = Connection::ident($stage . 'usermeta');
        $lm = Connection::ident($live . 'usermeta');
        $cols = array_values(array_diff(array_column($db->rows('SHOW COLUMNS FROM ' . Connection::ident($lu)), 'Field'), ['ID']));
        $list = implode(', ', array_map(['SiteShifter\Database\Connection', 'ident'], $cols));
        $map = [];
        $stats['users_added'] = $stats['users_mapped'] = $stats['users_skipped'] = 0;
        foreach ($db->rows('SELECT ID, user_login, user_email FROM ' . Connection::ident($su) . ' ORDER BY ID') as $u) {
            $existing = $db->value('SELECT ID FROM ' . Connection::ident($lu) . ' WHERE user_email = ' . $db->quote($u['user_email']) . ' OR user_login = ' . $db->quote($u['user_login']) . ' ORDER BY ID LIMIT 1');
            if ($existing !== null) {
                $map[(int) $u['ID']] = $policy->get('users') === 'map' ? (int) $existing : 0;
                $stats[$policy->get('users') === 'map' ? 'users_mapped' : 'users_skipped']++;
                continue;
            }
            $db->exec('INSERT INTO ' . Connection::ident($lu) . " ({$list}) SELECT {$list} FROM " . Connection::ident($su) . ' WHERE ID = ' . (int) $u['ID']);
            $id = (int) $db->value('SELECT LAST_INSERT_ID()');
            $map[(int) $u['ID']] = $id;
            $stats['users_added']++;
            $db->exec('INSERT INTO ' . $lm . ' (user_id, meta_key, meta_value) SELECT ' . $id . ', meta_key, meta_value FROM ' . $sm . ' WHERE user_id = ' . (int) $u['ID']);
        }

        return $map;
    }
}

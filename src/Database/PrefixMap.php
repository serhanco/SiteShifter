<?php

namespace SiteShifter\Database;

/**
 * Renames the table prefix while a dump is imported (wp_posts -> ssi1a2b3c_posts),
 * and fixes the few places where WordPress stores the prefix inside the data
 * (the user_roles option and the capability keys in usermeta).
 */
class PrefixMap
{
    /** @var string */
    private $from;

    /** @var string */
    private $to;

    public function __construct(string $from, string $to)
    {
        if ($from === '' || $to === '' || !preg_match('/^[A-Za-z0-9_]+$/', $from . $to)) {
            throw new \InvalidArgumentException('Table prefixes may only contain letters, digits and underscores.');
        }
        $this->from = $from;
        $this->to = $to;
    }

    public function table(string $name): string
    {
        return strpos($name, $this->from) === 0 ? $this->to . substr($name, strlen($this->from)) : $name;
    }

    /** Rewrites the table name a DROP / CREATE / INSERT / ALTER statement works on. */
    public function transform(string $sql): string
    {
        $head = '/^(\s*(?:\/\*!\d+\s*)?(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES)\s+)`([^`]+)`/i';
        if (!preg_match($head, $sql, $m)) {
            return $sql;
        }
        $renamed = $this->table($m[2]);
        $sql = '' . $m[1] . '`' . $renamed . '`' . substr($sql, strlen($m[0]));
        if (preg_match('/^\s*(?:\/\*!\d+\s*)?(CREATE|ALTER)\b/i', $sql)) {
            $sql = preg_replace_callback('/(REFERENCES\s+)`([^`]+)`/i', function ($r) {
                return $r[1] . '`' . $this->table($r[2]) . '`';
            }, $sql);
        }

        return $sql;
    }

    /**
     * After the import: the data that carries the prefix.
     *
     * @param string $optionsTable  the (staging) options table
     * @param string $usermetaTable the (staging) usermeta table
     * @param string $finalPrefix   the prefix the tables will have when they go live
     */
    public function fixData(Connection $db, string $optionsTable, string $usermetaTable, string $finalPrefix): void
    {
        if ($finalPrefix === $this->from) {
            return;
        }
        $len = strlen($this->from);
        $o = Connection::ident($optionsTable);
        $u = Connection::ident($usermetaTable);
        $db->exec("UPDATE {$o} SET option_name = " . $db->quote($finalPrefix . 'user_roles') . ' WHERE option_name = ' . $db->quote($this->from . 'user_roles'));
        $db->exec("UPDATE {$u} SET meta_key = CONCAT(" . $db->quote($finalPrefix) . ", SUBSTRING(meta_key, " . ($len + 1) . ')) WHERE LEFT(meta_key, ' . $len . ') = ' . $db->quote($this->from));
    }
}

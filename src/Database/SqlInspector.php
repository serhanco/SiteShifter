<?php

namespace SiteShifter\Database;

/**
 * Reads a SQL dump that was NOT made by SiteShifter (cPanel / phpMyAdmin /
 * mysqldump export) and works out what the installer needs to know:
 * table prefix, site URL, collations, tables, active plugins and theme.
 * The result has the same shape as the "db"/"site"/"plugins" parts of a
 * package manifest, so the same pre-flight checks run in both modes.
 */
class SqlInspector
{
    /** @var int stop reading INSERTs after this many bytes per statement (options rows are small) */
    private $maxStatement;

    public function __construct(int $maxStatement = 4 * 1048576)
    {
        $this->maxStatement = $maxStatement;
    }

    public function inspect(string $file): array
    {
        $tables = [];
        $collations = [];
        $options = [];
        $wanted = ['siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'db_version'];
        $largest = 0;
        $multisite = false;

        $reader = new SqlReader($file);
        while (($sql = $reader->next()) !== null) {
            $largest = max($largest, strlen($sql));
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s(]+)`?/i', $sql, $m)) {
                $tables[$m[1]] = 0;
                if (preg_match_all('/COLLATE[\s=]+`?(\w+)`?/i', $sql, $c)) {
                    foreach ($c[1] as $collation) {
                        $collations[$collation] = true;
                    }
                }
                continue;
            }
            if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([^`\s(]+)`?/i', $sql, $m)) {
                continue;
            }
            $table = $m[1];
            if (isset($tables[$table])) {
                $tables[$table] += self::countTuples($sql);
            }
            if (substr($table, -7) === 'options' && strlen($sql) <= $this->maxStatement) {
                foreach ($wanted as $name) {
                    if (!isset($options[$name]) && ($value = self::optionValue($sql, $name)) !== null) {
                        $options[$name] = $value;
                    }
                }
            }
        }

        $prefix = self::detectPrefix(array_keys($tables));
        if ($prefix !== null) {
            $multisite = isset($tables[$prefix . 'blogs']) && isset($tables[$prefix . 'site']);
        }
        $foreign = $prefix === null ? [] : array_values(array_filter(array_keys($tables), static function ($t) use ($prefix) {
            return strpos($t, $prefix) !== 0;
        }));

        $active = [];
        if (isset($options['active_plugins'])) {
            $list = @unserialize($options['active_plugins'], ['allowed_classes' => false]);
            if (is_array($list)) {
                $active = array_values(array_filter($list, 'is_string'));
            }
        }

        return [
            'site' => [
                'url' => $options['siteurl'] ?? null,
                'home' => $options['home'] ?? null,
                'table_prefix' => $prefix,
                'multisite' => $multisite,
            ],
            'db' => [
                'tables' => $prefix === null ? $tables : array_intersect_key($tables, array_flip(array_filter(array_keys($tables), static function ($t) use ($prefix) {
                    return strpos($t, $prefix) === 0;
                }))),
                'foreign_tables' => $foreign,
                'collations' => array_keys($collations),
                'largest_row' => $largest,
                'db_version' => $options['db_version'] ?? null,
            ],
            'plugins' => ['active' => $active],
            'themes' => array_values(array_unique(array_filter([$options['stylesheet'] ?? null, $options['template'] ?? null]))),
        ];
    }

    /** WordPress prefix = the part before "options" on the table that has a sibling "posts" table. */
    public static function detectPrefix(array $tables): ?string
    {
        $set = array_flip($tables);
        $candidates = [];
        foreach ($tables as $table) {
            if (substr($table, -7) === 'options') {
                $prefix = substr($table, 0, -7);
                if (isset($set[$prefix . 'posts']) && isset($set[$prefix . 'postmeta'])) {
                    $candidates[] = $prefix;
                }
            }
        }
        if ($candidates === []) {
            return null;
        }
        // Prefer the shortest (multisite sub-sites look like wp_2_options).
        usort($candidates, static function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        return $candidates[0];
    }

    /** Pulls one option's value out of an INSERT into the options table. */
    public static function optionValue(string $sql, string $name): ?string
    {
        $pattern = "/\\(\\s*\\d+\\s*,\\s*'" . preg_quote($name, '/') . "'\\s*,\\s*'((?:[^'\\\\]|\\\\.|'')*)'/s";
        if (!preg_match($pattern, $sql, $m)) {
            return null;
        }

        return self::unescape($m[1]);
    }

    public static function unescape(string $value): string
    {
        $value = str_replace("''", "'", $value);

        return preg_replace_callback('/\\\\(.)/s', static function ($m) {
            $map = ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", 'b' => "\x08"];

            return $map[$m[1]] ?? $m[1];
        }, $value);
    }

    /** Counts "(...)" tuples in a multi-row INSERT without a full parse. */
    private static function countTuples(string $sql): int
    {
        $pos = stripos($sql, 'VALUES');
        if ($pos === false) {
            return 1;
        }
        $count = 0;
        $depth = 0;
        $quote = null;
        $len = strlen($sql);
        $i = $pos + 6;
        while ($i < $len) {
            if ($quote !== null) {
                $i += strcspn($sql, $quote . '\\', $i);
                if ($i >= $len) {
                    break;
                }
                if ($sql[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                $quote = null;
                $i++;
                continue;
            }
            $i += strcspn($sql, "'\"()", $i);
            if ($i >= $len) {
                break;
            }
            $c = $sql[$i];
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                if ($depth === 0) {
                    $count++;
                }
                $depth++;
            } else {
                $depth--;
            }
            $i++;
        }

        return max(1, $count);
    }
}

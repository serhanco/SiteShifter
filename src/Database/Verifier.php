<?php

namespace SiteShifter\Database;

/**
 * After-migration checks that turn "did everything move?" into a report:
 *
 *  - leftovers(): every column that still mentions the old host, including
 *    base64-encoded copies that a text replacement can never reach;
 *  - rowCounts() / compareRowCounts(): exact row counts per table, to
 *    compare the source manifest with the destination database.
 */
class Verifier
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @param string[] $tables
     * @param string[] $hosts old hosts, e.g. ['old.test', 'localhost:8080/mysite']
     * @return array<int,array{table:string,column:string,kind:string,count:int,sample:string}>
     */
    public function leftovers(array $tables, array $hosts, array $skipColumns = []): array
    {
        $needles = [];
        foreach (array_unique(array_filter($hosts)) as $host) {
            $needles[] = ['plain', $host];
            if (strpos($host, '/') !== false) {
                $needles[] = ['json', str_replace('/', '\\/', $host)];
            }
            foreach (self::base64Fragments($host) as $fragment) {
                $needles[] = ['base64', $fragment];
            }
        }

        $found = [];
        foreach ($tables as $table) {
            $skip = $skipColumns[$table] ?? [];
            foreach ($this->textColumns($table) as $column) {
                if (in_array($column, $skip, true)) {
                    continue;
                }
                foreach ($needles as [$kind, $needle]) {
                    $hit = $this->countLike($table, $column, $needle);
                    if ($hit['count'] === 0) {
                        continue;
                    }
                    // Rows where the host only appears in e-mail addresses are reported separately:
                    // keeping info@old-domain is usually intended.
                    if ($kind === 'plain' && strpos($needle, '/') === false
                        && $this->countLike($table, $column, '@' . $needle)['count'] === $hit['count']
                        && $this->countOutsideEmails($table, $column, $needle) === 0) {
                        $kind = 'email';
                    }
                    $found[] = ['table' => $table, 'column' => $column, 'kind' => $kind] + $hit;
                }
            }
        }

        return self::dedupe($found);
    }

    /** @return array<string,int> */
    public function rowCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->db->value('SELECT COUNT(*) FROM ' . Connection::ident($table));
        }

        return $counts;
    }

    /**
     * Compares source counts (from the package manifest) with the
     * destination. Table names are matched after swapping the prefix.
     *
     * @return array<int,array{table:string,source:int|null,target:int|null}> only the mismatches
     */
    public static function compareRowCounts(array $source, array $target, string $sourcePrefix = '', string $targetPrefix = ''): array
    {
        $normalize = static function (array $counts, string $prefix): array {
            $out = [];
            foreach ($counts as $table => $count) {
                $key = ($prefix !== '' && strpos($table, $prefix) === 0) ? substr($table, strlen($prefix)) : $table;
                $out[$key] = $count;
            }

            return $out;
        };
        $s = $normalize($source, $sourcePrefix);
        $t = $normalize($target, $targetPrefix);
        $mismatches = [];
        foreach (array_unique(array_merge(array_keys($s), array_keys($t))) as $key) {
            $a = $s[$key] ?? null;
            $b = $t[$key] ?? null;
            if ($a !== $b) {
                $mismatches[] = ['table' => $targetPrefix . $key, 'source' => $a, 'target' => $b];
            }
        }

        return $mismatches;
    }

    /**
     * The three base64 spellings a string can have, depending on its byte
     * offset inside the encoded value.
     *
     * @return string[]
     */
    public static function base64Fragments(string $needle): array
    {
        $fragments = [];
        foreach ([0 => 0, 1 => 2, 2 => 3] as $pad => $skip) {
            $encoded = base64_encode(str_repeat("\0", $pad) . $needle);
            $full = intdiv($pad + strlen($needle), 3) * 4;
            $fragment = substr($encoded, $skip, $full - $skip);
            if (strlen($fragment) >= 8) {
                $fragments[] = $fragment;
            }
        }

        return array_values(array_unique($fragments));
    }

    /** @return string[] */
    private function textColumns(string $table): array
    {
        $columns = [];
        foreach ($this->db->rows('SHOW COLUMNS FROM ' . Connection::ident($table)) as $col) {
            if (preg_match('/^((var)?char|(tiny|medium|long)?text|(tiny|medium|long)?blob|json)\b/i', $col['Type'])) {
                $columns[] = $col['Field'];
            }
        }

        return $columns;
    }

    /** @return array{count:int,sample:string} */
    private function countLike(string $table, string $column, string $needle): array
    {
        $like = $this->db->quote('%' . addcslashes($needle, '%_\\') . '%');
        $c = Connection::ident($column);
        $t = Connection::ident($table);
        $count = (int) $this->db->value("SELECT COUNT(*) FROM {$t} WHERE {$c} LIKE BINARY {$like}");
        $sample = '';
        if ($count > 0) {
            $value = (string) $this->db->value("SELECT {$c} FROM {$t} WHERE {$c} LIKE BINARY {$like} LIMIT 1");
            $pos = (int) strpos($value, $needle);
            $sample = substr($value, max(0, $pos - 40), strlen($needle) + 80);
        }

        return ['count' => $count, 'sample' => $sample];
    }

    /** Rows where the host appears somewhere other than right after an "@". */
    private function countOutsideEmails(string $table, string $column, string $needle): int
    {
        $c = Connection::ident($column);
        $t = Connection::ident($table);
        $stripped = "REPLACE({$c}, " . $this->db->quote('@' . $needle) . ", '')";
        $like = $this->db->quote('%' . addcslashes($needle, '%_\\') . '%');

        return (int) $this->db->value("SELECT COUNT(*) FROM {$t} WHERE {$stripped} LIKE BINARY {$like}");
    }

    private static function dedupe(array $found): array
    {
        $seen = [];
        $out = [];
        foreach ($found as $row) {
            $key = $row['table'] . '|' . $row['column'] . '|' . $row['kind'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }

        return $out;
    }
}

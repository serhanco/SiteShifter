<?php

namespace SiteShifter\Database;

use SiteShifter\Replace\Replacer;

/**
 * Runs the replacement over every text column of the given tables.
 *
 * Work is split into small batches and the method returns when the time
 * budget runs out, together with a state array. Calling run() again with
 * that state continues where it stopped, so a host with a 30-second
 * max_execution_time can still process a database of any size.
 */
class DatabaseReplacer
{
    private const TEXT_TYPES = '/^((var)?char|(tiny|medium|long)?text|(tiny|medium|long)?blob|json)\b/i';

    /** @var Connection */
    private $db;

    /** @var Replacer */
    private $replacer;

    /** @var string[] */
    private $tables;

    /** @var int */
    private $batch;

    /** @var array<string,string[]> table => skipped columns */
    private $skipColumns;

    /**
     * @param string[]              $tables
     * @param array<string,string[]> $skipColumns e.g. ['wp_posts' => ['guid']]
     */
    public function __construct(Connection $db, Replacer $replacer, array $tables, array $skipColumns = [], int $batch = 500)
    {
        $this->db = $db;
        $this->replacer = $replacer;
        $this->tables = array_values($tables);
        $this->skipColumns = $skipColumns;
        $this->batch = max(1, $batch);
    }

    public static function initialState(): array
    {
        return ['table' => 0, 'cursor' => null, 'offset' => 0, 'done' => false, 'stats' => [], 'rows_done' => 0, 'total_rows' => null];
    }

    public static function percent(array $state): int
    {
        return \SiteShifter\Support\Progress::percent($state['rows_done'] ?? 0, $state['total_rows'] ?? 0, $state['done']);
    }

    /**
     * @param float $deadline microtime(true) value after which no new batch starts
     */
    public function run(array $state, float $deadline): array
    {
        if (($state['total_rows'] ?? null) === null) {
            $state['total_rows'] = \SiteShifter\Support\Progress::estimateRows($this->db, $this->tables);
            $state['rows_done'] = 0;
        }
        $first = true; // always make progress, even when the deadline has already passed
        while (!$state['done'] && ($first || microtime(true) < $deadline)) {
            $first = false;
            if (!isset($this->tables[$state['table']])) {
                $state['done'] = true;
                break;
            }
            $table = $this->tables[$state['table']];
            $finished = $this->processBatch($table, $state);
            if ($finished) {
                $state['table']++;
                $state['cursor'] = null;
                $state['offset'] = 0;
            }
        }

        return $state;
    }

    /** Runs to completion with no time limit (CLI use). */
    public function runAll(): array
    {
        return $this->run(self::initialState(), INF);
    }

    /** @return bool true when the table is finished */
    private function processBatch(string $table, array &$state): bool
    {
        [$pk, $columns] = $this->describe($table);
        if (!isset($state['stats'][$table])) {
            $state['stats'][$table] = ['rows' => 0, 'changed' => 0, 'cells' => 0];
        }
        if ($columns === []) {
            return true;
        }

        $t = Connection::ident($table);
        $select = implode(', ', array_map([Connection::class, 'ident'], array_unique(array_merge($pk, $columns))));

        if ($pk !== []) {
            $where = $state['cursor'] === null ? '' : ' WHERE ' . $this->afterCursor($pk, $state['cursor']);
            $order = implode(', ', array_map([Connection::class, 'ident'], $pk));
            $rows = $this->db->rows("SELECT {$select} FROM {$t}{$where} ORDER BY {$order} LIMIT {$this->batch}");
        } else {
            $select = '*';
            $rows = $this->db->rows("SELECT * FROM {$t} LIMIT {$this->batch} OFFSET " . (int) $state['offset']);
            $state['offset'] += count($rows);
        }

        foreach ($rows as $row) {
            $state['stats'][$table]['rows']++;
            $state['rows_done']++;
            $changes = [];
            foreach ($columns as $column) {
                $value = $row[$column];
                if ($value === null || !$this->replacer->matches($value)) {
                    continue;
                }
                $new = $this->replacer->replace($value);
                if ($new !== $value) {
                    $changes[$column] = $new;
                }
            }
            if ($changes !== []) {
                $this->update($table, $pk, $row, $changes);
                $state['stats'][$table]['changed']++;
                $state['stats'][$table]['cells'] += count($changes);
            }
        }

        if ($pk !== [] && $rows !== []) {
            $last = end($rows);
            $state['cursor'] = array_map(static function ($col) use ($last) {
                return $last[$col];
            }, $pk);
        }

        return count($rows) < $this->batch;
    }

    private function update(string $table, array $pk, array $row, array $changes): void
    {
        $set = [];
        foreach ($changes as $column => $value) {
            $set[] = Connection::ident($column) . ' = ' . $this->db->quote($value);
        }
        $match = $pk !== [] ? $pk : array_keys($row);
        $where = [];
        foreach ($match as $column) {
            $where[] = Connection::ident($column) . ' <=> ' . $this->db->quote($row[$column]);
        }
        $this->db->exec(sprintf(
            'UPDATE %s SET %s WHERE %s LIMIT 1',
            Connection::ident($table),
            implode(', ', $set),
            implode(' AND ', $where)
        ));
    }

    /** Keyset condition (a, b) > (x, y) written out for portability. */
    private function afterCursor(array $pk, array $cursor): string
    {
        $or = [];
        for ($i = 0; $i < count($pk); $i++) {
            $and = [];
            for ($j = 0; $j < $i; $j++) {
                $and[] = Connection::ident($pk[$j]) . ' = ' . $this->db->quote($cursor[$j]);
            }
            $and[] = Connection::ident($pk[$i]) . ' > ' . $this->db->quote($cursor[$i]);
            $or[] = '(' . implode(' AND ', $and) . ')';
        }

        return '(' . implode(' OR ', $or) . ')';
    }

    /** @var array<string,array> */
    private $describeCache = [];

    /** @return array{0:string[],1:string[]} primary key columns, text columns */
    private function describe(string $table): array
    {
        if (isset($this->describeCache[$table])) {
            return $this->describeCache[$table];
        }
        $pk = [];
        $columns = [];
        $skip = $this->skipColumns[$table] ?? [];
        foreach ($this->db->rows('SHOW COLUMNS FROM ' . Connection::ident($table)) as $col) {
            if ($col['Key'] === 'PRI') {
                $pk[] = $col['Field'];
            }
            if (preg_match(self::TEXT_TYPES, $col['Type']) && !in_array($col['Field'], $skip, true)) {
                $columns[] = $col['Field'];
            }
        }
        if ($pk !== []) {
            // SHOW COLUMNS lists PK columns in table order; use index order instead.
            $keys = $this->db->rows('SHOW KEYS FROM ' . Connection::ident($table) . " WHERE Key_name = 'PRIMARY'");
            usort($keys, static function ($a, $b) {
                return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
            });
            $pk = array_column($keys, 'Column_name');
        }

        return $this->describeCache[$table] = [$pk, $columns];
    }
}

<?php

namespace SiteShifter\Database;

/**
 * Writes a portable SQL dump (readable by phpMyAdmin, the mysql client
 * and SiteShifter's importer), in resumable batches.
 */
class Dumper
{
    /** Keep each INSERT statement well below common max_allowed_packet values. */
    private const MAX_STATEMENT_BYTES = 512 * 1024;

    /** @var Connection */
    private $db;

    /** @var string[] */
    private $tables;

    /** @var string */
    private $file;

    /** @var int */
    private $batch;

    public function __construct(Connection $db, array $tables, string $file, int $batch = 1000)
    {
        $this->db = $db;
        $this->tables = array_values($tables);
        $this->file = $file;
        $this->batch = max(1, $batch);
    }

    public static function initialState(): array
    {
        return ['started' => false, 'table' => 0, 'cursor' => null, 'offset' => 0, 'table_started' => false, 'done' => false, 'rows' => [], 'largest_row' => 0, 'rows_done' => 0, 'total_rows' => null];
    }

    public static function percent(array $state): int
    {
        return \SiteShifter\Support\Progress::percent($state['rows_done'] ?? 0, $state['total_rows'] ?? 0, $state['done']);
    }

    public function run(array $state, float $deadline): array
    {
        $fh = fopen($this->file, $state['started'] ? 'ab' : 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot write {$this->file}");
        }
        try {
            if (($state['total_rows'] ?? null) === null) {
                $state['total_rows'] = \SiteShifter\Support\Progress::estimateRows($this->db, $this->tables);
            }
            if (!$state['started']) {
                $this->write($fh, $this->header());
                $state['started'] = true;
            }
            while (!$state['done'] && microtime(true) < $deadline) {
                if (!isset($this->tables[$state['table']])) {
                    $this->write($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n-- SiteShifter dump complete\n");
                    $state['done'] = true;
                    break;
                }
                $table = $this->tables[$state['table']];
                if (!$state['table_started']) {
                    $this->write($fh, $this->createStatement($table));
                    $state['table_started'] = true;
                    $state['rows'][$table] = 0;
                }
                if ($this->dumpBatch($fh, $table, $state)) {
                    $state['table']++;
                    $state['table_started'] = false;
                    $state['cursor'] = null;
                    $state['offset'] = 0;
                }
            }
        } finally {
            fclose($fh);
        }

        return $state;
    }

    public function runAll(): array
    {
        return $this->run(self::initialState(), INF);
    }

    private function header(): string
    {
        return "-- SiteShifter SQL dump\n"
            . '-- Server: ' . $this->db->serverVersion() . "\n"
            . '-- Created: ' . gmdate('Y-m-d H:i:s') . " UTC\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET time_zone = '+00:00';\n\n";
    }

    private function createStatement(string $table): string
    {
        $t = Connection::ident($table);
        $rows = $this->db->rows("SHOW CREATE TABLE {$t}");
        $create = $rows[0]['Create Table'];

        return "\n--\n-- Table {$table}\n--\n\nDROP TABLE IF EXISTS {$t};\n{$create};\n\n";
    }

    /** @return bool table finished */
    private function dumpBatch($fh, string $table, array &$state): bool
    {
        [$pk, $columns, $binary] = $this->describe($table);
        $t = Connection::ident($table);
        $colList = implode(', ', array_map([Connection::class, 'ident'], $columns));

        if ($pk !== []) {
            $where = $state['cursor'] === null ? '' : ' WHERE ' . self::afterCursor($this->db, $pk, $state['cursor']);
            $order = implode(', ', array_map([Connection::class, 'ident'], $pk));
            $sql = "SELECT {$colList} FROM {$t}{$where} ORDER BY {$order} LIMIT {$this->batch}";
        } else {
            $sql = "SELECT {$colList} FROM {$t} LIMIT {$this->batch} OFFSET " . (int) $state['offset'];
        }
        $result = $this->db->query($sql);
        $count = 0;
        $values = [];
        $size = 0;
        $last = null;
        $prefix = "INSERT INTO {$t} ({$colList}) VALUES\n";
        while ($row = $result->fetch_assoc()) {
            $count++;
            $last = $row;
            $tuple = '(' . implode(', ', $this->encodeRow($row, $binary)) . ')';
            $state['largest_row'] = max($state['largest_row'], strlen($tuple));
            if ($values !== [] && $size + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                $this->write($fh, $prefix . implode(",\n", $values) . ";\n");
                $values = [];
                $size = 0;
            }
            $values[] = $tuple;
            $size += strlen($tuple);
        }
        $result->free();
        if ($values !== []) {
            $this->write($fh, $prefix . implode(",\n", $values) . ";\n");
        }
        $state['rows'][$table] += $count;
        $state['rows_done'] = ($state['rows_done'] ?? 0) + $count;
        $state['offset'] += $count;
        if ($pk !== [] && $last !== null) {
            $state['cursor'] = array_map(static function ($c) use ($last) {
                return $last[$c];
            }, $pk);
        }

        return $count < $this->batch;
    }

    private function encodeRow(array $row, array $binary): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            if ($value === null) {
                $out[] = 'NULL';
            } elseif (isset($binary[$column])) {
                $out[] = $value === '' ? "''" : '0x' . bin2hex($value);
            } else {
                $out[] = $this->db->quote($value);
            }
        }

        return $out;
    }

    /** @var array<string,array> */
    private $cache = [];

    /** @return array{0:string[],1:string[],2:array<string,bool>} */
    private function describe(string $table): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }
        $columns = [];
        $binary = [];
        foreach ($this->db->rows('SHOW FULL COLUMNS FROM ' . Connection::ident($table)) as $col) {
            if (stripos($col['Extra'] ?? '', 'GENERATED') !== false) {
                continue; // generated columns are recomputed by the server
            }
            $columns[] = $col['Field'];
            if (preg_match('/^(tiny|medium|long)?blob|^(var)?binary|^bit|^geometry|^point|^linestring|^polygon/i', $col['Type'])) {
                $binary[$col['Field']] = true;
            }
        }
        $keys = $this->db->rows('SHOW KEYS FROM ' . Connection::ident($table) . " WHERE Key_name = 'PRIMARY'");
        usort($keys, static function ($a, $b) {
            return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
        });

        return $this->cache[$table] = [array_column($keys, 'Column_name'), $columns, $binary];
    }

    public static function afterCursor(Connection $db, array $pk, array $cursor): string
    {
        $or = [];
        foreach ($pk as $i => $col) {
            $and = [];
            for ($j = 0; $j < $i; $j++) {
                $and[] = Connection::ident($pk[$j]) . ' = ' . $db->quote($cursor[$j]);
            }
            $and[] = Connection::ident($col) . ' > ' . $db->quote($cursor[$i]);
            $or[] = '(' . implode(' AND ', $and) . ')';
        }

        return '(' . implode(' OR ', $or) . ')';
    }

    private function write($fh, string $data): void
    {
        if (fwrite($fh, $data) !== strlen($data)) {
            throw new \RuntimeException('Could not write the SQL dump — is the disk full?');
        }
    }
}

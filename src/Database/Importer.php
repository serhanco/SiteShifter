<?php

namespace SiteShifter\Database;

/**
 * Imports a SQL dump in resumable steps, fixing the incompatibilities
 * that usually make an import fail half way:
 *
 *  - collations the server does not know (MySQL 8 "0900" collations on
 *    MariaDB or MySQL 5.7) are replaced using a plan from Preflight;
 *  - DEFINER clauses (views, triggers) that name a user that does not
 *    exist on the new server are removed;
 *  - MariaDB-only table options (Aria engine, PAGE_CHECKSUM, TRANSACTIONAL)
 *    are dropped when importing into MySQL.
 */
class Importer
{
    /** @var Connection */
    private $db;

    /** @var string */
    private $file;

    /** @var array<string,string> */
    private $collations;

    /** @var bool */
    private $targetIsMariaDb;

    /**
     * @param array<string,string> $collations from Preflight::collationPlan()['convert']
     */
    public function __construct(Connection $db, string $file, array $collations = [])
    {
        $this->db = $db;
        $this->file = $file;
        $this->collations = $collations;
        $this->targetIsMariaDb = $db->isMariaDb();
    }

    public static function initialState(): array
    {
        return ['offset' => 0, 'delimiter' => ';', 'statements' => 0, 'done' => false, 'size' => 0];
    }

    public static function percent(array $state): int
    {
        return \SiteShifter\Support\Progress::percent($state['offset'] ?? 0, $state['size'] ?? 0, $state['done']);
    }

    public function run(array $state, float $deadline): array
    {
        $reader = new SqlReader($this->file, (int) $state['offset'], $state['delimiter']);
        $state['size'] = $reader->size();
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $this->db->exec('SET NAMES utf8mb4');

        $first = true; // always make progress, even when the deadline has already passed
        while ($first || microtime(true) < $deadline) {
            $first = false;
            $statement = $reader->next();
            if ($statement === null) {
                $state['done'] = true;
                $state['offset'] = $reader->offset();
                break;
            }
            $sql = $this->transform($statement);
            if ($sql !== '') {
                try {
                    $this->db->exec($sql);
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException(sprintf(
                        'Import stopped at byte %d of %s (statement %d): %s',
                        $state['offset'],
                        basename($this->file),
                        $state['statements'] + 1,
                        $e->getMessage()
                    ), 0, $e);
                }
            }
            $state['statements']++;
            $state['offset'] = $reader->offset();
            $state['delimiter'] = $reader->delimiter();
        }

        return $state;
    }

    public function runAll(): array
    {
        return $this->run(self::initialState(), INF);
    }

    public function transform(string $sql): string
    {
        if (!preg_match('/^\s*(\/\*!\d*\s*)?(CREATE|ALTER|SET|DROP)\b/i', $sql)) {
            return $sql; // data statements are passed through untouched
        }
        // Session settings this importer manages itself.
        if (preg_match('/^\s*(\/\*!\d*\s*)?SET\s+(@OLD_|@@SESSION\.SQL_LOG_BIN|@@GLOBAL\.GTID_PURGED|SQL_LOG_BIN)/i', $sql)
            || preg_match('/^\s*(\/\*!\d*\s*)?SET\s+[^;]*=\s*@OLD_/i', $sql)) {
            return '';
        }
        foreach ($this->collations as $from => $to) {
            $sql = preg_replace('/\b' . preg_quote($from, '/') . '\b/', $to, $sql);
        }
        $sql = preg_replace('/\s*DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|[^\s*]+)/i', '', $sql);
        if (!$this->targetIsMariaDb) {
            $sql = preg_replace('/\bENGINE\s*=\s*Aria\b/i', 'ENGINE=InnoDB', $sql);
            $sql = preg_replace('/\s*\b(PAGE_CHECKSUM|TRANSACTIONAL)\s*=\s*\d+/i', '', $sql);
        }

        return $sql;
    }

    /**
     * Renames existing tables with the given prefix out of the way, so a
     * failed import can be rolled back. Returns old name => backup name.
     *
     * @return array<string,string>
     */
    public static function backupTables(Connection $db, string $prefix, ?string $backupPrefix = null): array
    {
        $backupPrefix = $backupPrefix ?: 'ssb' . date('ymdHi') . '_';
        $renamed = [];
        foreach ($db->tables($prefix) as $table) {
            $backup = substr($backupPrefix . $table, 0, 64);
            $db->exec('RENAME TABLE ' . Connection::ident($table) . ' TO ' . Connection::ident($backup));
            $renamed[$table] = $backup;
        }

        return $renamed;
    }

    /** Puts tables renamed by backupTables() back, dropping what the import created. */
    public static function restoreTables(Connection $db, array $renamed): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($renamed as $table => $backup) {
            $db->exec('DROP TABLE IF EXISTS ' . Connection::ident($table));
            $db->exec('RENAME TABLE ' . Connection::ident($backup) . ' TO ' . Connection::ident($table));
        }
    }
}

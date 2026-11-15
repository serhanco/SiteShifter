<?php

namespace SiteShifter\Database;

/**
 * Thin mysqli wrapper that turns every failure into an exception with a
 * readable message, so callers never continue after a silent DB error.
 */
class Connection
{
    /** @var \mysqli */
    private $db;

    /** @var string */
    private $name;

    public function __construct(string $host, string $user, string $pass, string $name, string $charset = 'utf8mb4')
    {
        if (!class_exists('mysqli')) {
            throw new \RuntimeException('The PHP mysqli extension is not installed.');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        [$hostname, $port, $socket] = self::parseHost($host);
        $db = mysqli_init();
        if (!@$db->real_connect($hostname, $user, $pass, $name, $port, $socket)) {
            throw new \RuntimeException('Could not connect to the database: ' . mysqli_connect_error(), (int) mysqli_connect_errno());
        }
        if (!$db->set_charset($charset)) {
            $db->set_charset('utf8');
        }
        $this->db = $db;
        $this->name = $name;
        $this->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    }

    public static function fromConfig(array $config): self
    {
        return new self($config['host'], $config['user'], $config['pass'], $config['name'], $config['charset'] ?? 'utf8mb4');
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return \mysqli_result|bool */
    public function query(string $sql)
    {
        $result = $this->db->query($sql);
        if ($result === false) {
            throw new \RuntimeException('Database error: ' . $this->db->error . ' — query: ' . self::shorten($sql), (int) $this->db->errno);
        }

        return $result;
    }

    public function exec(string $sql): void
    {
        $result = $this->query($sql);
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
    }

    /** @return array<int,array<string,string|null>> */
    public function rows(string $sql): array
    {
        $result = $this->query($sql);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /** @return string|null */
    public function value(string $sql)
    {
        $result = $this->query($sql);
        $row = $result->fetch_row();
        $result->free();

        return $row === null ? null : $row[0];
    }

    public function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . $this->db->real_escape_string($value) . "'";
    }

    public static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function serverVersion(): string
    {
        return (string) $this->value('SELECT VERSION()');
    }

    public function isMariaDb(): bool
    {
        return stripos($this->serverVersion(), 'mariadb') !== false;
    }

    /** @return string[] */
    public function tables(string $prefix = ''): array
    {
        $rows = $this->rows("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        foreach ($rows as $row) {
            $name = (string) reset($row);
            if ($prefix === '' || strpos($name, $prefix) === 0) {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    public function raw(): \mysqli
    {
        return $this->db;
    }

    private static function parseHost(string $host): array
    {
        $port = null;
        $socket = null;
        if (preg_match('/^(.*):(\d+)$/', $host, $m)) {
            $host = $m[1];
            $port = (int) $m[2];
        } elseif (preg_match('/^(.*):(\/.+)$/', $host, $m)) {
            $host = $m[1];
            $socket = $m[2];
        }

        return [$host === '' ? 'localhost' : $host, $port, $socket];
    }

    private static function shorten(string $sql): string
    {
        return strlen($sql) > 300 ? substr($sql, 0, 300) . '…' : $sql;
    }
}

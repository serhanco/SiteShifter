<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Database\SqlReader;

class SqlReaderTest extends TestCase
{
    private const SQL = <<<'SQL'
-- phpMyAdmin SQL Dump
/*!40101 SET NAMES utf8mb4 */;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
# hash comment
CREATE TABLE `wp_options` (
  `option_id` bigint(20) NOT NULL, /* inline; comment */
  `option_value` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO `wp_options` VALUES (1,'a;b'),(2,'it''s -- not a comment'),(3,'back\\slash\'q;'),(4,"dq;\"x"),(5,'çğıöşü; #');
DELIMITER ;;
CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = 2; END;;
DELIMITER ;
INSERT INTO `wp_posts` VALUES (1,'last')
SQL;

    private function file(string $sql): string
    {
        $f = tempnam(sys_get_temp_dir(), 'sql');
        file_put_contents($f, $sql);

        return $f;
    }

    private function all(SqlReader $r): array
    {
        $out = [];
        while (($s = $r->next()) !== null) {
            $out[] = $s;
        }

        return $out;
    }

    private function expected(): array
    {
        return [
            '/*!40101 SET NAMES utf8mb4 */',
            'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"',
            "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) NOT NULL,  \n  `option_value` longtext\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci",
            "INSERT INTO `wp_options` VALUES (1,'a;b'),(2,'it''s -- not a comment'),(3,'back\\\\slash\\'q;'),(4,\"dq;\\\"x\"),(5,'çğıöşü; #')",
            'CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = 2; END',
            "INSERT INTO `wp_posts` VALUES (1,'last')",
        ];
    }

    public function testSplitsStatements(): void
    {
        $this->assertSame($this->expected(), $this->all(new SqlReader($this->file(self::SQL))));
    }

    /** Every chunk size forces buffer boundaries to fall inside quotes, comments and delimiters. */
    public function testSameResultWithTinyChunks(): void
    {
        $f = $this->file(self::SQL);
        foreach ([1, 2, 3, 5, 7, 13] as $chunk) {
            $this->assertSame($this->expected(), $this->all(new SqlReader($f, 0, ';', $chunk)), "chunk {$chunk}");
        }
    }

    public function testResumesFromOffset(): void
    {
        $f = $this->file(self::SQL);
        foreach ([1, 4, 1048576] as $chunk) {
            $got = [];
            $offset = 0;
            $delimiter = ';';
            while (true) {
                $r = new SqlReader($f, $offset, $delimiter, $chunk);
                $s = $r->next();
                if ($s === null) {
                    break;
                }
                $got[] = $s;
                $offset = $r->offset();
                $delimiter = $r->delimiter();
            }
            $this->assertSame($this->expected(), $got, "chunk {$chunk}");
        }
    }

    public function testBomIsSkipped(): void
    {
        $this->assertSame(['SELECT 1'], $this->all(new SqlReader($this->file("\xEF\xBB\xBFSELECT 1;"))));
    }
}

<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Database\Importer;

class ImporterTransformTest extends TestCase
{
    private function importer(bool $maria): Importer
    {
        $r = new \ReflectionClass(Importer::class);
        $i = $r->newInstanceWithoutConstructor();
        foreach (['collations' => ['utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci'], 'targetIsMariaDb' => $maria] as $k => $v) {
            $p = $r->getProperty($k);
            $p->setAccessible(true);
            $p->setValue($i, $v);
        }

        return $i;
    }

    public function testTransforms(): void
    {
        $mysql = $this->importer(false);
        $this->assertSame('CREATE TABLE t (a int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci', $mysql->transform('CREATE TABLE t (a int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'));
        $this->assertSame('CREATE TABLE t (a int) ENGINE=InnoDB', $mysql->transform('CREATE TABLE t (a int) ENGINE=Aria PAGE_CHECKSUM=1 TRANSACTIONAL=1'));
        $this->assertSame('CREATE VIEW v AS SELECT 1', $mysql->transform('CREATE DEFINER=`root`@`localhost` VIEW v AS SELECT 1'));
        $this->assertSame('', $mysql->transform('/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */'));
        $this->assertSame('', $mysql->transform('SET @OLD_SQL_MODE=@@SQL_MODE'));
        $data = "INSERT INTO t VALUES ('utf8mb4_0900_ai_ci DEFINER=`a`@`b`')";
        $this->assertSame($data, $mysql->transform($data), 'data is never rewritten');
        $this->assertStringContainsString('ENGINE=Aria', $this->importer(true)->transform('CREATE TABLE t (a int) ENGINE=Aria'));
    }
}

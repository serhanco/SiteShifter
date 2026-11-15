<?php

namespace SiteShifter\Tests\Integration;

use SiteShifter\Preflight\Preflight;
use SiteShifter\Preflight\Result;

class PreflightDatabaseTest extends DatabaseTestCase
{
    public function testDatabaseChecks(): void
    {
        $this->createWordPressTables('wp_');
        $report = (new Preflight())->database($this->db, ['manifest' => [
            'site' => ['table_prefix' => 'wp_'],
            'db' => ['collations' => ['utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci'], 'foreign_tables' => ['crm_contacts'], 'largest_row' => 1024],
        ]]);
        $this->assertSame(Result::OK, $report->get('db_connect')->level);
        $this->assertSame(Result::OK, $report->get('db_privileges')->level);
        $this->assertSame(Result::WARN, $report->get('db_collation')->level, 'MariaDB has no 0900 collations');
        $this->assertStringContainsString('utf8mb4_0900_ai_ci → utf8mb4_unicode_520_ci', $report->get('db_collation')->message);
        $this->assertSame(Result::WARN, $report->get('db_existing_tables')->level);
        $this->assertSame(Result::WARN, $report->get('db_foreign_tables')->level);
        $this->assertNull($report->get('db_packet'));
        $this->assertSame([], $this->db->tables('siteshifter_probe'));
    }

    public function testNoConnectionBlocks(): void
    {
        $report = (new Preflight())->database(null, ['db_error' => 'Access denied']);
        $this->assertTrue($report->hasBlockers());
    }
}

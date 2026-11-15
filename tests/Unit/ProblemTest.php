<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Support\Problem;

class ProblemTest extends TestCase
{
    /** @dataProvider cases */
    public function testClassifiesKnownFailures(string $message, int $errno, string $code, bool $retryable): void
    {
        $p = Problem::classify(new \RuntimeException($message, $errno));
        $this->assertSame($code, $p['code']);
        $this->assertSame($retryable, $p['retryable']);
        $this->assertNotSame('', $p['fix']);
        $this->assertSame($message, $p['message']);
    }

    public function cases(): array
    {
        return [
            'cannot write' => ['Cannot write /var/www/x/database.sql — check folder permissions.', 0, Problem::PERM_WRITE, false],
            'permission denied' => ['file_put_contents(/x): Permission denied', 0, Problem::PERM_WRITE, false],
            'work folder' => ['Cannot create the SiteShifter work folder in wp-content. Check folder permissions.', 0, Problem::PERM_WRITE, false],
            'disk full text' => ['Could not write the SQL dump — is the disk full?', 0, Problem::DISK_FULL, false],
            'job state disk' => ['Cannot save job state (disk full?).', 0, Problem::DISK_FULL, false],
            'table full' => ['Database error: The table is full — query: INSERT', 1114, Problem::DISK_FULL, false],
            'open_basedir' => ['fopen(): open_basedir restriction in effect. File(/tmp/x) is not within the allowed path(s)', 0, Problem::OPEN_BASEDIR, false],
            'db privilege' => ['Database error: Access denied for user x to database y — query: DROP', 1044, Problem::DB_PRIVILEGE, false],
            'db drop privilege' => ['Database error: DROP command denied to user — query: DROP', 1142, Problem::DB_PRIVILEGE, false],
            'db auth' => ['Could not connect to the database: Access denied for user', 1045, Problem::DB_AUTH, false],
            'db connect' => ['Could not connect to the database: Connection refused', 2002, Problem::DB_CONNECT, true],
            'db gone' => ['Database error: MySQL server has gone away — query: INSERT', 2006, Problem::DB_GONE, true],
            'db packet' => ['Database error: Got a packet bigger than max_allowed_packet — query', 1153, Problem::DB_PACKET, false],
            'db deadlock' => ['Database error: Deadlock found', 1213, Problem::DB_LOCK, true],
            'zip ext' => ['The PHP zip extension is not installed.', 0, Problem::EXT_MISSING, false],
            'mysqli ext' => ['The PHP mysqli extension is not installed.', 0, Problem::EXT_MISSING, false],
            'bad zip' => ['Cannot open /x.zip (zip error 19). The file may be incomplete — upload it again', 0, Problem::ZIP_BAD, false],
            'busy' => ['The job is busy; try again in a moment.', 0, Problem::FILE_LOCKED, true],
            'unknown' => ['something odd', 0, Problem::UNKNOWN, true],
        ];
    }

    public function testEveryCodeHasATitleAFixAndIsDescribed(): void
    {
        foreach (Problem::codes() as $code) {
            $d = Problem::describe($code);
            $this->assertNotNull($d);
            $this->assertMatchesRegularExpression('/^SS_E_[A-Z_]+$/', $code);
            $this->assertNotSame('', $d[0]);
            $this->assertNotSame('', $d[1]);
        }
        $this->assertNull(Problem::describe('SS_E_NOPE'));
        $this->assertTrue(Problem::isRetryable('SS_E_NOPE'));
    }

    public function testFatalErrorsAreRecognised(): void
    {
        $this->assertSame(Problem::MEMORY, Problem::fromFatal(['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted']));
        $this->assertSame(Problem::TIMEOUT, Problem::fromFatal(['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded']));
        $this->assertNull(Problem::fromFatal(['type' => E_WARNING, 'message' => 'Allowed memory size']));
        $this->assertNull(Problem::fromFatal(['type' => E_ERROR, 'message' => 'Call to undefined function']));
        $this->assertNull(Problem::fromFatal(null));
    }

    public function testThePluginTranslatesEveryCode(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../plugin/includes/Admin/ProblemText.php');
        foreach (Problem::codes() as $code) {
            $this->assertStringContainsString("'" . $code . "' =>", $source, "ProblemText is missing {$code}");
        }
    }
}

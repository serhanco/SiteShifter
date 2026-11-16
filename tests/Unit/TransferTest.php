<?php

namespace SiteShifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteShifter\Transfer\Backoff;
use SiteShifter\Transfer\Download;
use SiteShifter\Transfer\FileSource;
use SiteShifter\Transfer\FolderStorage;
use SiteShifter\Transfer\Pieces;
use SiteShifter\Transfer\Sink;
use SiteShifter\Transfer\Source;
use SiteShifter\Transfer\TransferError;
use SiteShifter\Transfer\Upload;

class TransferTest extends TestCase
{
    private $base;
    private $file;
    private $now = 1000.0;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ss-tr-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0755, true);
        $this->file = $this->base . '/site.zip';
        $fh = fopen($this->file, 'wb');
        for ($i = 0; $i < 17; $i++) { // 2 full pieces and a short one
            fwrite($fh, random_bytes(1048576));
        }
        fwrite($fh, random_bytes(1234));
        fclose($fh);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function clock()
    {
        return function () {
            return $this->now;
        };
    }

    private function sleeper()
    {
        return function ($s) {
            $this->now += $s;
        };
    }

    private function sink(): Sink
    {
        $dir = $this->base . '/sink';
        @mkdir($dir);

        return new class($dir) implements Sink {
            public $dir;

            public function __construct($dir)
            {
                $this->dir = $dir;
            }

            public function begin($name, $size)
            {
                file_put_contents($this->dir . '/x.part', '');

                return 'x';
            }

            public function append($id, $offset, $pieceFile)
            {
                $part = $this->dir . '/' . $id . '.part';
                clearstatcache();
                if (filesize($part) === $offset) {
                    file_put_contents($part, file_get_contents($pieceFile), FILE_APPEND);
                }
                clearstatcache();

                return filesize($part);
            }

            public function finish($id)
            {
                rename($this->dir . '/' . $id . '.part', $this->dir . '/' . $id . '.zip');

                return ['file' => $this->dir . '/' . $id . '.zip'];
            }
        };
    }

    private function drain($worker, array $state, $steps = 100)
    {
        for ($i = 0; $i < $steps && !$state['done']; $i++) {
            $state = $worker->run($state, $this->now - 1); // one step per call
            $this->now += 1;
        }

        return $state;
    }

    public function testPiecesListingIsStableAndRejectsTampering(): void
    {
        $l = Pieces::ofFile($this->file);
        $this->assertSame(3, count($l['hashes']));
        $this->assertSame(filesize($this->file), $l['size']);
        $this->assertSame($l, Pieces::parse(json_encode($l)));
        $bad = $l;
        $bad['hashes'][1] = str_repeat('0', 64);
        $this->assertNull(Pieces::parse($bad), 'digest no longer matches');
        $short = $l;
        array_pop($short['hashes']);
        unset($short['digest']);
        $this->assertNull(Pieces::parse($short), 'wrong piece count');
        $this->assertNull(Pieces::parse('not json'));
    }

    public function testPiecesHashingIsResumable(): void
    {
        $s = Pieces::start($this->file);
        $s = Pieces::run($s, 0); // past deadline: still one piece
        $this->assertCount(1, $s['hashes']);
        $s = json_decode(json_encode($s), true); // survives being saved between requests
        while (!$s['done']) {
            $s = Pieces::run($s, 0);
        }
        $this->assertSame(Pieces::ofFile($this->file)['digest'], Pieces::listing($s)['digest']);
    }

    public function testBackoffWaitsLongerThenGivesUp(): void
    {
        $s = ['attempts' => 0];
        $e = TransferError::transient('busy');
        $waits = [];
        for ($i = 0; $i < count(Backoff::DELAYS); $i++) {
            $s = Backoff::fail($s, $e, 100);
            $waits[] = $s['wait_until'] - 100;
        }
        $this->assertSame(Backoff::DELAYS, $waits);
        try {
            Backoff::fail($s, $e, 100);
            $this->fail('should give up');
        } catch (TransferError $f) {
            $this->assertFalse($f->isTransient());
            $this->assertStringContainsString('gave up', $f->getMessage());
        }
        $this->expectException(TransferError::class);
        Backoff::fail(['attempts' => 0], new TransferError('denied'), 100); // not transient: at once
    }

    public function testUploadToStorageAndDownloadBackVerified(): void
    {
        $storage = new FolderStorage($this->base . '/cloud');
        $up = new Upload($storage, $this->clock(), $this->sleeper());
        $state = $this->drain($up, Upload::initialState($this->file, 'my site.zip'));
        $this->assertTrue($state['done']);
        $this->assertSame(100, Upload::progress($state)['percent']);
        $items = $storage->items();
        $this->assertCount(1, $items);
        $this->assertSame('my-site.zip', $items[0]['name']);
        $this->assertSame(Pieces::ofFile($this->file)['digest'], $items[0]['digest']);

        $dl = new Download($storage->open($items[0]['id']), $sink = $this->sink(), $this->base . '/piece.tmp', $this->clock(), $this->sleeper());
        $d = $this->drain($dl, Download::initialState());
        $this->assertTrue($d['done']);
        $this->assertTrue($d['verified']);
        $this->assertSame(md5_file($this->file), md5_file($d['result']['file']));

        $storage->delete($items[0]['id']);
        $this->assertSame([], $storage->items());
    }

    public function testUploadReusesAnExistingChecksumListAndRefusesWhenFull(): void
    {
        $listing = Pieces::ofFile($this->file);
        $this->assertSame('begin', Upload::initialState($this->file, 'a.zip', $listing)['phase']);
        $full = new class($this->base . '/cloud') extends FolderStorage {
            public function space()
            {
                return 1000;
            }
        };
        $up = new Upload($full, $this->clock(), $this->sleeper());
        $this->expectExceptionMessage('Not enough free space');
        $this->drain($up, Upload::initialState($this->file, 'a.zip', $listing));
    }

    public function testADamagedPieceIsFetchedAgain(): void
    {
        $listing = Pieces::ofFile($this->file);
        $damaged = 0;
        $source = new class($this->file, $listing, $damaged) extends FileSource {
            private $count;

            public function __construct($file, $pieces, &$count)
            {
                parent::__construct($file, $pieces);
                $this->count = &$count;
            }

            public function fetch($from, $to, $toFile)
            {
                parent::fetch($from, $to, $toFile);
                if ($from > 0 && $this->count++ === 0) {
                    $fh = fopen($toFile, 'r+b');
                    fwrite($fh, 'XX'); // one flipped corner, once
                    fclose($fh);
                }
            }
        };
        $dl = new Download($source, $this->sink(), $this->base . '/piece.tmp', $this->clock(), $this->sleeper());
        $d = $this->drain($dl, Download::initialState());
        $this->assertTrue($d['done']);
        $this->assertSame(md5_file($this->file), md5_file($d['result']['file']));
        $this->assertGreaterThan(1, $damaged);
    }

    public function testAPersistentFailureStopsWithItsMessage(): void
    {
        $source = new class implements Source {
            public function stat()
            {
                return ['name' => 'x.zip', 'size' => 10, 'pieces' => null];
            }

            public function fetch($from, $to, $toFile)
            {
                throw TransferError::transient('The server is busy.', 503);
            }
        };
        $dl = new Download($source, $this->sink(), $this->base . '/piece.tmp', $this->clock(), $this->sleeper());
        try {
            $this->drain($dl, Download::initialState(), 500);
            $this->fail('should stop');
        } catch (TransferError $e) {
            $this->assertStringContainsString('The server is busy.', $e->getMessage());
            $this->assertStringContainsString('gave up', $e->getMessage());
        }
    }

    public function testAChecksumListThatDoesNotMatchTheSizeIsRefused(): void
    {
        $listing = Pieces::ofFile($this->file);
        $source = new class($this->file, $listing) extends FileSource {
            public function stat()
            {
                $s = parent::stat();
                $s['size'] -= 1;

                return $s;
            }
        };
        $dl = new Download($source, $this->sink(), $this->base . '/piece.tmp', $this->clock(), $this->sleeper());
        $this->expectExceptionMessage('does not match its checksum list');
        $dl->run(Download::initialState(), 0);
    }
}

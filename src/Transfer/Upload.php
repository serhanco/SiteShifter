<?php

namespace SiteShifter\Transfer;

/**
 * Sends a local package to a Storage: first its piece hashes (so the far side
 * can be checked later), then the bytes in 8 MB pieces, resumable across
 * requests with retries on transient failures.
 */
class Upload
{
    /** @var Storage */
    private $storage;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $sleep;

    public function __construct(Storage $storage, $clock = null, $sleep = null)
    {
        $this->storage = $storage;
        $this->clock = $clock ?: 'microtime';
        $this->sleep = $sleep;
    }

    /**
     * @param array|null $pieces a Pieces listing already made for this file (skips hashing)
     */
    public static function initialState($file, $name, $pieces = null)
    {
        $pieces = $pieces !== null ? Pieces::parse($pieces) : null;
        if ($pieces !== null && is_file($file) && (int) filesize($file) !== $pieces['size']) {
            $pieces = null; // stale listing: hash again
        }

        return [
            'file' => (string) $file, 'name' => (string) $name,
            'phase' => $pieces === null ? 'hash' : 'begin',
            'hash' => $pieces === null ? Pieces::start($file) : null,
            'pieces' => $pieces, 'session' => null, 'offset' => 0,
            'attempts' => 0, 'wait_until' => 0, 'last_error' => null, 'done' => false, 'result' => null,
        ];
    }

    public function run(array $state, $deadline)
    {
        $first = true;
        while (!$state['done']) {
            $now = $this->now();
            if (!$first && $now >= $deadline) {
                break;
            }
            if (!Backoff::ready($state, $deadline, $now, $this->sleep)) {
                break;
            }
            $first = false;
            try {
                $state = $this->once($state, $deadline);
                $state = Backoff::ok($state);
            } catch (TransferError $e) {
                $state = Backoff::fail($state, $e, $this->now());
            }
        }

        return $state;
    }

    private function once(array $state, $deadline)
    {
        $size = $state['pieces'] !== null ? $state['pieces']['size'] : $state['hash']['size'];
        if ($state['phase'] !== 'hash' && (!is_file($state['file']) || (int) filesize($state['file']) !== $size)) {
            throw new TransferError('The package changed or was deleted during the upload. Start the upload again.');
        }
        switch ($state['phase']) {
            case 'hash':
                $state['hash'] = Pieces::run($state['hash'], $deadline);
                if ($state['hash']['done']) {
                    $state['pieces'] = Pieces::listing($state['hash']);
                    $state['hash'] = null;
                    $state['phase'] = 'begin';
                }
                break;
            case 'begin':
                $free = $this->storage->space();
                if ($free !== null && $free < $size) {
                    throw new TransferError('Not enough free space in ' . $this->storage->label() . ': the package needs ' . (int) ceil($size / 1048576) . ' MB, ' . (int) floor($free / 1048576) . ' MB is free.');
                }
                $state['session'] = $this->storage->beginUpload($state['name'], $size);
                $state['offset'] = 0;
                $state['phase'] = 'send';
                break;
            case 'send':
                if ($state['offset'] < $size) {
                    if (!empty($state['last_error'])) {
                        // The last piece failed half way: start from what the storage really holds.
                        $state['offset'] = max(0, min($size, (int) $this->storage->confirmed($state['session'])));
                        $state['last_error'] = null;
                        if ($state['offset'] >= $size) {
                            break;
                        }
                    }
                    $length = (int) min(Pieces::PIECE, $size - $state['offset']);
                    $r = $this->storage->uploadPiece($state['session'], $state['offset'], $state['file'], $length);
                    $state['session'] = $r['session'];
                    $state['offset'] = max(0, min($size, (int) $r['offset']));
                    break;
                }
                $state['phase'] = 'finish';
                break;
            case 'finish':
                $state['result'] = $this->storage->finishUpload($state['session'], $state['pieces']);
                $state['phase'] = 'done';
                $state['done'] = true;
                break;
        }

        return $state;
    }

    public static function progress(array $state)
    {
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => ''];
        }
        if ($state['phase'] === 'hash') {
            $count = max(1, Pieces::count($state['hash']['size']));

            // Hashing is quick next to sending: give it the first 5 percent.
            return ['percent' => (int) floor(5 * count($state['hash']['hashes']) / $count), 'phase' => 'checksum', 'detail' => ''];
        }
        $size = max(1, (int) $state['pieces']['size']);
        $detail = round($state['offset'] / 1048576) . ' / ' . round($size / 1048576) . ' MB';
        if (!empty($state['wait_until']) && $state['last_error']) {
            $detail .= ' · retry ' . (int) $state['attempts'];
        }

        return ['percent' => (int) min(99, 5 + floor(94 * $state['offset'] / $size)), 'phase' => 'upload', 'detail' => $detail];
    }

    private function now()
    {
        return (float) call_user_func($this->clock, true);
    }
}

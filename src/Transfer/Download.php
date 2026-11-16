<?php

namespace SiteShifter\Transfer;

/**
 * Copies a Source into a Sink in 8 MB pieces, resumable across requests.
 * When the far side publishes piece hashes, every piece is checked as it
 * arrives and fetched again if it is damaged; transient failures wait and
 * retry (Backoff) instead of failing the job.
 */
class Download
{
    /** @var Source */
    private $source;

    /** @var Sink */
    private $sink;

    /** @var string */
    private $tmp;

    /** @var callable */
    private $clock;

    /** @var callable|null */
    private $sleep;

    /**
     * @param string $tmp a scratch file path for the piece in flight
     */
    public function __construct(Source $source, Sink $sink, $tmp, $clock = null, $sleep = null)
    {
        $this->source = $source;
        $this->sink = $sink;
        $this->tmp = (string) $tmp;
        $this->clock = $clock ?: 'microtime';
        $this->sleep = $sleep;
    }

    public static function initialState()
    {
        return ['name' => null, 'size' => null, 'pieces' => null, 'id' => null, 'offset' => 0, 'attempts' => 0, 'wait_until' => 0, 'last_error' => null, 'verified' => false, 'done' => false, 'result' => null];
    }

    public function run(array $state, $deadline)
    {
        $state += self::initialState();
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
                $state = $this->once($state);
                $state = Backoff::ok($state);
            } catch (TransferError $e) {
                @unlink($this->tmp);
                $state = Backoff::fail($state, $e, $this->now());
            }
        }

        return $state;
    }

    private function once(array $state)
    {
        if ($state['size'] === null) {
            $stat = $this->source->stat();
            $size = (int) $stat['size'];
            if ($size < 1) {
                throw new TransferError('The package on the other side is empty.');
            }
            $pieces = isset($stat['pieces']) ? Pieces::parse($stat['pieces']) : null;
            if ($pieces !== null && $pieces['size'] !== $size) {
                throw new TransferError('The package on the other side does not match its checksum list. Create it again there.');
            }
            $state['name'] = (string) $stat['name'];
            $state['size'] = $size;
            $state['pieces'] = $pieces;
            $state['verified'] = $pieces !== null;

            return $state;
        }
        if ($state['id'] === null) {
            $state['id'] = $this->sink->begin($state['name'], $state['size']);

            return $state;
        }
        if ($state['offset'] < $state['size']) {
            $from = $state['offset'];
            $to = min($from + Pieces::PIECE, $state['size']) - 1;
            $this->source->fetch($from, $to, $this->tmp);
            clearstatcache(true, $this->tmp);
            if (!is_file($this->tmp) || filesize($this->tmp) !== $to - $from + 1) {
                throw TransferError::transient('A piece of the package arrived incomplete.');
            }
            if ($state['pieces'] !== null && $from % Pieces::PIECE === 0) {
                $i = intdiv($from, Pieces::PIECE);
                if (Pieces::hashRange($this->tmp, 0, $to - $from + 1) !== $state['pieces']['hashes'][$i]) {
                    throw TransferError::transient('A piece of the package arrived damaged (checksum mismatch); fetching it again.');
                }
            }
            $state['offset'] = (int) $this->sink->append($state['id'], $from, $this->tmp);
            @unlink($this->tmp);

            return $state;
        }
        $state['result'] = $this->sink->finish($state['id']);
        $state['done'] = true;

        return $state;
    }

    public static function progress(array $state)
    {
        $state += self::initialState();
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => ''];
        }
        $total = (int) $state['size'];
        $detail = $total > 0 ? round($state['offset'] / 1048576) . ' / ' . round($total / 1048576) . ' MB' : '';
        if (!empty($state['wait_until']) && $state['last_error']) {
            $detail .= ($detail === '' ? '' : ' · ') . 'retry ' . (int) $state['attempts'];
        }

        return ['percent' => $total > 0 ? (int) min(99, floor(100 * $state['offset'] / $total)) : 0, 'phase' => 'fetch', 'detail' => $detail];
    }

    private function now()
    {
        return (float) call_user_func($this->clock, true);
    }
}

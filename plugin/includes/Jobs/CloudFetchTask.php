<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Transfer\Download;
use SiteShifter\Transfer\LazySource;
use SiteShifter\Transfer\Storage;

defined('ABSPATH') || exit;

/** Brings a package from cloud storage into the incoming folder, checked piece by piece. */
class CloudFetchTask implements Task
{
    /** @var string */
    private $dir;

    /** @var Incoming */
    private $incoming;

    /** @var callable function (): Storage */
    private $storage;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $sleep;

    public function __construct($dir, Incoming $incoming, $storage, $clock = null, $sleep = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->incoming = $incoming;
        $this->storage = $storage;
        $this->clock = $clock;
        $this->sleep = $sleep;
    }

    public function initialState(array $params)
    {
        $item = (string) ($params['item'] ?? '');
        if (!preg_match('/^[\w.-]{1,200}$/', $item)) {
            throw new \InvalidArgumentException('Choose a package from the list.');
        }

        return ['item' => $item, 'dl' => Download::initialState(), 'done' => false, 'result' => null];
    }

    public function run(array $state, $deadline)
    {
        $storage = $this->storage;
        $item = $state['item'];
        $source = new LazySource(static function () use ($storage, $item) {
            /** @var Storage $s */
            $s = call_user_func($storage);

            return $s->open($item);
        });
        $dl = new Download($source, new IncomingSink($this->incoming), $this->dir . '/piece.tmp', $this->clock, $this->sleep);
        $state['dl'] = $dl->run($state['dl'], $deadline);
        if ($state['dl']['done']) {
            $state['result'] = $state['dl']['result'];
            $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        $p = Download::progress($state['dl']);
        if ($state['done']) {
            $p['result'] = $state['result'];
        }

        return $p;
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }
}

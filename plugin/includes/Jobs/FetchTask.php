<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Transfer\Download;
use SiteShifter\Transfer\LinkSource;

defined('ABSPATH') || exit;

/**
 * Fetches a package from another SiteShifter site (the link made on its Export screen)
 * into the incoming folder with the core Download: 8 MB pieces, each checked against
 * the other site's checksum list when it has one, transient failures retried with
 * growing waits. The result is a normal uploaded package.
 */
class FetchTask implements Task
{
    /** @var string */
    private $dir;

    /** @var Incoming */
    private $incoming;

    /** @var callable function (string $url, array $headers, string $toFile): array{code:int,headers:array}  headers lower-cased */
    private $http;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $sleep;

    public function __construct($dir, Incoming $incoming, $http, $clock = null, $sleep = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->incoming = $incoming;
        $this->http = $http;
        $this->clock = $clock;
        $this->sleep = $sleep;
    }

    public static function validate($url)
    {
        return LinkSource::validate($url);
    }

    public function initialState(array $params)
    {
        return ['url' => self::validate($params['url'] ?? ''), 'dl' => Download::initialState(), 'done' => false, 'result' => null];
    }

    public function run(array $state, $deadline)
    {
        $state = self::upgrade($state);
        $source = new LinkSource($state['url'], $this->http, $this->dir . '/probe.tmp');
        $download = new Download($source, new IncomingSink($this->incoming), $this->dir . '/piece.tmp', $this->clock, $this->sleep);
        $state['dl'] = $download->run($state['dl'], $deadline);
        if ($state['dl']['done']) {
            $state['result'] = $state['dl']['result'];
            $state['done'] = true;
        }

        return $state;
    }

    public function progress(array $state)
    {
        $state = self::upgrade($state);
        if ($state['done']) {
            return ['percent' => 100, 'phase' => 'done', 'detail' => '', 'result' => $state['result']];
        }

        return Download::progress($state['dl']);
    }

    public function checkpointFiles(array $state)
    {
        return [];
    }

    /** A fetch started by 0.2.x kept its fields at the top level; carry them on. */
    private static function upgrade(array $state)
    {
        if (isset($state['dl'])) {
            return $state;
        }
        $dl = Download::initialState();
        if (isset($state['total'])) {
            $dl['size'] = (int) $state['total'];
            $dl['name'] = 'package.zip';
            $dl['id'] = $state['id'] ?? null;
            $dl['offset'] = (int) ($state['offset'] ?? 0);
        }

        return ['url' => $state['url'], 'dl' => $dl, 'done' => !empty($state['done']), 'result' => $state['result'] ?? null];
    }
}

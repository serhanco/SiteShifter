<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Jobs;

use SiteShifter\Transfer\Sink;

defined('ABSPATH') || exit;

/** Lets a core Download land in the incoming folder, like an uploaded package. */
class IncomingSink implements Sink
{
    /** @var Incoming */
    private $incoming;

    public function __construct(Incoming $incoming)
    {
        $this->incoming = $incoming;
    }

    public function begin($name, $size)
    {
        return $this->incoming->begin($name, $size);
    }

    public function append($id, $offset, $pieceFile)
    {
        return (int) $this->incoming->append($id, $offset, $pieceFile)['size'];
    }

    public function finish($id)
    {
        return $this->incoming->finish($id);
    }
}

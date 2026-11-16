<?php

namespace SiteShifter\Transfer;

/**
 * A place packages are kept away from both sites: a Google Drive folder, later
 * S3-compatible buckets, Dropbox, OneDrive or an SFTP folder. Every call does a
 * small, bounded amount of work so it fits in one request on shared hosting.
 * All state passed between calls is plain, JSON-safe arrays.
 */
interface Storage
{
    /** @return string short name for screens and logs, e.g. "Google Drive" */
    public function label();

    /**
     * @return array<int,array{id:string,name:string,size:int,created:int,digest:string|null}> packages, newest first
     * @throws TransferError
     */
    public function items();

    /** @throws TransferError */
    public function open($id);

    /** @return int|null free bytes, null when the storage does not say */
    public function space();

    /**
     * @return array upload session
     * @throws TransferError
     */
    public function beginUpload($name, $size);

    /**
     * Sends $length bytes of $file starting at $offset.
     *
     * @return array{session:array,offset:int} offset = bytes the storage confirms it holds
     * @throws TransferError
     */
    public function uploadPiece(array $session, $offset, $file, $length);

    /**
     * Asks the storage how many bytes of an interrupted upload it really holds,
     * so the next piece starts at the right place after a failure.
     *
     * @return int
     * @throws TransferError
     */
    public function confirmed(array $session);

    /**
     * Completes the upload and stores the Pieces listing beside it.
     *
     * @return array{id:string,name:string,size:int,created:int,digest:string|null}
     * @throws TransferError
     */
    public function finishUpload(array $session, array $pieces);

    /** @throws TransferError */
    public function delete($id);
}

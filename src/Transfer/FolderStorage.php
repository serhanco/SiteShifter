<?php

namespace SiteShifter\Transfer;

/**
 * Packages in a local or mounted folder, each with its checksum list beside it
 * (name.zip + name.zip.pieces.json). Used by the tests as the reference
 * Storage, and usable for a backup disk or a synced folder.
 */
class FolderStorage implements Storage
{
    const SIDECAR = '.pieces.json';

    /** @var string */
    private $dir;

    public function __construct($dir)
    {
        $this->dir = rtrim((string) $dir, '/');
    }

    public function label()
    {
        return 'Folder';
    }

    public function items()
    {
        $out = [];
        foreach (glob($this->dir . '/*.zip') ?: [] as $file) {
            $out[] = $this->item(basename($file));
        }
        usort($out, static function ($a, $b) {
            return $b['created'] <=> $a['created'] ?: strcmp($a['name'], $b['name']);
        });

        return $out;
    }

    public function open($id)
    {
        $file = $this->path($id);
        if (!is_file($file)) {
            throw new TransferError('That package is no longer in the folder.');
        }
        $sidecar = is_file($file . self::SIDECAR) ? Pieces::parse((string) file_get_contents($file . self::SIDECAR)) : null;

        return new FileSource($file, $sidecar);
    }

    public function space()
    {
        $free = function_exists('disk_free_space') ? @disk_free_space($this->dir) : false;

        return $free === false ? null : (int) $free;
    }

    public function beginUpload($name, $size)
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true)) {
            throw new TransferError('Cannot create the folder ' . $this->dir . '.');
        }
        $name = self::cleanName($name);
        $part = $this->dir . '/' . $name . '.part';
        if (@file_put_contents($part, '') === false) {
            throw new TransferError('Cannot write to the folder ' . $this->dir . '.');
        }

        return ['name' => $name, 'size' => (int) $size];
    }

    public function uploadPiece(array $session, $offset, $file, $length)
    {
        $part = $this->dir . '/' . $session['name'] . '.part';
        clearstatcache(true, $part);
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($have !== (int) $offset) {
            return ['session' => $session, 'offset' => $have]; // a repeated piece: carry on from what is there
        }
        $in = fopen($file, 'rb');
        $out = fopen($part, 'ab');
        if (!$in || !$out) {
            throw new TransferError('Cannot copy the package into the folder.');
        }
        fseek($in, $offset);
        $copied = stream_copy_to_stream($in, $out, $length);
        fclose($in);
        fclose($out);
        if ($copied !== (int) $length) {
            throw TransferError::transient('The copy into the folder was cut short.');
        }
        clearstatcache(true, $part);

        return ['session' => $session, 'offset' => (int) filesize($part)];
    }

    public function confirmed(array $session)
    {
        $part = $this->dir . '/' . $session['name'] . '.part';
        clearstatcache(true, $part);

        return is_file($part) ? (int) filesize($part) : 0;
    }

    public function finishUpload(array $session, array $pieces)
    {
        $part = $this->dir . '/' . $session['name'] . '.part';
        $file = $this->dir . '/' . $session['name'];
        if ((int) filesize($part) !== (int) $session['size'] || Pieces::ofFile($part)['digest'] !== $pieces['digest']) {
            @unlink($part);
            throw new TransferError('The copy in the folder does not match the package. Start the upload again.');
        }
        file_put_contents($file . self::SIDECAR, json_encode($pieces));
        rename($part, $file);

        return $this->item($session['name']);
    }

    public function delete($id)
    {
        $file = $this->path($id);
        @unlink($file . self::SIDECAR);
        if (is_file($file) && !@unlink($file)) {
            throw new TransferError('Cannot delete the package from the folder.');
        }
    }

    private function item($name)
    {
        $file = $this->dir . '/' . $name;
        $pieces = is_file($file . self::SIDECAR) ? Pieces::parse((string) file_get_contents($file . self::SIDECAR)) : null;

        return ['id' => $name, 'name' => $name, 'size' => (int) filesize($file), 'created' => (int) filemtime($file), 'digest' => $pieces ? $pieces['digest'] : null];
    }

    private function path($id)
    {
        $id = (string) $id;
        if ($id === '' || $id !== basename($id) || substr($id, -4) !== '.zip') {
            throw new TransferError('Unknown package.');
        }

        return $this->dir . '/' . $id;
    }

    public static function cleanName($name)
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string) $name));
        $name = trim((string) $name, '.-');
        if ($name === '') {
            $name = 'package';
        }

        return substr($name, -4) === '.zip' ? $name : $name . '.zip';
    }
}

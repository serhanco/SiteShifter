<?php

namespace SiteShifter\Transfer;

/** HttpClient on curl, for the command line. */
class CurlClient implements HttpClient
{
    /** @var int */
    private $timeout;

    public function __construct($timeout = 120)
    {
        $this->timeout = (int) $timeout;
    }

    public function request($method, $url, array $headers = [], $body = null, $toFile = null)
    {
        if (!function_exists('curl_init')) {
            throw new TransferError('The PHP curl extension is needed for cloud transfers.');
        }
        if (is_array($body)) {
            $body = self::slice($body);
        }
        $ch = curl_init($url);
        $out = $toFile !== null ? fopen($toFile, 'wb') : null;
        $got = [];
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$got) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $got[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
        }
        if ($out) {
            curl_setopt($ch, CURLOPT_FILE, $out);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $res = curl_exec($ch);
        $code = $res === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($out) {
            fclose($out);
        }

        return ['code' => $code, 'headers' => $got, 'body' => $out || $res === false ? '' : (string) $res];
    }

    /** Reads the part of a file a request sends. */
    public static function slice(array $spec)
    {
        $data = @file_get_contents($spec['file'], false, null, (int) $spec['offset'], (int) $spec['length']);
        if ($data === false || strlen($data) !== (int) $spec['length']) {
            throw new TransferError('Cannot read the package file.');
        }

        return $data;
    }
}

<?php

namespace SiteShifter\Preflight;

/**
 * Everything the checks read from the server, behind one class so the
 * checks can be tested with fixed values.
 */
class Probe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    /** @return string|false */
    public function ini(string $key)
    {
        return ini_get($key);
    }

    public function canSetIni(string $key, string $value): bool
    {
        $old = ini_get($key);
        $ok = @ini_set($key, $value) !== false;
        if ($ok && $old !== false) {
            @ini_set($key, $old);
        }

        return $ok;
    }

    /** @return float|false */
    public function diskFree(string $dir)
    {
        return function_exists('disk_free_space') ? @disk_free_space($dir) : false;
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function read(string $path): string
    {
        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    public function serverSoftware(): string
    {
        return (string) ($_SERVER['SERVER_SOFTWARE'] ?? (PHP_SAPI === 'cli' ? 'cli' : ''));
    }

    public function serverAddress(): string
    {
        return (string) ($_SERVER['SERVER_ADDR'] ?? '');
    }

    /** @return string[] */
    public function resolve(string $host): array
    {
        $ips = @gethostbynamel($host);

        return $ips === false ? [] : $ips;
    }

    /**
     * Tries a TLS handshake with certificate verification.
     * Returns null on success or the error text.
     */
    public function tlsError(string $host, int $port = 443, int $timeout = 5): ?string
    {
        if (!function_exists('stream_socket_client') || !extension_loaded('openssl')) {
            return 'openssl is not available to test the certificate';
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            return $errstr !== '' ? $errstr : 'TLS connection failed';
        }
        fclose($s);

        return null;
    }
}

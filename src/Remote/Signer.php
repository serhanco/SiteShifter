<?php

namespace SiteShifter\Remote;

/**
 * Signs and checks clone requests: HMAC-SHA256 over the operation, the time,
 * a one-time nonce and the body's hash. A request is accepted only within
 * five minutes of its time and only once (the caller remembers nonces).
 */
class Signer
{
    const WINDOW = 300;

    /** @return array<string,string> headers to send */
    public static function sign($id, $secret, $op, $body, $now = null)
    {
        $time = (string) ($now === null ? time() : (int) $now);
        $nonce = bin2hex(random_bytes(12));

        return [
            'X-SiteShifter-Key' => $id,
            'X-SiteShifter-Time' => $time,
            'X-SiteShifter-Nonce' => $nonce,
            'X-SiteShifter-Sign' => self::mac($secret, $op, $time, $nonce, $body),
        ];
    }

    /**
     * @param array $h request headers, lower-case names without the X- prefix handling: x-siteshifter-*
     * @param callable $secretFor function (string $keyId): ?string, null when unknown, revoked or expired
     * @param callable $seen function (string $nonce, int $until): bool, true when the nonce was used before (and records it)
     * @return string|null the key id when valid, null otherwise
     */
    public static function verify(array $h, $op, $body, $secretFor, $seen, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $id = (string) ($h['x-siteshifter-key'] ?? '');
        $time = (string) ($h['x-siteshifter-time'] ?? '');
        $nonce = (string) ($h['x-siteshifter-nonce'] ?? '');
        $sig = (string) ($h['x-siteshifter-sign'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $id) || !ctype_digit($time) || !preg_match('/^[a-f0-9]{24}$/', $nonce) || $sig === '') {
            return null;
        }
        if (abs($now - (int) $time) > self::WINDOW) {
            return null;
        }
        $secret = call_user_func($secretFor, $id);
        if (!is_string($secret) || $secret === '' || !hash_equals(self::mac($secret, $op, $time, $nonce, $body), $sig)) {
            return null;
        }
        if (call_user_func($seen, $nonce, (int) $time + self::WINDOW)) {
            return null; // a replayed request
        }

        return $id;
    }

    private static function mac($secret, $op, $time, $nonce, $body)
    {
        return hash_hmac('sha256', implode("\n", ['siteshifter-clone-v1', $op, $time, $nonce, hash('sha256', (string) $body)]), $secret);
    }
}

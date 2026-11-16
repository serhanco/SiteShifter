<?php

namespace SiteShifter\Remote;

/**
 * The clone key a source site hands out: its address, a key id and a secret,
 * in one pasteable string ("ssk1." + base64url JSON). The target signs every
 * request with the secret; the secret itself never travels again.
 */
class CloneKey
{
    const PREFIX = 'ssk1.';

    /** @return array{id:string,secret:string,url:string,expires:int} */
    public static function make($url, $ttl, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        return [
            'id' => bin2hex(random_bytes(8)),
            'secret' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'url' => rtrim((string) $url, '/'),
            'expires' => $now + (int) $ttl,
        ];
    }

    public static function encode(array $key)
    {
        $json = json_encode(['u' => $key['url'], 'i' => $key['id'], 's' => $key['secret'], 'e' => (int) $key['expires']]);

        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{id:string,secret:string,url:string,expires:int}
     * @throws \InvalidArgumentException
     */
    public static function decode($text)
    {
        $text = trim((string) $text);
        if (strpos($text, self::PREFIX) !== 0) {
            throw new \InvalidArgumentException('That is not a SiteShifter clone key. Create one on the source site (Clone tab) and paste it whole.');
        }
        $j = json_decode((string) base64_decode(strtr(substr($text, strlen(self::PREFIX)), '-_', '+/')), true);
        if (!is_array($j) || !preg_match('/^[a-f0-9]{16}$/', (string) ($j['i'] ?? '')) || strlen((string) ($j['s'] ?? '')) < 40
            || !preg_match('#^https?://[^\s/]+#i', (string) ($j['u'] ?? ''))) {
            throw new \InvalidArgumentException('The clone key is damaged. Copy it again from the source site.');
        }

        return ['id' => $j['i'], 'secret' => $j['s'], 'url' => rtrim($j['u'], '/'), 'expires' => (int) ($j['e'] ?? 0)];
    }
}

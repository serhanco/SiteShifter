<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Remote;

use SiteShifter\Remote\CloneKey;
use SiteShifter\Transfer\Vault;

defined('ABSPATH') || exit;

/**
 * Clone keys, both sides, kept in the encrypted vault (never in the database,
 * never in a package).
 *
 * Source side: the keys this site handed out. Each lets one other site read
 * this site (build a package, fetch it) until it expires or is revoked.
 * Target side: the one key pasted here, to clone from that source.
 */
class Keys
{
    const TTL = 86400;
    const ISSUED = 'clone_keys';
    const REMOTE = 'clone_remote';
    /** Expired or revoked keys stay listed this long, then they are forgotten. */
    const KEEP = 604800;

    /** @var Vault */
    private $vault;

    /** @var callable */
    private $clock;

    public function __construct(Vault $vault, $clock = null)
    {
        $this->vault = $vault;
        $this->clock = $clock ?: 'time';
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }

    /**
     * A new key for this site.
     *
     * @return array{key:string,id:string,expires:int,insecure:bool}
     */
    public function create($homeUrl, $ttl = self::TTL)
    {
        $ttl = max(600, min(7 * 86400, (int) $ttl));
        $key = CloneKey::make($homeUrl, $ttl, $this->now());
        $all = $this->issued();
        $all[] = ['id' => $key['id'], 'secret' => $key['secret'], 'created' => $this->now(), 'expires' => $key['expires'], 'revoked' => false, 'used' => 0];
        $this->vault->set(self::ISSUED, $all);

        return ['key' => CloneKey::encode($key), 'id' => $key['id'], 'expires' => $key['expires'], 'insecure' => stripos($key['url'], 'https://') !== 0];
    }

    /** @return array[] the issued keys without their secrets, newest first */
    public function all()
    {
        $out = [];
        foreach (array_reverse($this->issued()) as $k) {
            $out[] = [
                'id' => $k['id'],
                'created' => (int) $k['created'],
                'expires' => (int) $k['expires'],
                'revoked' => !empty($k['revoked']),
                'active' => $this->active($k),
                'used' => (int) ($k['used'] ?? 0),
            ];
        }

        return $out;
    }

    public function revoke($id)
    {
        $all = $this->issued();
        $found = false;
        foreach ($all as &$k) {
            if ($k['id'] === $id) {
                $k['revoked'] = true;
                $found = true;
            }
        }
        unset($k);
        if (!$found) {
            throw new \InvalidArgumentException('That clone key was not found.');
        }
        $this->vault->set(self::ISSUED, $all);
    }

    /** @return string|null the secret of an active key; null when unknown, expired or revoked */
    public function secretFor($id)
    {
        foreach ($this->issued() as $k) {
            if ($k['id'] === $id) {
                return $this->active($k) ? (string) $k['secret'] : null;
            }
        }

        return null;
    }

    /** Remembers when a key was last used (shown on the screen), at most once a minute. */
    public function touch($id)
    {
        $all = $this->issued();
        foreach ($all as &$k) {
            if ($k['id'] === $id && $this->now() - (int) ($k['used'] ?? 0) >= 60) {
                $k['used'] = $this->now();
                unset($k);
                $this->vault->set(self::ISSUED, $all);

                return;
            }
        }
    }

    /** Target side: saves a pasted key. @return array the decoded key */
    public function setRemote($text)
    {
        $key = CloneKey::decode($text);
        if ($key['expires'] && $key['expires'] < $this->now()) {
            throw new \InvalidArgumentException('This clone key has expired. Create a new one on the source site.');
        }
        $this->vault->set(self::REMOTE, $key);

        return $key;
    }

    /** @return array|null the pasted key, secret included (server side only) */
    public function remote()
    {
        $key = $this->vault->get(self::REMOTE);

        return is_array($key) && !empty($key['id']) ? $key : null;
    }

    /** @return array|null what the screen may show about the pasted key */
    public function remoteView()
    {
        $key = $this->remote();

        return $key ? ['url' => $key['url'], 'expires' => (int) $key['expires'], 'expired' => $key['expires'] && $key['expires'] < $this->now(), 'insecure' => stripos($key['url'], 'https://') !== 0] : null;
    }

    public function forgetRemote()
    {
        $this->vault->delete(self::REMOTE);
    }

    private function active(array $k)
    {
        return empty($k['revoked']) && (int) $k['expires'] >= $this->now();
    }

    private function issued()
    {
        $all = $this->vault->get(self::ISSUED);
        $all = is_array($all) ? $all : [];
        $now = $this->now();

        return array_values(array_filter($all, static function ($k) use ($now) {
            return is_array($k) && isset($k['id'], $k['secret']) && (int) $k['expires'] + self::KEEP >= $now;
        }));
    }
}

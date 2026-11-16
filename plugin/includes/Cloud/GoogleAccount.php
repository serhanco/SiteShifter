<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Cloud;

use SiteShifter\Transfer\Google\DeviceAuth;
use SiteShifter\Transfer\Google\Drive;
use SiteShifter\Transfer\HttpClient;
use SiteShifter\Transfer\Vault;

defined('ABSPATH') || exit;

/**
 * This site's Google Drive connection. Each person connects their own Google
 * account; the client id only names the app. The connection lives in the
 * Vault (encrypted, outside the database), so an import never loses it.
 */
class GoogleAccount
{
    const KEY = 'google';
    const PENDING = 'google_pending';
    const CLIENT = 'google_client';

    /** @var Vault */
    private $vault;

    /** @var HttpClient */
    private $http;

    /** @var array{id:string,secret:string} the client SiteShifter ships with */
    private $builtIn;

    /** @var callable */
    private $clock;

    public function __construct(Vault $vault, HttpClient $http, array $builtIn, $clock = null)
    {
        $this->vault = $vault;
        $this->http = $http;
        $this->builtIn = $builtIn + ['id' => '', 'secret' => ''];
        $this->clock = $clock ?: 'time';
    }

    /** @return array{id:string,secret:string,own:bool} */
    public function client()
    {
        $own = $this->vault->get(self::CLIENT);
        if (is_array($own) && !empty($own['id'])) {
            return ['id' => (string) $own['id'], 'secret' => (string) ($own['secret'] ?? ''), 'own' => true];
        }

        return ['id' => (string) $this->builtIn['id'], 'secret' => (string) $this->builtIn['secret'], 'own' => false];
    }

    /** Sets (or with an empty id, clears) a person's own OAuth client; the connection must be made again. */
    public function setClient($id, $secret)
    {
        $id = trim((string) $id);
        if ($id !== '' && !preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $id)) {
            throw new \InvalidArgumentException('That is not a Google OAuth client id (it ends with .apps.googleusercontent.com).');
        }
        $this->disconnect();
        if ($id === '') {
            $this->vault->delete(self::CLIENT);
        } else {
            $this->vault->set(self::CLIENT, ['id' => $id, 'secret' => trim((string) $secret)]);
        }
    }

    /** @return array what the screen shows */
    public function status()
    {
        $c = $this->client();
        $t = $this->vault->get(self::KEY);
        $p = $this->vault->get(self::PENDING);
        $now = (int) call_user_func($this->clock);

        return [
            'configured' => $c['id'] !== '',
            'own_client' => $c['own'],
            'connected' => is_array($t) && !empty($t['refresh_token']),
            'account' => is_array($t) ? (string) ($t['email'] ?? '') : '',
            'pending' => is_array($p) && (int) $p['expires_at'] > $now ? self::publicPending($p) : null,
        ];
    }

    /** Starts a sign-in: returns the code to enter at google.com/device. */
    public function connect()
    {
        $p = $this->auth()->start((int) call_user_func($this->clock));
        $p['next_poll'] = 0;
        $this->vault->set(self::PENDING, $p);

        return self::publicPending($p);
    }

    /**
     * Asks Google once (never faster than it allows) whether the person approved.
     *
     * @return array{status:string,account?:string}
     */
    public function poll()
    {
        $p = $this->vault->get(self::PENDING);
        if (!is_array($p)) {
            return ['status' => 'none'];
        }
        $now = (int) call_user_func($this->clock);
        if ($now < (int) $p['next_poll']) {
            return ['status' => 'pending'];
        }
        $r = $this->auth()->poll($p, $now);
        switch ($r['status']) {
            case 'approved':
                $this->vault->set(self::KEY, $r['tokens']);
                $this->vault->delete(self::PENDING);

                return ['status' => 'approved', 'account' => (string) ($r['tokens']['email'] ?? '')];
            case 'slow_down':
                $p['interval'] = $r['interval'];
                // fall through
            case 'pending':
                $p['next_poll'] = $now + (int) $p['interval'];
                $this->vault->set(self::PENDING, $p);

                return ['status' => 'pending'];
        }
        $this->vault->delete(self::PENDING);

        return ['status' => $r['status']]; // denied, expired
    }

    public function disconnect()
    {
        $t = $this->vault->get(self::KEY);
        if (is_array($t) && !empty($t['refresh_token']) && $this->client()['id'] !== '') {
            $this->auth()->revoke($t);
        }
        $this->vault->delete(self::KEY);
        $this->vault->delete(self::PENDING);
    }

    /** @return Drive */
    public function drive()
    {
        $t = $this->vault->get(self::KEY);
        if (!is_array($t) || empty($t['refresh_token'])) {
            throw new \InvalidArgumentException('Google Drive is not connected on this site. Connect it first.');
        }
        $vault = $this->vault;

        return new Drive($this->http, $this->auth(), $t, static function (array $tokens) use ($vault) {
            $vault->set(self::KEY, $tokens);
        }, $t['folder'] ?? null, $this->clock);
    }

    private function auth()
    {
        $c = $this->client();

        return new DeviceAuth($this->http, $c['id'], $c['secret']);
    }

    private static function publicPending(array $p)
    {
        return ['user_code' => $p['user_code'], 'verification_url' => $p['verification_url'], 'expires_at' => (int) $p['expires_at'], 'interval' => (int) $p['interval']];
    }
}

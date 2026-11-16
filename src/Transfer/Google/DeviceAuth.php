<?php

namespace SiteShifter\Transfer\Google;

use SiteShifter\Transfer\HttpClient;
use SiteShifter\Transfer\TransferError;

/**
 * Connects a Google account with the device code flow: the site shows a short
 * code, the person enters it at google.com/device in any browser, and the site
 * polls until Google says yes. Works whatever the site's address is (no
 * redirect address to register) and asks only for drive.file: the plugin sees
 * only the files it created, never the rest of the Drive.
 *
 * The client id belongs to the app, not to the person: every person signs in
 * with their own Google account and the files go to their own Drive.
 */
class DeviceAuth
{
    const CODE_URL = 'https://oauth2.googleapis.com/device/code';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    const SCOPE = 'https://www.googleapis.com/auth/drive.file email';

    /** @var HttpClient */
    private $http;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    public function __construct(HttpClient $http, $clientId, $clientSecret)
    {
        if (trim((string) $clientId) === '') {
            throw new TransferError('Google Drive is not set up in this copy of SiteShifter (no client id).');
        }
        $this->http = $http;
        $this->clientId = (string) $clientId;
        $this->clientSecret = (string) $clientSecret;
    }

    /**
     * @return array{device_code:string,user_code:string,verification_url:string,expires_at:int,interval:int}
     */
    public function start($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $r = $this->post(self::CODE_URL, ['client_id' => $this->clientId, 'scope' => self::SCOPE]);
        $j = json_decode($r['body'], true);
        if ($r['code'] !== 200 || !is_array($j) || empty($j['device_code']) || empty($j['user_code'])) {
            throw new TransferError(self::explain($r, 'Google did not give a sign-in code'), \SiteShifter\Transfer\TransferError::transientStatus($r['code']), $r['code']);
        }

        return [
            'device_code' => (string) $j['device_code'],
            'user_code' => (string) $j['user_code'],
            'verification_url' => (string) ($j['verification_url'] ?? ($j['verification_uri'] ?? 'https://www.google.com/device')),
            'expires_at' => $now + (int) ($j['expires_in'] ?? 1800),
            'interval' => max(5, (int) ($j['interval'] ?? 5)),
        ];
    }

    /**
     * Asks once whether the person has approved.
     *
     * @return array{status:string,tokens?:array,interval?:int} status: pending, slow_down, approved, denied, expired
     */
    public function poll(array $pending, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if ($now >= (int) $pending['expires_at']) {
            return ['status' => 'expired'];
        }
        $r = $this->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'device_code' => $pending['device_code'],
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ]);
        $j = json_decode($r['body'], true);
        $error = is_array($j) ? (string) ($j['error'] ?? '') : '';
        if ($r['code'] === 200 && is_array($j) && !empty($j['refresh_token'])) {
            return ['status' => 'approved', 'tokens' => self::tokens($j, $now)];
        }
        switch ($error) {
            case 'authorization_pending':
                return ['status' => 'pending'];
            case 'slow_down':
                return ['status' => 'slow_down', 'interval' => (int) $pending['interval'] + 5];
            case 'access_denied':
                return ['status' => 'denied'];
            case 'expired_token':
                return ['status' => 'expired'];
        }
        throw new TransferError(self::explain($r, 'Google refused the sign-in'), TransferError::transientStatus($r['code']), $r['code']);
    }

    /**
     * @return array tokens with a fresh access token (the refresh token is kept)
     */
    public function refresh(array $tokens, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $r = $this->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        $j = json_decode($r['body'], true);
        if ($r['code'] === 200 && is_array($j) && !empty($j['access_token'])) {
            return self::tokens($j + ['refresh_token' => $tokens['refresh_token']], $now) + ['email' => $tokens['email'] ?? ''];
        }
        if (is_array($j) && ($j['error'] ?? '') === 'invalid_grant') {
            throw new TransferError('The Google Drive connection has expired or was removed in the Google account. Connect Google Drive again.', false, 401);
        }
        throw new TransferError(self::explain($r, 'Google did not renew the connection'), TransferError::transientStatus($r['code']), $r['code']);
    }

    /** Best effort: tells Google to forget the connection. */
    public function revoke(array $tokens)
    {
        try {
            $this->post(self::REVOKE_URL, ['token' => $tokens['refresh_token']]);
        } catch (\Throwable $e) {
            // Disconnecting locally is what matters.
        }
    }

    private static function tokens(array $j, $now)
    {
        $t = [
            'access_token' => (string) $j['access_token'],
            'refresh_token' => (string) $j['refresh_token'],
            'expires_at' => $now + (int) ($j['expires_in'] ?? 3600) - 60,
        ];
        if (!empty($j['id_token'])) {
            $t['email'] = self::email($j['id_token']);
        }

        return $t;
    }

    /** The account's address from the id token (it came straight from Google over TLS, so it is not re-verified). */
    public static function email($idToken)
    {
        $parts = explode('.', (string) $idToken);
        if (count($parts) < 2) {
            return '';
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) ? (string) ($payload['email'] ?? '') : '';
    }

    private function post($url, array $fields)
    {
        return $this->http->request('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }

    private static function explain(array $r, $what)
    {
        if ($r['code'] === 0) {
            return $what . ': this server could not reach Google. Check that it may make outgoing requests.';
        }
        $j = json_decode($r['body'], true);
        $why = is_array($j) ? trim((string) ($j['error_description'] ?? ($j['error'] ?? ''))) : '';

        return $what . ' (' . $r['code'] . ($why !== '' ? ': ' . $why : '') . ').';
    }
}

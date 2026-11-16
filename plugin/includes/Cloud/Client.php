<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Cloud;

defined('ABSPATH') || exit;

/**
 * Which Google OAuth client this copy uses. Order: constants in wp-config.php
 * (SITESHIFTER_GOOGLE_CLIENT_ID / _SECRET), then the client the release build
 * put in includes/Cloud/client.php. A person can also enter their own client
 * on the settings screen (kept in the Vault, see GoogleAccount).
 */
class Client
{
    /** @return array{id:string,secret:string} */
    public static function builtIn()
    {
        if (defined('SITESHIFTER_GOOGLE_CLIENT_ID') && SITESHIFTER_GOOGLE_CLIENT_ID !== '') {
            return ['id' => (string) SITESHIFTER_GOOGLE_CLIENT_ID, 'secret' => defined('SITESHIFTER_GOOGLE_CLIENT_SECRET') ? (string) SITESHIFTER_GOOGLE_CLIENT_SECRET : ''];
        }
        $file = __DIR__ . '/client.php';
        $c = is_readable($file) ? include $file : null;

        return is_array($c) ? ['id' => (string) ($c['id'] ?? ''), 'secret' => (string) ($c['secret'] ?? '')] : ['id' => '', 'secret' => ''];
    }
}

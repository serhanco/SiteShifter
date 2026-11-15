<?php

namespace SiteShifter\Preflight;

/**
 * Plugins and themes that are known to bind something to the site's
 * domain (licence activation, account connection, or a "site was copied"
 * safety mode). After a domain change the owner has to act on each one.
 *
 * Keys are plugin basenames (folder/file.php) or "theme:<folder>".
 * Contributions welcome: keep each note to one actionable sentence.
 */
class KnownPlugins
{
    /** @return array<string,array{name:string,action:string}> */
    public static function all(): array
    {
        return [
            'elementor-pro/elementor-pro.php' => [
                'name' => 'Elementor Pro',
                'action' => 'Before the move, disconnect the licence in Elementor → License on the old domain; afterwards connect it again on the new domain, then run Elementor → Tools → Regenerate CSS & Data.',
            ],
            'wp-rocket/wp-rocket.php' => [
                'name' => 'WP Rocket',
                'action' => 'The licence is validated per domain: open Settings → WP Rocket on the new site to re-validate, and clear the cache (the old domain\'s file in wp-content/wp-rocket-config/ is replaced automatically).',
            ],
            'advanced-custom-fields-pro/acf.php' => [
                'name' => 'ACF Pro',
                'action' => 'Deactivate the licence in ACF → Updates on the old domain (or in your ACF account) and activate it on the new domain.',
            ],
            'sitepress-multilingual-cms/sitepress.php' => [
                'name' => 'WPML',
                'action' => 'WPML will show a "site moved or copied" notice: choose "moved", then register the new domain with a site key from your wpml.org account. Check per-language domains in WPML → Languages.',
            ],
            'gravityforms/gravityforms.php' => [
                'name' => 'Gravity Forms',
                'action' => 'Re-enter or re-validate the licence key in Forms → Settings on the new domain.',
            ],
            'seo-by-rank-math-pro/rank-math-pro.php' => [
                'name' => 'Rank Math Pro',
                'action' => 'Reconnect your Rank Math account in Rank Math → Dashboard on the new domain.',
            ],
            'wordpress-seo-premium/wp-seo-premium.php' => [
                'name' => 'Yoast SEO Premium',
                'action' => 'Add the new domain to your subscription in MyYoast and reconnect the site.',
            ],
            'js_composer/js_composer.php' => [
                'name' => 'WPBakery Page Builder',
                'action' => 'Deregister the licence on the old domain and activate it again on the new one (WPBakery → Product License).',
            ],
            'revslider/revslider.php' => [
                'name' => 'Slider Revolution',
                'action' => 'Deregister the purchase code on the old domain and register it on the new domain.',
            ],
            'LayerSlider/layerslider.php' => [
                'name' => 'LayerSlider',
                'action' => 'Deactivate the licence on the old domain and activate it on the new one.',
            ],
            'woocommerce-subscriptions/woocommerce-subscriptions.php' => [
                'name' => 'WooCommerce Subscriptions',
                'action' => 'After a URL change Subscriptions enters "staging mode" and stops automatic renewals. On the live new site choose "Enable automatic payments" in the admin notice — only on the real live site, never on a copy.',
            ],
            'jetpack/jetpack.php' => [
                'name' => 'Jetpack',
                'action' => 'Jetpack will report an "identity crisis": choose "Move Jetpack connection" (not "Create a fresh connection") so stats and settings carry over.',
            ],
            'wordfence/wordfence.php' => [
                'name' => 'Wordfence',
                'action' => 'Re-run Wordfence → Firewall → Optimize (its firewall file path changes); a Premium key must be moved to the new site in your Wordfence account.',
            ],
            'litespeed-cache/litespeed-cache.php' => [
                'name' => 'LiteSpeed Cache',
                'action' => 'Request a new QUIC.cloud domain key for the new domain (LiteSpeed Cache → General) and purge all caches.',
            ],
            'updraftplus/updraftplus.php' => [
                'name' => 'UpdraftPlus',
                'action' => 'Remote storage connections (Google Drive, Dropbox) are tied to the site URL: re-authenticate them in Settings → UpdraftPlus Backups. Premium: reconnect your updraftplus.com account.',
            ],
            'mainwp-child/mainwp-child.php' => [
                'name' => 'MainWP Child',
                'action' => 'Remove the old site from your MainWP dashboard and add the new URL again.',
            ],
            'theme:Avada' => [
                'name' => 'Avada (theme)',
                'action' => 'Deregister the purchase code on the old domain and register it on the new one (Avada → Dashboard).',
            ],
            'theme:Divi' => [
                'name' => 'Divi (theme)',
                'action' => 'Check that the Elegant Themes username and API key are still set in Divi → Theme Options → Updates.',
            ],
            'theme:flatsome' => [
                'name' => 'Flatsome (theme)',
                'action' => 'Re-register the purchase code for the new domain in Flatsome → Theme Registration.',
            ],
        ];
    }

    /**
     * @param string[] $activePlugins plugin basenames
     * @param string[] $themes        active theme folder names (child and parent)
     * @return array<int,array{key:string,name:string,action:string}>
     */
    public static function match(array $activePlugins, array $themes = []): array
    {
        $known = self::all();
        $keys = $activePlugins;
        foreach ($themes as $theme) {
            $keys[] = 'theme:' . $theme;
        }
        $found = [];
        foreach (array_unique($keys) as $key) {
            if (isset($known[$key])) {
                $found[] = ['key' => $key] + $known[$key];
            }
        }

        return $found;
    }
}

<?php
/**
 * Plugin Name:       SiteShifter
 * Plugin URI:        https://github.com/serhanco/SiteShifter
 * Description:       Shift WordPress sites safely: pre-flight checks, resumable export and import, and serialization-safe address changes.
 * Version:           0.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Serhan Demirel
 * Author URI:        https://serhandemirel.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siteshifter
 * Domain Path:       /languages
 *
 * @package SiteShifter
 */

defined('ABSPATH') || exit;

define('SITESHIFTER_VERSION', '0.2.1');
define('SITESHIFTER_FILE', __FILE__);
define('SITESHIFTER_DIR', plugin_dir_path(__FILE__));
define('SITESHIFTER_URL', plugin_dir_url(__FILE__));

require_once SITESHIFTER_DIR . 'includes/Requirements.php';

// Never fatal on an unsupported site: show a notice and stay inactive.
$siteshifter_problems = \SiteShifter\Plugin\Requirements::problems();
if ($siteshifter_problems !== []) {
    add_action('admin_notices', static function () use ($siteshifter_problems) {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>SiteShifter</strong></p><ul>';
        foreach ($siteshifter_problems as $problem) {
            echo '<li>' . esc_html($problem) . '</li>';
        }
        echo '</ul></div>';
    });
    return;
}

require_once SITESHIFTER_DIR . 'includes/Autoloader.php';
\SiteShifter\Plugin\Autoloader::register(SITESHIFTER_DIR);

add_action('plugins_loaded', static function () {
    \SiteShifter\Plugin\Plugin::boot();
});

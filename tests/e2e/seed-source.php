<?php
/**
 * Installs a WordPress test site in the folder given as argv[1] and fills it
 * with the kinds of data that break naive migrations.
 */
$dir = rtrim($argv[1], '/');
$url = $argv[2];
$_SERVER['HTTP_HOST'] = parse_url($url, PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/';
define('WP_INSTALLING', true);
require $dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

wp_install('Kaynak Site', 'admin', 'info@' . parse_url($url, PHP_URL_HOST), true, '', 'secret-pass');
update_option('siteurl', $url);
update_option('home', $url);

// Fake premium plugins so the licence list has something to find.
foreach (['elementor-pro/elementor-pro.php' => 'Elementor Pro', 'advanced-custom-fields-pro/acf.php' => 'Advanced Custom Fields PRO'] as $file => $name) {
    @mkdir(WP_PLUGIN_DIR . '/' . dirname($file), 0755, true);
    file_put_contents(WP_PLUGIN_DIR . '/' . $file, "<?php\n/**\n * Plugin Name: {$name}\n * Version: 1.0\n * Requires PHP: 7.4\n * Requires at least: 6.0\n */\n");
}
update_option('active_plugins', ['elementor-pro/elementor-pro.php', 'advanced-custom-fields-pro/acf.php']);

// Upload + content.
$upload = wp_upload_dir();
@mkdir($upload['path'], 0755, true);
file_put_contents($upload['path'] . '/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$img = $url . '/wp-content/uploads' . $upload['subdir'] . '/logo.png';

for ($i = 1; $i <= 30; $i++) {
    $id = wp_insert_post([
        'post_title' => "Yazı {$i} — Türkçe içerik",
        'post_content' => "<p>Ana sayfa: <a href=\"{$url}/\">{$url}</a></p><img src=\"{$img}\"><p>Mail: info@" . parse_url($url, PHP_URL_HOST) . '</p>',
        'post_status' => 'publish',
        'post_type' => $i % 3 === 0 ? 'page' : 'post',
    ]);
    // Elementor stores JSON with escaped slashes.
    update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode([['elType' => 'widget', 'settings' => ['image' => ['url' => $img, 'id' => 5], 'link' => ['url' => $url . '/iletisim']]]])));
    update_post_meta($id, '_elementor_css', ['status' => 'file']);
    // ACF link field: serialized array.
    update_post_meta($id, 'cta_link', ['title' => 'Detay', 'url' => $url . "/detay-{$i}", 'target' => '']);
}
update_option('widget_text', [2 => ['title' => 'Bağlantı', 'text' => "<a href=\"{$url}/hakkimizda\">Hakkımızda</a>"], '_multiwidget' => 1]);
update_option('ss_test_paths', ['cache_dir' => ABSPATH . 'wp-content/cache/', 'log' => ABSPATH . 'wp-content/debug.log']);
set_transient('ss_old_transient', $url);

// Server files that hard-code the old path.
file_put_contents(ABSPATH . 'wordfence-waf.php', "<?php // firewall\n");
file_put_contents(ABSPATH . '.user.ini', "auto_prepend_file = '" . ABSPATH . "wordfence-waf.php'\n");
file_put_contents(WP_CONTENT_DIR . '/object-cache.php', "<?php // redis drop-in that would fail on the new server\n");
@mkdir(WP_CONTENT_DIR . '/cache/page', 0755, true);
file_put_contents(WP_CONTENT_DIR . '/cache/page/index.html', 'cached');

echo "Seeded {$url} in {$dir}\n";

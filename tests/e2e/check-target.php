<?php
/** Loads the migrated site and prints facts for run.sh to compare. */
$dir = rtrim($argv[1], '/');
$url = $argv[2];
$_SERVER['HTTP_HOST'] = parse_url($url, PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/';
require $dir . '/wp-load.php';

$fail = 0;
$check = function (string $label, bool $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : ' — ' . print_r($detail, true)) . "\n";
    $fail += $ok ? 0 : 1;
};

$check('home option', get_option('home') === $url, get_option('home'));
$check('siteurl option', get_option('siteurl') === $url, get_option('siteurl'));
$posts = get_posts(['numberposts' => -1, 'post_type' => ['post', 'page'], 'post_status' => 'publish']);
$check('30 posts + pages (plus sample content)', count($posts) >= 30, count($posts));
$p = get_page_by_path('yazi-3-turkce-icerik', OBJECT, 'page') ?: $posts[0];
$check('post content uses new URL', strpos($p->post_content, $url . '/') !== false && strpos(str_replace('info@old.test', '', $p->post_content), 'old.test') === false, $p->post_content);
$check('email address untouched', strpos($p->post_content, 'info@old.test') !== false, $p->post_content);
$link = get_post_meta($p->ID, 'cta_link', true);
$check('ACF link (serialized) intact', is_array($link) && strpos($link['url'], $url . '/detay-') === 0, $link);
$el = json_decode(get_post_meta($p->ID, '_elementor_data', true), true);
$check('Elementor JSON intact', is_array($el) && strpos($el[0]['settings']['image']['url'], $url . '/wp-content/uploads/') === 0, $el);
$check('Elementor CSS cache cleared', get_post_meta($p->ID, '_elementor_css', true) === '');
$widget = get_option('widget_text');
$check('widget (serialized option) intact', is_array($widget) && strpos($widget[2]['text'], $url . '/hakkimizda') !== false, $widget);
$paths = get_option('ss_test_paths');
$check('server paths replaced', is_array($paths) && strpos($paths['cache_dir'], ABSPATH) === 0, $paths);
$check('old transient removed', get_transient('ss_old_transient') === false);
$check('uploaded file present', is_file(wp_upload_dir()['path'] . '/logo.png') || (bool) glob(WP_CONTENT_DIR . '/uploads/*/*/logo.png'));
$check('admin user can log in', !is_wp_error(wp_authenticate('admin', 'secret-pass')));
$ini = (string) @file_get_contents(ABSPATH . '.user.ini');
$check('.user.ini points to new path', $ini === '' || strpos($ini, ABSPATH) !== false, $ini);
$check('object-cache drop-in disabled', !is_file(WP_CONTENT_DIR . '/object-cache.php'));
$check('page cache folder not copied', !is_dir(WP_CONTENT_DIR . '/cache/page'));
exit($fail ? 1 : 0);

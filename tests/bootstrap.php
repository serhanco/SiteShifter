<?php
// The plugin files start with `defined('ABSPATH') || exit;` like any WordPress file.
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/siteshifter-abspath/');
}
require dirname(__DIR__) . '/vendor/autoload.php';

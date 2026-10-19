<?php
/** Load the real WordPress HTTP API and Requests without a database or a live site. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$telegramTestWp = getenv('TG_BACKUP_TEST_WP') ?: '';
foreach ($argv as $arg) { if (str_starts_with($arg, '--wp=')) { $telegramTestWp = substr($arg, 5); } }
$telegramTestWp = $telegramTestWp !== '' ? realpath($telegramTestWp) : false;
if (!$telegramTestWp || !is_file($telegramTestWp . '/wp-includes/class-wp-http.php')) {
    throw new RuntimeException('The --http suite requires real WordPress source: --wp=/path/to/wordpress or TG_BACKUP_TEST_WP. No database is needed.');
}
define('ABSPATH', $telegramTestWp . '/');
define('WPINC', 'wp-includes');
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/class-wp-http.php';
require ABSPATH . WPINC . '/class-wp-http-proxy.php';
require ABSPATH . WPINC . '/class-wp-http-cookie.php';
require ABSPATH . WPINC . '/class-wp-http-response.php';
require ABSPATH . WPINC . '/class-wp-http-requests-response.php';
require ABSPATH . WPINC . '/class-wp-http-requests-hooks.php';
require ABSPATH . WPINC . '/http.php';
// Only unrelated site/environment helpers are stubbed. HTTP, Requests, errors and hooks above are WordPress code.
function get_bloginfo($key) { return $key === 'version' ? 'test-core' : 'http://localhost'; }
function get_option($key, $default = false) { return $key === 'siteurl' ? 'http://localhost' : $default; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function __($text, $domain = 'default') { return $text; }
function mbstring_binary_safe_encoding($reset = false) {}
function reset_mbstring_encoding() {}
function get_status_header_desc($code) { return 'HTTP ' . (int) $code; }

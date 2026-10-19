<?php
/** Run inside a disposable WordPress: PARCEL_TEST=1 wp --skip-plugins eval-file tests/telegram-wordpress-test.php */
if (PHP_SAPI !== 'cli' || getenv('PARCEL_TEST') !== '1' || !defined('ABSPATH') || !function_exists('wp_remote_post')) {
    throw new RuntimeException('Use PARCEL_TEST=1 and WP-CLI eval-file --skip-plugins on a disposable WordPress installation.');
}
foreach (['Settings', 'Support', 'TelegramException', 'Telegram'] as $telegramTestClass) {
    if (class_exists('ParcelBackups\\' . $telegramTestClass, false)) {
        throw new RuntimeException('Run with --skip-plugins to prevent loading another plugin version.');
    }
    require dirname(__DIR__) . '/includes/' . $telegramTestClass . '.php';
}
$telegramTestRoot = sys_get_temp_dir() . '/tg-backup-wordpress-http-' . bin2hex(random_bytes(8));
if (!mkdir($telegramTestRoot, 0700)) { throw new RuntimeException('Cannot create test directory.'); }
$telegramTestOriginalTransport = isset($GLOBALS['wp_filter']['parcel_telegram_transport']) ? clone $GLOBALS['wp_filter']['parcel_telegram_transport'] : null;
$telegramTestOriginalSettings = get_option('parcel_settings', []);
$telegramTestProcess = null;
$telegramTestFilter = null;
$telegramTestChecks = 0;
$telegramTestCheck = static function ($ok, string $label) use (&$telegramTestChecks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $telegramTestChecks++;
};
try {
    file_put_contents($telegramTestRoot . '/router.php', <<<'ROUTER'
<?php
header('Content-Type: application/json');
$method = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$result = ['message_id' => 817, 'chat' => ['id' => -1001234567890, 'type' => 'supergroup'], 'received' => $_POST, 'marker' => isset($_SERVER['HTTP_X_TG_BACKUP_REQUEST'])];
if ($method === 'getMe') { $result = ['id' => 123456, 'is_bot' => true, 'username' => 'tg_test_bot']; }
if ($method === 'sendDocument') { $file = $_FILES['document'] ?? []; $result['sha256'] = hash_file('sha256', $file['tmp_name']); $result['bytes'] = $file['size']; }
echo json_encode(['ok' => true, 'result' => $result]);
ROUTER);
    $telegramTestListener = stream_socket_server('tcp://127.0.0.1:0', $telegramTestErrno, $telegramTestError);
    if (!$telegramTestListener) { throw new RuntimeException('Cannot open loopback listener.'); }
    $telegramTestAddress = stream_socket_get_name($telegramTestListener, false);
    fclose($telegramTestListener);
    $telegramTestProcess = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=8M', '-S', $telegramTestAddress, $telegramTestRoot . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $telegramTestRoot . '/http.log', 'a'], 2 => ['file', $telegramTestRoot . '/http.log', 'a']], $telegramTestPipes);
    if (!is_resource($telegramTestProcess)) { throw new RuntimeException('Cannot start loopback fixture.'); }
    fclose($telegramTestPipes[0]);
    $telegramTestReady = false;
    for ($telegramTestAttempt = 0; $telegramTestAttempt < 40; $telegramTestAttempt++) {
        $telegramTestProbe = @stream_socket_client('tcp://' . $telegramTestAddress, $telegramTestErrno, $telegramTestError, 0.1);
        if ($telegramTestProbe) { fclose($telegramTestProbe); $telegramTestReady = true; break; }
        usleep(50000);
    }
    $telegramTestCheck($telegramTestReady, 'loopback fixture');
    $telegramTestSettings = array_replace(\ParcelBackups\Settings::defaults(), [
        'consent' => true, 'api_mode' => 'local', 'api_url' => 'http://' . $telegramTestAddress,
        'chat_id' => '-1001234567890', 'thread_id' => 17,
        '_token' => \ParcelBackups\Settings::encrypt('123456:TEST_ONLY_NOT_A_REAL_TOKEN_12345'),
    ]);
    $telegramTestFilter = static fn() => $telegramTestSettings;
    add_filter('pre_option_parcel_settings', $telegramTestFilter);
    remove_all_filters('parcel_telegram_transport');
    $telegramTestBot = new \ParcelBackups\Telegram();
    $telegramTestCheck($telegramTestBot->request('getMe')['id'] === 123456, 'real WordPress transport + encrypted settings');
    $telegramTestMessage = $telegramTestBot->send_message('Starting backup');
    $telegramTestCheck($telegramTestMessage['chat']['type'] === 'supergroup' && $telegramTestMessage['received']['message_thread_id'] === '17', 'initial status recipient');
    $telegramTestMessage = $telegramTestBot->edit_message(817, 'Backup: 50%');
    $telegramTestCheck($telegramTestMessage['received']['message_id'] === '817' && !isset($telegramTestMessage['received']['message_thread_id']), 'edit existing message');
    file_put_contents($telegramTestRoot . '/part.pvlt', random_bytes(1048576));
    $telegramTestMessage = $telegramTestBot->send_document($telegramTestRoot . '/part.pvlt', 'Test fixture');
    $telegramTestCheck($telegramTestMessage['bytes'] === 1048576 && $telegramTestMessage['sha256'] === hash_file('sha256', $telegramTestRoot . '/part.pvlt'), 'streamed multipart bytes');
    $telegramTestCheck($telegramTestMessage['received']['disable_notification'] === 'true' && !$telegramTestMessage['marker'], 'silent document without internal marker');
    $telegramTestCheck(!has_action('requests-requests.before_request') && !has_action('http_api_curl'), 'no leaked transport hooks');
} finally {
    if ($telegramTestFilter) { remove_filter('pre_option_parcel_settings', $telegramTestFilter); }
    if ($telegramTestOriginalTransport) { $GLOBALS['wp_filter']['parcel_telegram_transport'] = $telegramTestOriginalTransport; }
    else { unset($GLOBALS['wp_filter']['parcel_telegram_transport']); }
    if (is_resource($telegramTestProcess)) { proc_terminate($telegramTestProcess); proc_close($telegramTestProcess); }
    foreach (glob($telegramTestRoot . '/*') ?: [] as $telegramTestFile) { unlink($telegramTestFile); }
    rmdir($telegramTestRoot);
}
$telegramTestCheck(get_option('parcel_settings', []) === $telegramTestOriginalSettings, 'persistent settings unchanged');
echo "WordPress Telegram HTTP integration: {$telegramTestChecks} checks passed. No live Telegram requests.\n";

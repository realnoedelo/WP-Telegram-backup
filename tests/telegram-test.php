<?php
/** Isolated transport regression checks: no WordPress install or live bot token needed. */
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (in_array('--http', $argv, true)) { require __DIR__ . '/wordpress-http-bootstrap.php'; }
}
namespace ParcelBackups {
    final class Settings {
        public static array $data = [
            'consent' => true, 'chat_id' => '-1001234567890', 'thread_id' => 17,
            'api_mode' => 'cloud', 'api_url' => 'https://api.telegram.org',
            'proxy_url' => '', 'proxy_username' => '', 'dns_servers' => '', 'doh_url' => '',
        ];
        public static function get(): array { return self::$data; }
        public static function secret(string $key): string {
            return ['token' => '123456:TEST_ONLY_NOT_A_REAL_TOKEN_12345', 'proxy_password' => 'private-proxy-value'][$key] ?? '';
        }
    }
    final class Support {
        public static function redact(string $text): string { return $text; }
    }
    final class Storage {
        public static function root(): string {
            $root = sys_get_temp_dir() . '/parcel-telegram-tests-' . getmypid();
            if (!is_dir($root)) { mkdir($root, 0700); }
            return $root;
        }
        public static function ensure_space(int $bytes): void {}
    }
}
namespace {
    use ParcelBackups\Settings;
    use ParcelBackups\Storage;
    use ParcelBackups\Telegram;
    use ParcelBackups\TelegramException;

    $mock = static fn() => ['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'parcel_test_bot']];
    $calls = [];
    $transportFilter = static function ($value, ...$args) {
        global $mock, $calls;
        $calls[] = $args;
        return $mock(...$args);
    };
    if (function_exists('add_filter')) {
        add_filter('parcel_telegram_transport', $transportFilter, 10, 5);
    } else {
        function apply_filters(string $filter, $value, ...$args) {
            global $transportFilter;
            if ($filter !== 'parcel_telegram_transport') { throw new \RuntimeException('Unexpected filter'); }
            return $transportFilter($value, ...$args);
        }
        function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    }
    if (!function_exists('wp_delete_file')) { function wp_delete_file($path) { unlink($path); } }
    require dirname(__DIR__) . '/includes/TelegramException.php';
    require dirname(__DIR__) . '/includes/Telegram.php';

    $count = 0;
    function check(bool $ok, string $message): void {
        global $count;
        if (!$ok) { throw new \RuntimeException('FAIL: ' . $message); }
        $count++;
    }
    function rejected(callable $callback, string $contains = '', ?bool $retryable = null): TelegramException {
        try { $callback(); } catch (TelegramException $error) {
            check($contains === '' || str_contains($error->getMessage(), $contains), 'failure description: ' . $error->getMessage());
            if ($retryable !== null) { check($error->retryable === $retryable, 'retry classification'); }
            return $error;
        }
        throw new \RuntimeException('FAIL: operation should have been rejected');
    }

    $bot = new Telegram();
    check($bot->request('getMe')['id'] === 123456, 'valid API envelope');
    rejected(fn() => $bot->request('deleteWebhook'), 'not allowed', false);
    rejected(fn() => $bot->request('sendDocument'), 'send_document', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['consent' => false])))->request('getMe'), 'confirm', false);

    $mock = static fn() => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 93], 'description' => 'Too Many Requests'];
    $error = rejected(fn() => $bot->request('getMe'), '93', true);
    check($error->retry_after === 93, 'Telegram retry_after is preserved');
    $mock = static fn() => ['ok' => false, 'error_code' => 503, 'description' => 'Service unavailable'];
    check(rejected(fn() => $bot->request('getMe'), '503', true)->retry_after === 60, '5xx retry delay');
    $mock = static fn() => ['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'];
    rejected(fn() => $bot->request('getMe'), 'Token', false);
    $mock = static fn() => ['ok' => false, 'error_code' => 400, 'parameters' => ['migrate_to_chat_id' => -1009876543210]];
    rejected(fn() => $bot->request('getMe'), '-1009876543210', false);
    check(Settings::$data['chat_id'] === '-1001234567890', 'migration never silently changes destination');

    $mock = static fn() => ['ok' => false, 'error_code' => 400, 'description' => 'url https://api.telegram.org/bot' . Settings::secret('token') . '/getMe pass private-proxy-value'];
    $error = rejected(fn() => $bot->request('getMe'));
    check(!str_contains($error->getMessage(), Settings::secret('token')), 'no token in errors');
    check(!str_contains($error->getMessage(), 'private-proxy-value'), 'no proxy password in errors');
    check(!str_contains($error->getMessage(), 'https://'), 'no credential-bearing URLs in errors');
    $mock = static fn() => ['ok' => true, 'result' => []];

    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => 'http://example.com'])))->request('getMe'), 'HTTPS', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => 'http://169.254.169.254'])))->request('getMe'), 'HTTPS', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => 'https://api.telegram.org'])))->request('getMe'), 'your own', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => 'https://user:password@example.com'])))->request('getMe'), 'URL', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['proxy_url' => 'ftp://localhost:1080'])))->request('getMe'), 'Proxy', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['proxy_url' => 'socks5h://u:p@localhost:1080'])))->request('getMe'), 'URL', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['dns_servers' => 'dns.example.com'])))->request('getMe'), 'DNS', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['dns_servers' => '1.1.1.1:0'])))->request('getMe'), 'DNS', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['doh_url' => 'http://dns.example.com/dns-query'])))->request('getMe'), 'HTTPS', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['doh_url' => 'https://dns.example.com/dns-query', 'proxy_url' => 'socks5h://localhost:1080'])))->request('getMe'), 'DoH with', false);
    rejected(fn() => (new Telegram(array_replace(Settings::get(), ['doh_url' => 'https://dns.example.com/dns-query', 'dns_servers' => '1.1.1.1'])))->request('getMe'), 'one', false);
    foreach (['http://127.0.0.1:8081', 'http://192.168.1.2:8081', 'http://10.1.1.1:8081', 'http://172.16.0.1:8081', 'http://[::1]:8081', 'https://local.example.org/telegram-api'] as $url) {
        check((new Telegram(array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => $url])))->request('getMe') === [], 'valid local endpoint');
    }

    $calls = [];
    $mock = static fn(string $method) => ['ok' => true, 'result' => $method === 'getWebhookInfo' ? ['url' => 'https://example.com/private-webhook'] : []];
    rejected(fn() => $bot->discover_chats(), 'webhook', false);
    check(count($calls) === 1, 'active webhook prevents polling');
    $calls = [];
    $mock = static fn(string $method) => ['ok' => true, 'result' => $method === 'getWebhookInfo' ? ['url' => ''] : [
        ['update_id' => 1, 'message' => ['chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Backups'], 'message_thread_id' => 17]],
        ['update_id' => 2, 'message' => ['chat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Backups'], 'message_thread_id' => 17]],
        ['update_id' => 3, 'message' => ['chat' => ['id' => 42, 'type' => 'private', 'first_name' => 'Owner']]],
    ]];
    $chats = $bot->discover_chats();
    check(count($chats) === 2 && $chats[0]['thread_id'] === 17, 'chat and topic discovery deduplicates');
    check($calls[1][1] === ['offset' => 0, 'limit' => 100, 'timeout' => 0], 'discovery does not consume updates or change allowed_updates');

    $calls = [];
    $mock = static fn() => ['ok' => true, 'result' => ['message_id' => 730, 'chat' => ['id' => -1001234567890, 'type' => 'supergroup']]];
    check($bot->send_message('Starting backup')['chat']['type'] === 'supergroup', 'initial status preserves confirmed chat metadata');
    check($calls[0][0] === 'sendMessage' && !isset($calls[0][1]['disable_notification']), 'initial status is the one normal notification');
    $calls = [];
    check($bot->edit_message(730, 'Progress: 50%')['message_id'] === 730, 'persistent status edit receipt');
    check($calls[0][0] === 'editMessageText' && $calls[0][1]['message_id'] === 730 && !isset($calls[0][1]['message_thread_id']), 'status edits address the existing message without a topic parameter');
    $mock = static fn() => ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'];
    check(!empty($bot->edit_message(730, 'Progress: 50%')['unchanged']), 'unchanged status is a successful no-op');
    rejected(fn() => $bot->edit_message(0, 'Invalid'), 'valid message ID', false);
    $mock = static fn() => ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden'];
    rejected(fn() => $bot->edit_message(730, 'Progress'), 'blocked', false);

    $path = Storage::root() . '/backup.pvlt';
    file_put_contents($path, 'encrypted test fixture');
    $calls = [];
    $mock = static fn() => ['ok' => true, 'result' => ['message_id' => 731]];
    check($bot->send_document($path, 'Test')['message_id'] === 731, 'document receipt');
    check($calls[0][1]['chat_id'] === '-1001234567890' && $calls[0][1]['message_thread_id'] === 17, 'document destination and forum topic');
    check($calls[0][2] === $path && !isset($calls[0][3]['token'], $calls[0][3]['proxy_password']), 'transport hook passes path without decrypted secrets');
    check($calls[0][1]['disable_notification'] === 'true', 'every document including helpers is silent');
    $handle = fopen($path, 'r+');
    ftruncate($handle, 45 * 1048576 + 1); fclose($handle);
    rejected(fn() => $bot->send_document($path), 'limit', false);
    $local = new Telegram(array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => 'http://127.0.0.1:8081']));
    check($local->send_document($path)['message_id'] === 731, 'local mode accepts above cloud cap');
    $handle = fopen($path, 'r+');
    ftruncate($handle, 1900 * 1048576 + 1); fclose($handle);
    rejected(fn() => $local->send_document($path), 'limit', false);
    unlink($path);

    // Exercise diagnostics with mocked responses but real cURL capability detection.
    $mock = static fn(string $method) => ['ok' => true, 'result' => match ($method) {
        'getMe' => ['id' => 123456, 'is_bot' => true, 'username' => 'parcel_test_bot'],
        'getChat' => ['id' => -1001234567890, 'type' => 'supergroup', 'title' => 'Backups'],
        'getWebhookInfo' => ['url' => 'https://secret.example/private-path'],
        'sendDocument' => ['message_id' => 732],
        default => [],
    }];
    $calls = [];
    $diagnostic = $bot->diagnostics(false);
    check($diagnostic['ok'], 'read-only diagnostics success');
    check(count(array_filter($calls, static fn($call) => $call[0] === 'sendDocument')) === 0, 'read-only diagnostic sends no file');
    check(!empty($diagnostic['checks'][count($diagnostic['checks']) - 1]['skipped']), 'unperformed upload is explicitly skipped');
    check(!str_contains(json_encode($diagnostic), 'secret.example'), 'diagnostics hide webhook URL');
    $diagnostic = $bot->diagnostics(true);
    check($diagnostic['ok'], 'explicit upload diagnostic success');
    check(glob(Storage::root() . '/connection-test-*') === [], 'test file cleaned after success');
    $version = curl_version();
    if (empty($version['ares']) || !defined('CURLOPT_DNS_SERVERS')) {
        $diagnostic = (new Telegram(array_replace(Settings::get(), ['dns_servers' => '1.1.1.1'])))->diagnostics();
        check(!$diagnostic['ok'] && str_contains($diagnostic['checks'][0]['detail'], 'c-ares'), 'unsupported custom DNS fails explicitly');
    }

    // Opt-in loopback integration: actual multipart upload and HTTP error handling.
    // The server and client use a fake token and bind only to 127.0.0.1.
    if (in_array('--http', $argv, true)) {
        $router = Storage::root() . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: application/json');
$route = $_SERVER['REQUEST_URI'];
file_put_contents(__DIR__ . '/requests.jsonl', json_encode(['route' => $route]) . "\n", FILE_APPEND);
if (str_starts_with($route, 'http://127.0.0.2:9/')) {
    if (($_SERVER['HTTP_PROXY_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('parcel-test:private-proxy-value')) {
        http_response_code(407); echo json_encode(['ok' => false, 'error_code' => 407, 'description' => 'Proxy credentials rejected']); exit;
    }
    echo json_encode(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'proxy_auth_ok' => true, 'absolute_target' => true]]); exit;
}
if (str_starts_with($route, '/redirect/')) {
    header('Location: /followed/getMe', true, 302); exit;
}
if (str_starts_with($route, '/followed/')) {
    file_put_contents(__DIR__ . '/redirect-followed', 'bad'); exit;
}
if (str_starts_with($route, '/rate/')) {
    http_response_code(429); header('Retry-After: 121');
    echo json_encode(['ok' => false, 'error_code' => 429]); exit;
}
if (str_starts_with($route, '/unavailable/')) {
    http_response_code(503); echo '<html>Temporary maintenance</html>'; exit;
}
if (str_starts_with($route, '/gzip/')) {
    header('Content-Encoding: gzip'); echo gzencode(json_encode(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])); exit;
}
if (str_starts_with($route, '/bomb/')) {
    header('Content-Encoding: gzip'); echo gzencode(str_repeat('x', 2097153)); exit;
}
if (str_starts_with($route, '/bad-encoding/')) {
    header('Content-Encoding: gzip'); echo 'not a gzip stream'; exit;
}
if (str_starts_with($route, '/overflow/')) {
    echo str_repeat('x', 2097153); exit;
}
$method = basename(parse_url($route, PHP_URL_PATH));
if ($method === 'sendDocument') {
    $upload = $_FILES['document'] ?? [];
    if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || ($_POST['chat_id'] ?? '') !== '-1001234567890' || ($_POST['message_thread_id'] ?? '') !== '17' || ($_POST['disable_notification'] ?? '') !== 'true') {
        http_response_code(400); echo json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Malformed multipart or recipient']); exit;
    }
    echo json_encode(['ok' => true, 'result' => ['message_id' => 817, 'document' => ['file_name' => $upload['name'], 'file_size' => $upload['size'], 'fixture_sha256' => hash_file('sha256', $upload['tmp_name'])]]]); exit;
}
echo json_encode(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'parcel_test_bot', 'internal_marker' => isset($_SERVER['HTTP_X_TG_BACKUP_REQUEST']), 'test_header' => $_SERVER['HTTP_X_TEST_SCOPE'] ?? '', 'proxy_auth' => isset($_SERVER['HTTP_PROXY_AUTHORIZATION'])]]);
PHP);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$listener) { throw new \RuntimeException('Cannot choose loopback port: ' . $errstr); }
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=64M', '-d', 'post_max_size=64M', '-S', $address, $router], [0 => ['pipe', 'r'], 1 => ['file', Storage::root() . '/http.log', 'a'], 2 => ['file', Storage::root() . '/http.log', 'a']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start loopback test server'); }
        fclose($pipes[0]);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 40; $attempt++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(50000);
            }
            check($ready, 'loopback test server started');
            $mock = static fn() => null;
            $localSettings = array_replace(Settings::get(), ['api_mode' => 'local', 'api_url' => 'http://' . $address]);
            $local = new Telegram($localSettings);
            check($local->request('getMe')['id'] === 123456, 'real WordPress HTTP API request reaches local Bot API');
            check(!$local->request('getMe')['internal_marker'], 'opaque scope marker is not transmitted');
            $assertCleanHooks = static function (): void {
                check(!has_action('http_api_curl') && !has_action('requests-requests.before_request'), 'temporary HTTP hooks are removed');
            };
            $assertCleanHooks();
            $transportCache = \WpOrg\Requests\Requests::$transport;
            \WpOrg\Requests\Requests::$transport[serialize(['ssl' => false])] = \WpOrg\Requests\Transport\Fsockopen::class;
            try { check($local->request('getMe')['id'] === 123456, 'request selects cURL even when global Requests transport is streams'); }
            finally { \WpOrg\Requests\Requests::$transport = $transportCache; }
            $assertCleanHooks();
            $intercept = static fn() => ['response' => ['code' => 200], 'body' => '{"ok":true,"result":{"message_id":123}}'];
            add_filter('pre_http_request', $intercept);
            try { rejected(fn() => $local->request('getMe'), 'intercepted', false); }
            finally { remove_filter('pre_http_request', $intercept); }
            $assertCleanHooks();
            $intercept = static fn() => new \WP_Error('http_request_failed', 'cURL error 28: sensitive ' . Settings::secret('token'));
            add_filter('pre_http_request', $intercept);
            try {
                $error = rejected(fn() => $local->request('getMe'), 'timed out', true);
                check(!str_contains($error->getMessage(), Settings::secret('token')), 'WP HTTP errors cannot leak the token');
            } finally { remove_filter('pre_http_request', $intercept); }
            $assertCleanHooks();
            $intercept = static function () { throw new \RuntimeException('Unexpected https://example.test/bot' . Settings::secret('token')); };
            add_filter('pre_http_request', $intercept);
            try {
                $error = rejected(fn() => $local->request('getMe'), 'WordPress HTTP API', false);
                check(!str_contains($error->getMessage(), Settings::secret('token')) && !str_contains($error->getMessage(), 'https://'), 'unexpected hook exceptions are redacted');
            } finally { remove_filter('pre_http_request', $intercept); }
            $assertCleanHooks();
            $nested = null;
            $nestedFilter = static function ($pre, $args) use ($address, &$nested) {
                if (!empty($args['_tg_backup_request'])) {
                    $nested = wp_remote_post('http://' . $address . '/nested/getMe', ['headers' => ['X-Test-Scope' => 'untouched']]);
                }
                return $pre;
            };
            add_filter('pre_http_request', $nestedFilter, 10, 2);
            try { $local->request('getMe'); }
            finally { remove_filter('pre_http_request', $nestedFilter, 10); }
            check(!is_wp_error($nested) && json_decode(wp_remote_retrieve_body($nested), true)['result']['test_header'] === 'untouched', 'nested unrelated WordPress request retains its own headers');
            $assertCleanHooks();
            $ordinary = wp_remote_post('http://' . $address . '/ordinary/getMe', ['headers' => ['X-Test-Scope' => 'after']]);
            $ordinaryResult = json_decode(wp_remote_retrieve_body($ordinary), true)['result'];
            check($ordinaryResult['test_header'] === 'after' && !$ordinaryResult['proxy_auth'], 'ordinary request after plugin call has no leaked proxy credentials');

            $upload = Storage::root() . '/multipart-fixture.pvlt';
            $stream = fopen($upload, 'wb');
            $chunk = random_bytes(1048576);
            for ($i = 0; $i < 20; $i++) { fwrite($stream, $chunk); }
            fclose($stream); unset($chunk);
            $before = memory_get_usage(true);
            if (function_exists('memory_reset_peak_usage')) { memory_reset_peak_usage(); }
            $message = $local->send_document($upload, 'Loopback multipart verification');
            check($message['message_id'] === 817 && $message['document']['file_size'] === 20 * 1048576, 'real 20MiB multipart reaches receiver');
            check($message['document']['fixture_sha256'] === hash_file('sha256', $upload), 'real multipart preserves bytes');
            check(memory_get_peak_usage(true) - $before < 10 * 1048576, 'multipart does not load whole file in PHP memory');
            $httpProxySettings = array_replace($localSettings, ['api_url' => 'http://127.0.0.2:9', 'proxy_url' => 'http://' . $address, 'proxy_username' => 'parcel-test']);
            $proxyResult = (new Telegram($httpProxySettings))->request('getMe');
            check(!empty($proxyResult['proxy_auth_ok']) && !empty($proxyResult['absolute_target']), 'HTTP proxy receives destination and separate Basic credentials');
            rejected(fn() => (new Telegram(array_replace($httpProxySettings, ['proxy_username' => 'wrong-user'])))->request('getMe'), '407', false);
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $socksAddress = stream_socket_get_name($listener, false); fclose($listener);
            $socksLog = Storage::root() . '/socks.jsonl';
            $socksPipes = [];
            $socks = proc_open([PHP_BINARY, __DIR__ . '/socks-fixture.php', $socksAddress, $socksLog], [0 => ['pipe', 'r'], 1 => ['file', Storage::root() . '/socks.log', 'a'], 2 => ['file', Storage::root() . '/socks.log', 'a']], $socksPipes);
            if (!is_resource($socks)) { throw new \RuntimeException('Cannot start SOCKS fixture'); }
            fclose($socksPipes[0]);
            try {
                for ($attempt = 0; $attempt < 40; $attempt++) {
                    $probe = @stream_socket_client('tcp://' . $socksAddress, $errno, $errstr, 0.1);
                    if ($probe) { fclose($probe); break; }
                    usleep(50000);
                }
                $socksSettings = array_replace($localSettings, ['api_url' => 'http://localhost:' . substr($address, strrpos($address, ':') + 1), 'proxy_username' => 'parcel-test']);
                foreach (['socks5', 'socks5h'] as $scheme) {
                    $socksBot = new Telegram(array_replace($socksSettings, ['proxy_url' => $scheme . '://' . $socksAddress]));
                    check($socksBot->request('getMe')['id'] === 123456, $scheme . ' authenticated roundtrip');
                    $viaProxy = $socksBot->send_document($upload, 'SOCKS multipart test');
                    check($viaProxy['document']['fixture_sha256'] === hash_file('sha256', $upload), $scheme . ' streams multipart through same proxy');
                }
                $entries = array_map(static fn($line) => json_decode($line, true), file($socksLog, FILE_IGNORE_NEW_LINES));
                check(count($entries) === 4, 'all four SOCKS operations reached proxy');
                check($entries[0]['address_type'] !== 3 && $entries[1]['address_type'] !== 3, 'SOCKS5 resolves destination locally');
                check($entries[2]['address_type'] === 3 && $entries[2]['destination'] === 'localhost' && $entries[3]['address_type'] === 3, 'SOCKS5H delegates destination hostname to proxy');
                check(count(array_filter($entries, static fn($entry) => $entry['auth_ok'])) === 4, 'SOCKS username/password authentication verified');
                rejected(fn() => (new Telegram(array_replace($socksSettings, ['proxy_url' => 'socks5h://' . $socksAddress, 'proxy_username' => 'wrong-user'])))->request('getMe'), 'Proxy', false);
            } finally {
                proc_terminate($socks); proc_close($socks);
                foreach (['socks.jsonl', 'socks.log'] as $name) { if (file_exists(Storage::root() . '/' . $name)) { unlink(Storage::root() . '/' . $name); } }
            }
            rejected(fn() => (new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/redirect'])))->request('getMe'), 'redirect', false);
            check(!file_exists(Storage::root() . '/redirect-followed'), 'redirect target was not contacted');
            $error = rejected(fn() => (new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/rate'])))->request('getMe'), '121', true);
            check($error->retry_after === 121, 'real HTTP Retry-After header respected');
            rejected(fn() => (new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/unavailable'])))->request('getMe'), '503', true);
            rejected(fn() => (new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/overflow'])))->request('getMe'), 'too large', false);
            $assertCleanHooks();
            check((new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/gzip'])))->request('getMe')['id'] === 123456, 'compressed JSON is decoded with a size bound');
            rejected(fn() => (new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/bomb'])))->request('getMe'), 'too large', false);
            $assertCleanHooks();
            rejected(fn() => (new Telegram(array_replace($localSettings, ['api_url' => 'http://' . $address . '/bad-encoding'])))->request('getMe'), 'encoded', false);
            $assertCleanHooks();
            $requestLog = array_map(static fn($line) => json_decode($line, true), file(Storage::root() . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
            foreach (['overflow', 'bomb', 'bad-encoding'] as $prefix) {
                check(count(array_filter($requestLog, static fn($entry) => str_starts_with($entry['route'], '/' . $prefix . '/'))) === 1, $prefix . ' rejection never repeats the HTTP request');
            }

            unlink($upload);
        } finally {
            proc_terminate($process); proc_close($process);
            foreach (['router.php', 'http.log', 'redirect-followed', 'multipart-fixture.pvlt', 'requests.jsonl'] as $name) {
                if (file_exists(Storage::root() . '/' . $name)) { unlink(Storage::root() . '/' . $name); }
            }
        }
    }
    rmdir(Storage::root());
    echo "Telegram transport: {$count} checks passed. No live Telegram requests.\n";
}

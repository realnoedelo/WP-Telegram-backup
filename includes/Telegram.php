<?php
/** One transport for diagnostics, discovery, notifications and streamed uploads. GPL-3.0-or-later. */
namespace ParcelBackups;

final class Telegram
{
    private const METHODS = ['getMe', 'getChat', 'getWebhookInfo', 'getUpdates', 'sendMessage', 'sendDocument'];
    private const MAX_RESPONSE_BYTES = 2097152;
    private array $settings;
    private string $token;
    private string $proxyPassword;

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? Settings::get();
        unset($this->settings['token'], $this->settings['password'], $this->settings['proxy_password']);
        $this->token = Settings::secret('token');
        $this->proxyPassword = Settings::secret('proxy_password');
    }

    public function request(string $method, array $params = []): array
    {
        if ($method === 'sendDocument') {
            throw new TelegramException('Use send_document to upload a local file.');
        }
        // offset=0 only inspects the pending queue; it never acknowledges updates.
        // Do not set allowed_updates: Telegram persists it for other bot consumers.
        if ($method === 'getUpdates') {
            $params = ['offset' => 0, 'limit' => 100, 'timeout' => 0];
        }
        return $this->transport($method, $params);
    }

    public function send_document(string $path, string $caption = ''): array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new TelegramException('The file to upload is missing, unreadable, or a symbolic link.');
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        $limit = (($this->settings['api_mode'] ?? 'cloud') === 'local' ? 1900 : 45) * 1048576;
        if ($size === false || $size < 1 || $size > $limit) {
            throw new TelegramException('The part exceeds the safe size limit for the selected Bot API, or the file is empty.');
        }
        $params = $this->recipient();
        $params['caption'] = self::clip($caption, 900);
        $params['disable_content_type_detection'] = 'true';
        $result = $this->transport('sendDocument', $params, $path);
        if (empty($result['message_id'])) {
            throw new TelegramException('The Bot API did not confirm a message ID for the uploaded document. Check the chat before retrying: a duplicate is possible.', true, 60);
        }
        return $result;
    }

    public function send_message(string $text): array
    {
        if (trim($text) === '') {
            throw new TelegramException('The message text is empty.');
        }
        return $this->transport('sendMessage', $this->recipient() + ['text' => self::clip($text, 4000)]);
    }

    public function diagnostics(bool $sendTest = false): array
    {
        $result = ['ok' => true, 'checks' => []];
        $add = static function (string $id, string $label, bool $ok, string $detail, ?int $ms = null) use (&$result): void {
            $check = compact('id', 'label', 'ok', 'detail');
            if ($ms !== null) {
                $check['ms'] = $ms;
            }
            $result['checks'][] = $check;
            if (!$ok) {
                $result['ok'] = false;
            }
        };
        try {
            $this->validate();
            $handle = $this->make_handle(false);
            unset($handle);
            $add('network', 'Network settings', true, $this->network_description());
        } catch (\Throwable $error) {
            $add('network', 'Network settings', false, $this->redact($error->getMessage()));
            return $result;
        }
        $start = microtime(true);
        try {
            $bot = $this->request('getMe');
            if (empty($bot['id']) || empty($bot['is_bot'])) {
                throw new TelegramException('The getMe response does not confirm a bot account.');
            }
            $result['bot'] = array_intersect_key($bot, array_flip(['id', 'username', 'first_name']));
            $add('bot', 'Bot authentication', true, '@' . ($bot['username'] ?? (string) $bot['id']), (int) round((microtime(true) - $start) * 1000));
        } catch (\Throwable $error) {
            $add('bot', 'Bot authentication', false, $this->redact($error->getMessage()), (int) round((microtime(true) - $start) * 1000));
            return $result;
        }
        try {
            $webhook = $this->request('getWebhookInfo');
            $active = !empty($webhook['url']);
            $add('webhook', 'Bot compatibility', true, $active
                ? 'Another application has an active webhook. Sending is available; chat discovery is disabled. The webhook address is hidden.'
                : 'No webhook is configured; safe chat discovery is available.');
        } catch (\Throwable $error) {
            $add('webhook', 'Bot compatibility', false, $this->redact($error->getMessage()));
        }
        try {
            $recipient = $this->recipient();
            $chat = $this->request('getChat', ['chat_id' => $recipient['chat_id']]);
            $result['chat'] = array_intersect_key($chat, array_flip(['id', 'type', 'title', 'username', 'first_name', 'is_forum']));
            $add('chat', 'Destination chat', true, (string) ($chat['title'] ?? $chat['first_name'] ?? $chat['id'] ?? 'Chat is accessible') . '. Sending permissions and topic access are checked only by sending a test document.');
        } catch (\Throwable $error) {
            $add('chat', 'Destination chat', false, $this->redact($error->getMessage()));
            return $result;
        }
        if ($sendTest) {
            $path = '';
            try {
                Storage::ensure_space(4096);
                $path = Storage::root() . '/connection-test-' . bin2hex(random_bytes(8)) . '.txt';
                $body = "Telegram Backup: connection test.\nNo site data is included.\nUTC: " . gmdate('c') . "\n";
                if (file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
                    throw new TelegramException('Could not create a test file in protected storage.');
                }
                chmod($path, 0600);
                $message = $this->send_document($path, 'Telegram Backup: document delivery test. No website data was sent.');
                $add('upload', 'Document delivery', true, 'Telegram confirmed message #' . (string) $message['message_id'] . '. Check it in the selected chat.');
            } catch (\Throwable $error) {
                $add('upload', 'Document delivery', false, $this->redact($error->getMessage()));
            } finally {
                if ($path !== '' && is_file($path)) {
                    unlink($path);
                }
            }
        } else {
            $add('upload', 'Document delivery', true, 'Not tested. Click “Send test file” to check permissions, topic access, and uploads.', null);
            $result['checks'][count($result['checks']) - 1]['skipped'] = true;
        }
        return $result;
    }

    public function discover_chats(): array
    {
        $webhook = $this->request('getWebhookInfo');
        if (!empty($webhook['url'])) {
            throw new TelegramException('The bot has an active webhook for another application. Enter the Chat ID manually or create a dedicated bot; the webhook was not changed.');
        }
        $updates = $this->request('getUpdates');
        $chats = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post', 'my_chat_member'] as $kind) {
                $message = $update[$kind] ?? null;
                if (!is_array($message) || !isset($message['chat']['id'])) {
                    continue;
                }
                $chat = $message['chat'];
                $thread = (int) ($message['message_thread_id'] ?? 0);
                $item = [
                    'id' => (string) $chat['id'],
                    'title' => (string) ($chat['title'] ?? $chat['first_name'] ?? $chat['username'] ?? $chat['id']),
                    'type' => (string) ($chat['type'] ?? 'unknown'),
                ];
                if ($thread > 0) {
                    $item['thread_id'] = $thread;
                }
                $chats[$item['id'] . ':' . $thread] = $item;
            }
        }
        return array_values($chats);
    }

    private function recipient(): array
    {
        $chat = trim((string) ($this->settings['chat_id'] ?? ''));
        if (!preg_match('/^(?:-?[1-9][0-9]{0,19}|@[A-Za-z][A-Za-z0-9_]{4,63})$/D', $chat)) {
            throw new TelegramException('Enter a valid Chat ID or public channel name @username.');
        }
        $params = ['chat_id' => $chat];
        $thread = (int) ($this->settings['thread_id'] ?? 0);
        if ($thread > 0) {
            $params['message_thread_id'] = $thread;
        }
        return $params;
    }

    private function validate(): void
    {
        if (empty($this->settings['consent'])) {
            throw new TelegramException('First, confirm consent to send data to the selected Telegram Bot API in settings.');
        }
        if (!preg_match('/^[1-9][0-9]{4,19}:[A-Za-z0-9_-]{20,200}$/D', $this->token)) {
            throw new TelegramException('The bot token is missing or has an invalid format. Obtain it from @BotFather.');
        }
        $this->endpoint();
        $proxy = trim((string) ($this->settings['proxy_url'] ?? ''));
        $dns = trim((string) ($this->settings['dns_servers'] ?? ''));
        $doh = trim((string) ($this->settings['doh_url'] ?? ''));
        if ($proxy !== '') {
            $parts = self::url_parts($proxy);
            if (!in_array(strtolower($parts['scheme']), ['http', 'https', 'socks5', 'socks5h'], true) || !empty($parts['path'])) {
                throw new TelegramException('Proxy URLs must use http://host:port, https://host:port, socks5://host:port, or socks5h://host:port; enter credentials separately.');
            }
        }
        if ($dns !== '' && $doh !== '') {
            throw new TelegramException('Choose one DNS mode: a server list or DNS-over-HTTPS.');
        }
        if ($dns !== '') {
            foreach (explode(',', $dns) as $server) {
                $server = trim($server);
                $address = $server;
                $port = null;
                if (preg_match('/^\[([^\]]+)\](?::([0-9]{1,5}))?$/D', $server, $match)) {
                    $address = $match[1];
                    $port = $match[2] ?? null;
                } elseif (preg_match('/^([0-9.]+):([0-9]{1,5})$/D', $server, $match)) {
                    $address = $match[1];
                    $port = $match[2];
                }
                if (!filter_var($address, FILTER_VALIDATE_IP) || ($port !== null && ((int) $port < 1 || (int) $port > 65535))) {
                    throw new TelegramException('DNS: enter comma-separated IP addresses with optional ports; enclose IPv6 addresses in square brackets when specifying a port.');
                }
            }
        }
        if ($doh !== '') {
            $parts = self::url_parts($doh);
            if (strtolower($parts['scheme']) !== 'https') {
                throw new TelegramException('DNS-over-HTTPS requires an https:// URL without a username, password, or query parameters.');
            }
            if ($proxy !== '') {
                throw new TelegramException('DoH with a proxy is not supported: libcurl may send DNS requests outside the proxy. Use socks5h with DNS on the proxy, or socks5 with a DNS server list.');
            }
        }
    }

    private function endpoint(): string
    {
        $mode = $this->settings['api_mode'] ?? 'cloud';
        if ($mode === 'cloud') {
            return 'https://api.telegram.org';
        }
        if ($mode !== 'local') {
            throw new TelegramException('Unknown Bot API mode.');
        }
        $url = rtrim(trim((string) ($this->settings['api_url'] ?? '')), '/');
        $parts = self::url_parts($url);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'api.telegram.org' || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new TelegramException('For files up to 2 GB, enter the address of your own Local Bot API Server instead of api.telegram.org.');
        }
        if (strtolower($parts['scheme']) === 'http' && !self::private_host($host)) {
            throw new TelegramException('Unencrypted HTTP is allowed only for localhost or a private Local Bot API IP address. Use HTTPS for a remote server.');
        }
        $path = $parts['path'] ?? '';
        if (str_contains($path, '..') || !preg_match('#^[A-Za-z0-9/_~.\-]*$#D', $path)) {
            throw new TelegramException('Invalid Local Bot API path. Enter the base URL without the token or method name.');
        }
        return $url;
    }

    private static function url_parts(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new TelegramException('The server address contains spaces or control characters.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new TelegramException('Invalid URL: a scheme and host are required, without credentials, query parameters, or a fragment.');
        }
        return $parts;
    }

    private static function private_host(string $host): bool
    {
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = array_map('intval', explode('.', $host));
            return $octets[0] === 127 || $octets[0] === 10 || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31) || ($octets[0] === 192 && $octets[1] === 168);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($host);
            return $binary !== false && (ord($binary[0]) & 0xfe) === 0xfc;
        }
        return false;
    }

    /** Configure exactly the same DNS, proxy, trust and redirect policy for every operation. */
    private function make_handle(bool $upload): \CurlHandle
    {
        if (!extension_loaded('curl')) {
            throw new TelegramException('PHP cURL is not installed on the server. Contact your hosting provider.');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new TelegramException('Could not initialize a cURL connection.');
        }
        $timeout = $upload ? (($this->settings['api_mode'] ?? 'cloud') === 'local' ? 3600 : 600) : 30;
        $options = [
            CURLOPT_POST => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 32,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'ParcelBackups/' . (defined('PARCEL_VERSION') ? PARCEL_VERSION : '1.0.0'),
            // Disable environment proxies and NO_PROXY bypasses: the UI is authoritative.
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Expect:'],
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        $proxy = trim((string) ($this->settings['proxy_url'] ?? ''));
        if ($proxy !== '') {
            $parts = self::url_parts($proxy);
            $scheme = strtolower($parts['scheme']);
            $constant = ['http' => 'CURLPROXY_HTTP', 'https' => 'CURLPROXY_HTTPS', 'socks5' => 'CURLPROXY_SOCKS5', 'socks5h' => 'CURLPROXY_SOCKS5_HOSTNAME'][$scheme];
            if (!defined($constant)) {
                unset($handle);
                throw new TelegramException('This cURL build does not support the selected proxy type.');
            }
            $options[CURLOPT_PROXY] = $proxy;
            $options[CURLOPT_PROXYTYPE] = constant($constant);
            if ($scheme === 'https') {
                if (!defined('CURLOPT_PROXY_SSL_VERIFYPEER') || !defined('CURLOPT_PROXY_SSL_VERIFYHOST')) {
                    unset($handle);
                    throw new TelegramException('An HTTPS proxy requires a cURL build that can verify its TLS certificate.');
                }
                $options[constant('CURLOPT_PROXY_SSL_VERIFYPEER')] = true;
                $options[constant('CURLOPT_PROXY_SSL_VERIFYHOST')] = 2;
            }
            $username = (string) ($this->settings['proxy_username'] ?? '');
            if ($username !== '' || $this->proxyPassword !== '') {
                $options[CURLOPT_PROXYUSERNAME] = $username;
                $options[CURLOPT_PROXYPASSWORD] = $this->proxyPassword;
            }
        }
        $dns = trim((string) ($this->settings['dns_servers'] ?? ''));
        if ($dns !== '') {
            $version = curl_version();
            if (!defined('CURLOPT_DNS_SERVERS') || empty($version['ares'])) {
                unset($handle);
                throw new TelegramException('A DNS server list requires PHP cURL with c-ares and CURLOPT_DNS_SERVERS. Your host does not support this setting; it was not silently ignored. Try DoH without a proxy, or socks5h.');
            }
            $options[constant('CURLOPT_DNS_SERVERS')] = implode(',', array_map('trim', explode(',', $dns)));
        }
        $doh = trim((string) ($this->settings['doh_url'] ?? ''));
        if ($doh !== '') {
            foreach (['CURLOPT_DOH_URL', 'CURLOPT_DOH_SSL_VERIFYPEER', 'CURLOPT_DOH_SSL_VERIFYHOST'] as $constant) {
                if (!defined($constant)) {
                    unset($handle);
                    throw new TelegramException('This PHP cURL build does not support DoH with explicit TLS verification. Update PHP/cURL or choose another DNS mode.');
                }
            }
            $options[constant('CURLOPT_DOH_URL')] = $doh;
            $options[constant('CURLOPT_DOH_SSL_VERIFYPEER')] = true;
            $options[constant('CURLOPT_DOH_SSL_VERIFYHOST')] = 2;
        }
        try {
            foreach ($options as $option => $value) {
                if (!curl_setopt($handle, $option, $value)) {
                    throw new TelegramException('cURL could not apply a network setting. The request was not sent; check DNS and proxy support with your hosting provider.');
                }
            }
        } catch (\Throwable $error) {
            unset($handle);
            if ($error instanceof TelegramException) {
                throw $error;
            }
            throw new TelegramException('cURL does not support one of the selected network settings. The request was not sent.');
        }
        return $handle;
    }

    private function transport(string $method, array $params, ?string $file = null): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new TelegramException('This Bot API method is not allowed by the plugin.');
        }
        $this->validate();
        // Local filter for integration tests. It never receives decrypted secrets.
        $override = apply_filters('parcel_telegram_transport', null, $method, $params, $file, $this->settings);
        if ($override !== null) {
            if (!is_array($override)) {
                throw new TelegramException('The test transport returned an invalid response.');
            }
            return $this->decode_envelope($override, 200);
        }
        $handle = $this->make_handle($file !== null);
        $body = '';
        $overflow = false;
        $httpRetryAfter = 0;
        try {
            curl_setopt($handle, CURLOPT_URL, $this->endpoint() . '/bot' . $this->token . '/' . $method);
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $overflow = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            });
            curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$httpRetryAfter): int {
                if (preg_match('/^Retry-After:\s*([0-9]+)\s*$/i', trim($line), $match)) {
                    $httpRetryAfter = min(604800, (int) $match[1]);
                }
                return strlen($line);
            });
            if ($file !== null) {
                // CURLFile streams from disk; never concatenate multipart bodies in PHP memory.
                $params['document'] = new \CURLFile($file, 'application/octet-stream', basename($file));
                curl_setopt($handle, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            }
            $success = curl_exec($handle);
            $error = curl_errno($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($overflow) {
                throw new TelegramException('The Bot API response is too large. Check the server address and pending updates queue.');
            }
            if ($success === false || $error !== 0) {
                $retry = in_array($error, [5, 6, 7, 16, 18, 28, 35, 52, 55, 56, 81, 92, 95], true);
                $message = match ($error) {
                    5 => 'Could not resolve the proxy hostname through DNS.',
                    6 => 'Could not resolve the Bot API hostname through the selected DNS.',
                    7 => 'Could not connect to the Bot API or proxy.',
                    28 => 'The connection or upload timed out.',
                    35, 51, 58, 60, 77, 83, 90, 91 => 'TLS error: check the server certificate, proxy certificate, and trusted hosting CAs. TLS verification remains enabled.',
                    97 => 'Proxy rejected the connection: check its type, address, and credentials.',
                    default => 'cURL network error (' . $error . '). Check connectivity, DNS, and proxy settings.',
                };
                if ($file !== null && $retry) {
                    $message .= ' Delivery may have completed before the connection broke; retrying can create a duplicate.';
                }
                throw new TelegramException($message, $retry, $retry ? 60 : 0, $error);
            }
            if ($status >= 300 && $status < 400) {
                throw new TelegramException('The Bot API returned a redirect. Enter the final address; redirects are disabled to protect the token.');
            }
            $decoded = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);
            if (!is_array($decoded)) {
                $retry = $status >= 500 || $status === 429 || $status === 408;
                throw new TelegramException('The Bot API returned a non-JSON response (HTTP ' . $status . '). Check the server address and proxy.', $retry, $retry ? max(60, $httpRetryAfter) : 0, $status);
            }
            return $this->decode_envelope($decoded, $status, $httpRetryAfter);
        } finally {
            unset($handle);
        }
    }

    private function decode_envelope(array $body, int $httpStatus, int $httpRetryAfter = 0): array
    {
        if (($body['ok'] ?? null) === true && $httpStatus >= 200 && $httpStatus < 300 && isset($body['result']) && is_array($body['result'])) {
            return $body['result'];
        }
        $code = (int) ($body['error_code'] ?? $httpStatus);
        $parameters = is_array($body['parameters'] ?? null) ? $body['parameters'] : [];
        if (isset($parameters['migrate_to_chat_id'])) {
            $id = preg_replace('/[^0-9-]/', '', (string) $parameters['migrate_to_chat_id']);
            throw new TelegramException('The group was migrated to a supergroup. Verify and save the new Chat ID: ' . $id . '. The destination was not changed automatically.', false, 0, $code);
        }
        $retry = $code === 429 || $httpStatus === 429 || $code === 408 || $httpStatus === 408 || $code >= 500 || $httpStatus >= 500;
        $delay = $retry ? max(1, min(604800, (int) ($parameters['retry_after'] ?? ($httpRetryAfter ?: 60)))) : 0;
        $hint = match ($code) {
            401 => 'Token is invalid. Replace it through @BotFather.',
            403 => 'The bot is blocked or cannot send messages to this chat. Press Start in the bot chat or grant permissions in the group/channel.',
            404 => 'The Bot API or bot was not found. Check the token and server address.',
            407 => 'Proxy rejected the credentials (HTTP 407). Check the proxy username and password.',
            409 => 'Another update consumer is using this bot. Create a dedicated bot or enter the Chat ID manually.',
            413 => 'The Bot API or reverse proxy rejected the file size. Reduce the part size or raise the limit on your server.',
            429 => 'Telegram rate limit reached. Retry is allowed in ' . $delay . ' seconds.',
            default => 'Bot API error (' . $code . ').',
        };
        $description = $this->redact(self::clip((string) ($body['description'] ?? ''), 350));
        if ($description !== '' && $code !== 429) {
            $hint .= ' ' . $description;
        }
        throw new TelegramException($hint, $retry, $delay, $code);
    }

    private function network_description(): string
    {
        $parts = [($this->settings['api_mode'] ?? 'cloud') === 'local' ? 'Local Bot API, parts up to 1900 MiB.' : 'Telegram Cloud Bot API, parts up to 45 MiB.'];
        $proxy = trim((string) ($this->settings['proxy_url'] ?? ''));
        if ($proxy !== '') {
            $scheme = strtolower((string) parse_url($proxy, PHP_URL_SCHEME));
            $parts[] = 'Proxy ' . strtoupper($scheme) . '.';
            if (in_array($scheme, ['http', 'https', 'socks5h'], true)) {
                $parts[] = 'The proxy resolves the Bot API hostname; local DNS settings do not control the proxy DNS.';
            }
        } else {
            $parts[] = 'Direct connection.';
        }
        if (!empty($this->settings['dns_servers'])) {
            $parts[] = 'The configured DNS servers are applied to the local c-ares resolver.';
        } elseif (!empty($this->settings['doh_url'])) {
            $parts[] = 'DNS-over-HTTPS is enabled; the initial lookup of the DoH hostname uses system DNS.';
        } else {
            $parts[] = 'System DNS is used for local queries.';
        }
        $parts[] = 'HTTPS certificates are verified. Redirects are disabled.';
        if (str_starts_with($this->endpoint(), 'http://')) {
            $parts[] = 'Local HTTP is selected: the connection to the Bot API is unencrypted; use a trusted network.';
        }
        return implode(' ', $parts);
    }

    private function redact(string $text): string
    {
        foreach ([$this->token, $this->proxyPassword] as $secret) {
            if ($secret !== '') {
                $text = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '[redacted]', $text);
            }
        }
        $text = preg_replace('~(?:https?|socks5h?)://[^\s<>]+~i', '[address redacted]', $text) ?? 'Connection error.';
        $text = preg_replace('/[0-9]{5,20}:[A-Za-z0-9_-]{20,}/', '[token redacted]', $text) ?? 'Connection error.';
        return Support::redact($text);
    }

    private static function clip(string $text, int $length): string
    {
        if (preg_match('/^.{0,' . $length . '}/us', $text, $matches)) {
            return $matches[0];
        }
        return '';
    }
}

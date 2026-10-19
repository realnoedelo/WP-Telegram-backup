# Networking, proxies, and DNS

The connection settings apply to every outgoing request made by the plugin: bot authentication, chat and webhook checks, chat discovery, messages, and backup part uploads. All use the same PHP cURL transport. WordPress network settings, the administrator's browser settings, and process environment variables do not override the configuration selected in the plugin.

## Direct connection

Leave the proxy, DNS, and DoH fields empty. The WordPress server connects to the Cloud Bot API over HTTPS using its hosting environment's normal DNS. Connectivity is tested from the WordPress server, not from the administrator's computer.

## Proxies

| Type | Example address | Where the Bot API hostname is resolved |
| --- | --- | --- |
| HTTP | `http://proxy.example.org:3128` | On the proxy |
| HTTPS | `https://proxy.example.org:8443` | On the proxy; the connection to the proxy itself also uses TLS |
| SOCKS5 | `socks5://192.0.2.10:1080` | On the WordPress server |
| SOCKS5H | `socks5h://proxy.example.org:1080` | On the proxy |

These are example addresses, not working free services. Enter the username and password in their separate fields. URLs such as `http://user:password@host:port` are rejected. The proxy password and bot token are stored encrypted using WordPress keys. Their values are not returned to the interface, logs, or diagnostic errors.

An HTTP proxy connects to an HTTPS Bot API through cURL's normal tunnel, with the Bot API certificate verified. SOCKS5 does not encrypt its own connection to the proxy. The HTTPS request still uses TLS, but SOCKS5 credentials may be visible on an untrusted network between WordPress and the proxy. Use a trusted route.

The proxy also applies to a Local Bot API server. If that server is at `127.0.0.1`, a remote proxy is usually the wrong configuration: to the proxy, `127.0.0.1` means the proxy itself, not the WordPress host. Remove the proxy or use an address reachable from it.

## Custom DNS servers

Example syntax:

```text
1.1.1.1,8.8.8.8
192.168.1.53:5353
[2606:4700:4700::1111]:53
```

The plugin accepts comma-separated IP addresses with optional ports. Enclose an IPv6 address in square brackets when specifying its port. This configures the cURL resolver; it does not change DNS for the operating system or the rest of WordPress.

PHP cURL must provide `CURLOPT_DNS_SERVERS` and use the c-ares resolver backend. The presence of “AsynchDNS” alone is insufficient: a different asynchronous backend may not support a custom DNS server list. If the feature is unavailable or cURL cannot apply it, the request stops with an explicit error. The plugin does not silently substitute system DNS. See the [libcurl documentation for the c-ares requirement](https://curl.se/libcurl/c/CURLOPT_DNS_SERVERS.html).

With an HTTP, HTTPS, or SOCKS5H proxy, the proxy resolves the destination hostname. Your DNS list only controls local resolution, such as looking up the proxy's own hostname; it does not change the proxy's DNS. The connection diagnostics explicitly explain this distinction. To use your chosen DNS servers for the Bot API hostname, use a direct connection or SOCKS5 with local resolution. If DNS access on your hosting server is restricted, SOCKS5H with a numeric proxy IP can pass the Bot API hostname to the proxy.

## DNS over HTTPS

Instead of a DNS server list, you can specify the HTTPS URL of a compatible DoH server, such as `https://cloudflare-dns.com/dns-query`. The DoH provider sees your DNS queries, so choose a service you trust. A DNS server list and DoH cannot be configured at the same time.

Support depends on the installed PHP cURL build. The plugin requires DoH options with explicit certificate and hostname verification; older builds are rejected. The DoH URL must use HTTPS and contain no username, password, query string, or fragment.

**DoH combined with a proxy is rejected.** In libcurl, an internal DoH request does not inherit the parent connection's proxy settings and could therefore go out directly. For proxy connections, use SOCKS5H or ordinary DNS servers with SOCKS5. The initial lookup of the DoH server's own hostname uses system DNS; enabling DoH does not guarantee that all system DNS queries are eliminated. These limitations are described in the [official CURLOPT_DOH_URL documentation](https://curl.se/libcurl/c/CURLOPT_DOH_URL.html).

## TLS and the server address

- Cloud mode uses the fixed endpoint `https://api.telegram.org`.
- In local mode, enter your own server's base URL. HTTPS is allowed; HTTP is limited to localhost, loopback, private IPv4 addresses, or IPv6 ULA addresses. Public HTTP and link-local addresses are rejected.
- The transport adds the token to the request path internally. Do not include it in the server address field.
- Server certificates and hostnames are verified. There is no “disable SSL verification” option. If you use your own certificate authority, install its CA certificate on the server.
- Redirects are not followed, preventing a 301/302 response from sending the token to another address. Enter the final URL directly.
- Secrets are not placed in GET parameters, but the Bot API protocol includes the token in the request path. On your own reverse proxy, disable logging of these paths or mask tokens in access and error logs. The archive password is never sent to Telegram.

## File limits and Premium

A proxy or custom DNS changes the network route, not the Bot API upload limit. The [Cloud Bot API accepts documents up to 50 MB](https://core.telegram.org/bots/api#senddocument); the plugin uses a safe maximum of 45 MiB per part. Your own [Local Bot API server supports uploads up to 2000 MB](https://core.telegram.org/bots/api#using-a-local-bot-api-server), with a plugin maximum of 1900 MiB per part. [Telegram Premium's 4 GB allowance](https://telegram.org/faq_premium) applies to personal account uploads and does not increase either bot limit.

The part size controls each uploaded document. The maximum backup size controls the complete backup across all parts. The local storage quota controls total space used by backups on the WordPress host; physical free space must also be sufficient. These are independent limits. See the [Telegram setup guide](TELEGRAM.md#4-file-size-two-api-modes) for examples and Local Bot API setup.

## Troubleshooting

| Result | What to check |
| --- | --- |
| Invalid token / 401 | Obtain the current token from BotFather and remove accidental spaces |
| Chat not found / 400 | Press Start in a private chat; check the negative Chat ID and the bot's group membership |
| No permission / 403 | Check that the bot is not blocked and may send documents or publish posts |
| Topic unavailable | The Thread ID must belong to the selected group, and the topic must be open and accessible to the bot |
| Conflict / 409 | Another webhook or update consumer is using the bot; use a dedicated bot |
| Rate limit / 429 | The queue waits for `retry_after`; repeatedly pressing the test button is unnecessary |
| 5xx error | The Bot API or proxy is temporarily unavailable; the queue retries after a delay |
| File too large / 413 | Check the API mode, part size, and incoming request limit on the Local Bot API reverse proxy |
| cURL 5/6 | The selected DNS cannot resolve the proxy or Bot API hostname |
| cURL 7 | Check the address, port, firewall, and connectivity from the WordPress server |
| cURL 28 | Check network speed, hosting timeouts, and part size |
| TLS/CA | Check the certificate chain, hosting CA store, and HTTPS proxy certificate |
| c-ares/DoH unsupported | Ask the host to update PHP/cURL or choose another supported network mode |

The queue schedules network retries; there is no hidden retry loop within a transport request. Permanent configuration errors are not retried indefinitely. An uncertain network outcome can result in the same document being delivered twice. Compare the job ID and part filename.

## Testing without a real bot

Developers can use the `parcel_telegram_transport` filter. Its arguments are the initial `null` value, method name, parameters, local file path or `null`, and non-secret settings. If the filter returns a Bot API response array (`ok`, `result`, or `error_code`), that response replaces the network request. Errors and `retry_after` are processed in the same way as a real response. The bot token, archive password, and proxy password are not passed to the filter. Use it only in a test environment: it replaces actual proof of delivery.

A mocked response does not prove Telegram connectivity from a particular host. After installation, run the real connection check and send a test document using your own bot.

From the plugin source directory:

```sh
php tests/telegram-test.php
php tests/telegram-test.php --http
```

The first command checks responses and errors using the mocked transport. With `--http`, temporary servers listen only on `127.0.0.1` to test real 20 MiB multipart uploads, receiver-side SHA-256, bounded memory use, an HTTP proxy with Basic authentication, and SOCKS5/SOCKS5H with username/password authentication. The SOCKS fixture confirms that SOCKS5 receives a resolved IP address while SOCKS5H receives the destination hostname. Tests also cover rejected credentials, blocked redirects, HTTP 429/503, and response size limits.

These tests require PHP cURL, the ability to start a child PHP process, and permission to listen on loopback ports. No real tokens, Telegram servers, or external proxies are used. An HTTPS proxy with a certificate signed by a trusted CA and real DNS/DoH providers are not part of this test.

# Version 1.0.0 verification

Archive and restore checks were performed on October 19, 2026, in an isolated local environment: **WordPress 7.1.3, PHP 8.5.11 (64-bit), MariaDB 13.0.2**, and Chromium. No real Telegram chats were used in these checks.

Installation was also verified on a separate staging site running **WordPress 7.1.2 and PHP 8.4.26**. Checks covered REST API permissions, the **Settings → TG Backup** menu, persistent private storage, cron installation by the plugin, repeated installation without duplicates, removal on deactivation, and reinstallation on activation. After the temporary server-side task was removed, a new background execution of the plugin's own worker was confirmed.

## Completed checks

| Area | Coverage | Result |
| --- | --- | --- |
| Full WordPress backup | 3,808 source files, 13 parts, 47,725,456 bytes of encrypted archive data | Full cycle reached confirmed completion using the test transport |
| Website restoration | Decryption, SHA-256 comparison, SQL import into a new database, and the restored WordPress site running on another port | Homepage showed the original title and published post |
| SQL | NULL, Unicode, binary/BLOB, JSON, BIT, BIT64, unsigned BIGINT, DECIMAL, latin1/utf8mb4, and TIMESTAMP with a different time zone | Values and binary representations matched; Telegram Backup settings and secrets were absent from the dump |
| Large source file | A real streaming round trip of a 2,147,495,993-byte file, including data on both sides of the 2 GiB boundary | Restored size and SHA-256 matched |
| Archive and restore | 15 scenarios: encryption, Unicode, empty files, multipart archives, wrong passwords, corruption/truncation/trailing data, missing parts, unsafe paths, checkpoint recovery, and size limits | Passed |
| Telegram transport | 98 checks, including real local 20 MiB multipart uploads, HTTP proxies, SOCKS5/SOCKS5H, proxy authentication, IP/hostname handling in SOCKS, 429/503, and blocked redirects | Passed without contacting real Telegram servers |
| Storage and settings | 57 checks: count/age retention, quotas, disk reserve, locks, preservation of active/unsent data, foreign directories, AES-GCM secrets, validation, scheduling, and prevention of duplicate scheduled jobs | Passed; test fixtures did not change the test site's actual settings, cron configuration, or job history |
| Queue | A 429 response, delayed retry, resumption, and acknowledgements for parts, manifest, restore utility, and completion | Reached `completed` after an actual transition through `retry` |
| Cron | Execution of a registered event through WordPress Cron and separate tracking of manual worker invocations | Passed; a manual invocation does not masquerade as a cron heartbeat |
| Automatic cron setup | 39 isolated crontab checks: preservation of unrelated jobs, repeat installation, removal, permissions, root rejection, damaged blocks, quoting, concurrent changes, Vixie headers, and timeouts | Passed using a fake executable; the test machine's system crontab was not changed |
| Permissions | Anonymous access rejected; administrators without a nonce rejected; valid nonce accepted | Passed |
| Interface | Five screens at 1440 px and 390 px; 11 settings/validation/consent/chat-discovery/test-delivery scenarios; archive/manifest/restore-utility downloads; logs | No JavaScript errors or horizontal overflow |
| Maintenance | Restoring scheduled events, cleanup with no eligible copies, and preserving unrelated files | Passed |
| Static checks | Syntax checks for all PHP files and JavaScript | Passed |

Peak memory use during the full integration run was **44 MiB**. PHP files changed during subsequent development were compared against the corresponding backup snapshot. The final SQL changes were separately re-imported and checked for character encoding and timestamp preservation.

## Reproducing the standalone tests

From the plugin source directory:

```sh
php tests/archive.php
php tests/archive.php --large
php tests/telegram-test.php
php tests/telegram-test.php --http
php tests/system-cron.php
```

`--large` needs several gigabytes of free disk space for the source and restored files; large temporary files are removed after a successful test. `--http` starts only local test servers and exercises real cURL requests. All tokens in the fixtures are fictional.

Storage tests require a separate **test WordPress installation**:

```sh
PARCEL_TEST=1 php tests/storage-wordpress-test.php --wp=/path/to/test-wordpress --scratch=/private/test-work
```

The suite uses its own temporary storage directory and settings overridden only for the lifetime of the process. Do not run test scripts inside a production WordPress installation. [GitHub Actions on PHP 8.1–8.5](https://github.com/realnoedelo/WP-Telegram-backup/actions/workflows/tests.yml) successfully checked syntax, archives, the transport with local HTTP/SOCKS uploads, automatic cron setup, and ZIP packaging.

## What these checks do not establish

- **Real Telegram delivery:** no bot token or destination chat was supplied. After installation, use **Send test file** to perform that final check.
- A 1900 MiB upload to a real self-hosted Bot API server. Streaming uploads and size limits are implemented, and the boundaries were tested; no running server was provided.
- Real external DNS servers, DoH, or HTTPS proxies on a particular hosting network. The local cURL build has no c-ares, so the tests verified that a custom DNS list is explicitly rejected rather than ignored. HTTP and SOCKS proxies were tested through real local connections.
- MySQL 8, other WordPress/PHP versions, and different hosting control panels were not deployed locally. Syntax and API usage target the stated minimum versions.
- Multisite, external directories, a consistent live database snapshot, and one-click restoration are outside version 1.0's scope.
- No independent security audit or WordPress.org review has been performed.

The English release was rechecked after translation: all 21 PHP files retained identical non-text tokens; archive (14), transport (98), storage/settings (57), and system cron (39) checks passed again. The English interface passed five-tab browser checks at 1440 px and 390 px, plus mocked action and cron-control scenarios, with no JavaScript errors or horizontal overflow. Unicode filenames remain in archive test fixtures intentionally.

The interface uses standard WordPress administration elements: tabs, tables, forms, notices, and buttons. The public plugin name is **Telegram Backup**.

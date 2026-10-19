# TG Backup

[![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-2271b1)](https://wordpress.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![GPL v3 or later](https://img.shields.io/badge/License-GPL_v3_or_later-blue)](LICENSE)
[![Plugin checks](https://github.com/realnoedelo/WP-Telegram-backup/actions/workflows/tests.yml/badge.svg)](https://github.com/realnoedelo/WP-Telegram-backup/actions/workflows/tests.yml)

**Back up WordPress to your own Telegram bot. Restore from downloaded archives, even if the original site is gone.**

TG Backup compresses and encrypts data on your server, uploads the archive in parts, and shows the state of the queue, cron, and local storage. Free and open source, with no account with the plugin authors, paid plugin subscription, or telemetry. You need your own Telegram account and bot.

[**Download ZIP v1.0.1**](https://github.com/realnoedelo/WP-Telegram-backup/releases/download/v1.0.1/tg-backup-1.0.1.zip) · [Latest release](https://github.com/realnoedelo/WP-Telegram-backup/releases/latest) · [Recovery guide](docs/RESTORE.md) · [Report an issue](https://github.com/realnoedelo/WP-Telegram-backup/issues)

## Two workflows

| Task | How it works |
| --- | --- |
| **Back up a site** | WordPress → compression and encryption → archive parts → your Telegram chat |
| **Restore a site** | Downloaded parts + password → `restore.php` → verified files and SQL → a separate site |

Once you have downloaded the complete archive, recovery needs neither a running WordPress site nor access to the bot. The plugin does not automatically retrieve old backups from chat history or overwrite a live site with one click.

## Quick start

1. **Install the plugin.** Download the ZIP from a [release](https://github.com/realnoedelo/WP-Telegram-backup/releases/latest), upload it through **Plugins → Add New → Upload Plugin**, and activate it. Open **Settings → TG Backup**.
2. **Create your bot.** Open [@BotFather](https://t.me/BotFather), send `/newbot`, and get a token. Open the new bot and press **Start**.
3. **Connect a chat.** On the connection tab, save the token and consent to data transfer. Send the bot a message, use chat discovery, and select the recipient. You can also enter the chat ID and topic ID manually.
4. **Set an archive password.** Use at least 12 characters, preferably a long random password from a password manager. **Store it outside the site and separately from the Telegram backups.** The original password is required to decrypt an archive.
5. **Test the connection.** Configure a proxy, DNS, or DoH if needed. Save the settings, run the connection check, and send a test file.
6. **Create your first backup.** Check the estimated size and free disk space, start the backup, and wait for delivery confirmation. Test recovery. New installations default to a daily backup at **03:00 in the WordPress timezone**; updates preserve an existing saved schedule.

[Detailed bot setup guide →](docs/TELEGRAM.md)

## Updating from 1.0.0

Version 1.0.1 uses the **TG Backup** name and the `tg-backup` plugin folder. The repository URL stays the same.

1. Let active jobs finish. Keep the archive password and a copy of the private storage outside the site. Leave `TELEGRAM_BACKUP_STORAGE_DIR` and its path unchanged.
2. Deactivate the old plugin in the `telegram-backup` folder. **Do not use WordPress Delete**: its uninstall handler can remove settings shared with the new version. Do not activate both versions together.
3. Install `tg-backup-1.0.1.zip` and activate **TG Backup**. Existing saved settings and local backup history are reused; the archive format remains version 1.
4. Check the connection, storage, next scheduled run, and cron heartbeat. If a system cron entry still points at the old plugin folder, update it from **Overview → Cron setup**.
5. After verifying the new version, remove only the inactive old `wp-content/plugins/telegram-backup` source directory through your file manager or deployment tool. Keep the private backup directory.

## Features

| Feature | Included |
| --- | --- |
| Backup scope | Full site, database only, or files only; exclusions by relative path |
| Queue | Saved progress, one worker at a time, delayed retries for temporary errors, cancellation, and logs |
| Telegram status | One progress message per job, updated in place; archive parts, manifest and recovery tool are sent as separate silent documents |
| Scheduling | Manual, hourly, daily, or weekly; separate cron and manual-run status |
| Storage controls | Total local quota, maximum backup size, free-space reserve, and retention by count and age |
| Network | HTTP/HTTPS/SOCKS5/SOCKS5H proxies, custom DNS or DoH; shared settings for checks and uploads |
| Recovery | Standalone PHP tool, archive integrity checks, and extraction into a new empty directory |
| Administration | Native WordPress layout, part downloads, log viewer, and WP-CLI commands |

## File sizes and disk space

| Upload mode | Telegram limit | Plugin part size |
| --- | --- | --- |
| Standard cloud Bot API | Up to **50 MB** per document | **40 MiB** by default; maximum 45 MiB |
| Your own Telegram Bot API server in `--local` mode | Up to **2000 MB** per document | Maximum **1900 MiB**, leaving room below the limit |

**Telegram Premium does not increase Bot API limits.** Part size limits each uploaded file; it is separate from the maximum backup size and total local disk quota. Larger backups are sent as multiple files. Source files larger than 2 GB are read in small blocks. The 2 GB upload mode requires a separately installed official Bot API server; changing the plugin setting does not install one. See Telegram's [file upload documentation](https://core.telegram.org/bots/api#sending-files) and [Local Bot API documentation](https://core.telegram.org/bots/api#using-a-local-bot-api-server).

**Your WordPress host needs space for the entire archive, not just one part.** If you run your own Bot API server, account for disk space on that server too.

| Setting | Default |
| --- | --- |
| Total local quota | 10 GiB |
| Maximum size of one backup | 5 GiB |
| Free disk-space reserve | 512 MiB |
| Delivered backups retained locally | Latest 3, no older than 14 days |

The quota includes archives, working data, and queue state. Active and undelivered backups are not deleted automatically. Local cleanup **does not delete Telegram messages or files**. 1 MiB = 1,048,576 bytes.

For durable storage, set a private directory **outside the public web root** in `wp-config.php`:

```php
define('TELEGRAM_BACKUP_STORAGE_DIR', '/path/outside/webroot/backups');
```

Replace the example with your own path. The directory must belong to the PHP user; directory permissions are `0700`, and file permissions are `0600`. Do not share the directory between sites. Without this setting, the plugin uses a system temporary directory that the host may clean. Persistent storage is required for automatic system cron setup.

## Telegram progress

Each job attempts to create one status message, then edits it as work progresses and finishes. Progress is an overall estimate. Archive parts, the manifest and the recovery tool remain separate document messages, sent silently. “Silent” means without sound: Telegram clients may still show notifications. Mute the destination chat in Telegram if you want no per-file notifications.

In channels and supergroups, the status can link to up to 10 parts plus the manifest and recovery tool. **Private bot chats have no native public message links**: find the documents by their job ID instead. Status updates are best effort; a failed edit does not stop backup delivery or create a replacement announcement. Check the job state in WordPress and test recovery.

## Encryption and security

| Mechanism | What it provides |
| --- | --- |
| **AES-256-GCM** | Archive contents are encrypted before being written or uploaded; altered encrypted records fail verification during recovery |
| Streaming compression | Data is compressed and encrypted in small blocks without loading whole files into memory |
| Archive verification | The recovery tool checks part order, checksums, and the required completion record |
| Stored credentials | The bot token, archive password, and proxy password are encrypted with a key derived from WordPress salts |
| Permissions and nonces | Management and downloads require `manage_options` and a valid WordPress REST nonce |
| Private storage | Working files stay outside the public web root; source symlinks are not archived |
| No telemetry | No usage statistics are sent to the developer; Telegram and your chosen network services handle the transfers you configure |

Content protection comes from **the plugin's encryption before upload**, not Telegram end-to-end encryption. The password is not sent to the bot or included in the recovery package. **Job captions and status messages, `manifest.json`, and the recovery script are sent unencrypted.** They expose job IDs, dates, backup type, counts, part names, sizes, hashes, and error summaries, but not the archive password or backed-up file contents. Telegram and your selected infrastructure also see transfer metadata.

The WordPress process can access stored credentials: encrypting settings does not protect against a fully compromised site or server. Changing WordPress salts requires you to enter saved credentials again. The archive format is open, but it has not undergone an independent cryptographic audit. [Read the security model →](SECURITY.md)

## Restore a downloaded backup

Download **every** `part-*.pvlt` file from one backup, plus `manifest.json` and `restore.php`. Do not mix jobs or rename parts. The recovery tool is also available at [`tools/restore.php`](tools/restore.php) and on the plugin's recovery tab.

```sh
php restore.php --input=/path/to/backup --output=/path/to/new-empty-directory
```

On Linux/macOS, the tool prompts for the password without displaying it. Automation can use `--password-stdin` or the `TELEGRAM_BACKUP_PASSWORD` environment variable; do not pass a password as a command-line argument. The admin download is named `tg-backup-restore.php`; substitute that name for `restore.php` if you use it.

A verified recovery produces `files/` and/or `database.sql`, depending on the backup scope, plus `PARCEL-VERIFIED.json`. A `PARCEL-INCOMPLETE.txt` marker means recovery did not finish: do not deploy that output.

The tool **does not import SQL or replace a live site**. Start with a new empty database and a separate test site, check the files and database connection in `wp-config.php`, and verify WordPress works. The SQL contains `DROP TABLE IF EXISTS`: do not import it over a live database without a separate backup and an explicit decision to replace those tables. [Full recovery guide →](docs/RESTORE.md)

## Cron setup, when the host allows it

Activation registers WP-Cron events, which normally run on site visits. If `DISABLE_WP_CRON` is enabled, the plugin also **attempts to install its own system cron entry** to run once per minute.

Automatic setup requires a Unix host, persistent storage, a suitable PHP CLI, and permission for the site's user to run commands and manage its crontab. Installation as `root` is refused. When a requirement is missing, the overview shows the reason and a control to retry setup. An existing entry with an outdated command can be updated; manual setup through the hosting panel remains available.

**Installed does not mean running.** The last background-run timestamp confirms execution; merely creating an entry or clicking a manual-run button does not. The plugin manages only its own marked crontab block, attempts to remove it on deactivation, and does not call hosting control-panel APIs.

```sh
wp tg-backup cron status
wp tg-backup cron install
wp tg-backup cron remove
```

Other WP-CLI commands:

```sh
wp tg-backup status
wp tg-backup check --type=full
wp tg-backup backup --type=full
wp tg-backup run
wp tg-backup cleanup
```

`run` executes one queue step. A manual CLI run is not reported as a cron heartbeat. Run commands as the site's operating-system user.

## Requirements and limits

- **WordPress 6.5+**, **PHP 8.1+ 64-bit**, OpenSSL, zlib, cURL, and sufficient free disk space. The host does not need Composer or Node.js.
- Single-site WordPress is supported. Multisite, external directories, symlinks, web-server configuration, and hosting cron configuration are outside the backup scope.
- Database export includes tables with the current WordPress prefix. Database triggers, routines, and events are not exported.
- Backing up a running site does not produce a consistent transactional snapshot. For critical orders or accounting data, use a maintenance window or an additional database-native snapshot.
- If Telegram accepts a file but its response is lost, a retry may create a duplicate. Job IDs, part names, and hashes help identify it.
- Keep the password and another independent backup outside both this site and Telegram. Test recovery regularly.

## 1.0.1 changes

- Renamed the plugin and installable folder to **TG Backup** / `tg-backup`, preserving existing settings, storage, and version 1 archives.
- Routed Bot API requests through the WordPress HTTP API while retaining streamed uploads and proxy/DNS controls.
- Added one editable Telegram status message per job and silent document uploads. Status messages link to uploaded files in supported channels and supergroups.
- Clarified data-transfer disclosures and upgrade instructions.

See [Testing](docs/TESTING.md) for test procedures and recorded limits. GitHub distribution does not imply WordPress.org approval.

## Documentation and contributions

[Bot setup](docs/TELEGRAM.md) · [Proxy and DNS](docs/NETWORK.md) · [Recovery](docs/RESTORE.md) · [Archive format](docs/FORMAT.md) · [Testing](docs/TESTING.md) · [Security](SECURITY.md) · [Contributing](CONTRIBUTING.md)

Use [Issues](https://github.com/realnoedelo/WP-Telegram-backup/issues) for bugs and suggestions. Do not post tokens, passwords, or backups containing personal data. Report vulnerabilities according to [SECURITY.md](SECURITY.md).

Licensed under [GNU GPL v3 or later](LICENSE) (`GPL-3.0-or-later`).

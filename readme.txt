=== TG Backup ===
Tags: backup, telegram, encrypted backup, restore, schedule
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Encrypted WordPress backups to your own Telegram bot, with resumable jobs, schedules, storage limits and network diagnostics.

== Description ==
TG Backup is a free, self-hosted backup plugin. No account with the plugin authors, paid plugin plan, analytics or advertising is required. You need your own Telegram account and bot; Telegram is the external delivery service. You create a bot with @BotFather and keep control of its credentials and recovery password.

* Files, database or a full single-site backup.
* Password-encrypted streaming archives; large source files are processed in small blocks.
* Resumable queue, bounded retries, cancellation, logs and distinct worker/cron heartbeat.
* Manual, hourly, daily and weekly schedules in the site's timezone.
* Local byte quota, maximum backup size, free-space reserve, age and count retention.
* One edited Telegram status message per job; separate archive, manifest and recovery documents are sent silently.
* Cloud Bot API multipart delivery; optional self-hosted Bot API endpoint for files up to 2000 MB.
* HTTP/HTTPS/SOCKS5 proxy, custom DNS where supported by cURL, and DNS-over-HTTPS.
* Bot/chat/network diagnostics and an explicit test document.
* Standalone command-line recovery utility, authenticated archive records and SHA-256 part manifest.

The interface and documentation are in English. The plugin uses the native WordPress admin layout and the tg-backup text domain for translatable PHP messages.

Backups contain sensitive site data. Encryption is required. Keep the recovery password outside WordPress: losing it means losing access to encrypted backups. Telegram bot conversations are not end-to-end encrypted; archive encryption happens on your server before upload.

This release supports ordinary single-site WordPress installations with wp-content inside the WordPress root. It is not a migration wizard or a transactional database snapshot service. On a busy shop, use a maintenance window or a database-native consistent snapshot. Symbolic links and external directories are not followed.

= External services =
Only after the administrator saves connection consent, TG Backup contacts the administrator's configured Telegram Bot API endpoint (default https://api.telegram.org). It sends the bot token, chat/topic identifiers, requested test documents and encrypted archive parts. Job captions and status messages, manifest.json, and the standalone recovery script are sent unencrypted. These include job IDs, dates, backup type, counts, part names, sizes, SHA-256 hashes and error summaries; they do not include the archive password or decrypted backup contents. A selected proxy carries these requests; a custom DNS resolver or DoH service receives the relevant DNS queries. The selected services may observe transfer metadata. TG Backup does not send telemetry to its authors.

Telegram service and terms: https://telegram.org/tos
Telegram privacy policy: https://telegram.org/privacy
Telegram Bot API documentation: https://core.telegram.org/bots/api

== Installation ==
1. Upload the tg-backup ZIP in Plugins > Add New > Upload Plugin, then activate.
2. Open Settings > TG Backup. Create a bot with @BotFather using /newbot.
3. Open your new bot and press Start. Paste its token, save, and find/select your chat, or enter Chat ID manually.
4. Create a recovery password of at least 12 characters and store it separately.
5. Review the network settings if your host needs a proxy or custom DNS.
6. Save consent, run the connection and test-file checks, then preflight and start your first backup.
7. Confirm all parts arrive, perform a test recovery, and review the schedule. New installations default to daily backups at 03:00 in the WordPress timezone. Existing saved schedules are unchanged.

The host needs PHP 8.1+ (64-bit), OpenSSL, zlib, cURL, writable private storage and enough free disk space. Set TELEGRAM_BACKUP_STORAGE_DIR in wp-config.php to a durable private directory outside the public web root. Without it, TG Backup uses a private system temporary directory, which the host may clean.

= Updating from 1.0.0 =
Wait for active jobs to finish and keep the archive password and private backup directory. Leave TELEGRAM_BACKUP_STORAGE_DIR unchanged. Deactivate the old plugin in the telegram-backup folder; do not use WordPress Delete, since its uninstall handler can remove shared settings. Upload tg-backup-1.0.1.zip and activate TG Backup. Do not activate both versions together. Check the retained connection settings, history, storage and cron heartbeat. Update a stale system cron entry through Overview > Cron setup. After verification, remove only the inactive old plugin source folder using your file manager; keep private storage.

== Frequently Asked Questions ==

= Why are cloud uploads split into small parts? =
The normal Bot API allows documents up to 50 MB. Telegram Premium does not increase Bot API limits. Part size is separate from maximum backup size and total local disk quota. TG Backup defaults to 40 MiB parts. The separate self-hosted Telegram Bot API server allows up to 2000 MB; TG Backup caps that mode at 1900 MiB to stay below the limit. Installing the plugin alone does not set up that server.

= Does one status message replace the archive documents? =
No. Each job attempts one status message and edits it with estimated progress and completion. Documents still appear separately but are sent silently. Silent means without sound, not without notifications: Telegram clients may still show them. Mute the destination chat in Telegram to stop per-file notifications. Supported channels and supergroups get links to up to 10 parts plus the manifest and recovery tool; private bot chats have no native public message links, so use the job ID to find files. Status updates are best effort and do not block archive delivery.

= Does retention delete Telegram messages? =
No. Retention only removes completed local backups. Delivered Telegram files remain in your chat. Failed or unfinished local jobs are not automatically removed to make space.

= Can I restore without the original WordPress site? =
Yes. Download every .pvlt part, manifest.json and tools/restore.php. Run the recovery utility with PHP on a computer or server, provide the password and restore into a new directory. Import database.sql and copy the restored files following docs/RESTORE.md. PHP OpenSSL and zlib are required, but WordPress is not.

= What if WP-Cron does not run? =
The plugin registers WP-Cron events automatically; WP-Cron normally runs on site visits. If DISABLE_WP_CRON is enabled, activation also attempts to install its own system cron. This requires persistent private storage, a suitable PHP CLI and permission to run commands and manage the site user's crontab. Overview provides retry/update/removal controls and shows why automatic setup is unavailable. An installed entry is separate from a confirmed cron heartbeat. The plugin manages only its own crontab entry and attempts to remove it on deactivation; it does not use hosting control-panel APIs. Manual setup remains available when automatic installation is blocked by the host. WP-CLI commands: `wp tg-backup cron status`, `wp tg-backup cron install`, `wp tg-backup cron remove`.

= Are custom DNS and proxy always supported? =
DNS server selection depends on the libcurl build. Unsupported options fail with an explanation; they are never silently ignored. A remote-resolving proxy controls destination DNS. See docs/NETWORK.md for the supported combinations.

= Is this published on WordPress.org? =
This archive is an independently installable source release. A directory listing and its contributor account must be registered and reviewed separately.

== Changelog ==
= 1.0.1 =
* Renamed the plugin and folder to TG Backup / tg-backup while preserving settings, storage and archive compatibility.
* Integrated Bot API requests with the WordPress HTTP API, retaining streaming uploads and proxy/DNS controls.
* Added one editable Telegram status message per job and silent document uploads, with file links where supported.
* Clarified transfer disclosures and the upgrade procedure.

= 1.0.0 =
Initial free source release: encrypted backup engine, Telegram transport, scheduling, quotas, network diagnostics and standalone recovery.

== Upgrade Notice ==
= 1.0.1 =
The plugin folder is now tg-backup. Deactivate the old version without using WordPress Delete, install the new ZIP, and verify settings, history and cron. Keep TELEGRAM_BACKUP_STORAGE_DIR unchanged.

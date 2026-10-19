# Restore a backup

[Back to README](../README.md)

Recovery **does not require a working WordPress installation or access to the bot**. You need every file from one backup downloaded from Telegram, the original archive password, and a computer or server running 64-bit PHP 8.1+ with OpenSSL and zlib. The tool processes small chunks in memory; the disk must have enough space for the extracted files.

## 1. Save one complete backup set

Use the job ID in message captions to find its parts: `part-00001.pvlt`, `part-00002.pvlt`, and so on. Download **all** parts into one directory. Add the `manifest.json` and `restore.php` sent by the same job. Do not mix files from different backups or rename the parts. The recovery tool is also available in the plugin's [`tools` directory](../tools/restore.php) and on the plugin's Recovery screen. The admin download is named `tg-backup-restore.php`; use that filename instead of `restore.php` in the command below if you downloaded it there.

WordPress marks a backup as delivered after Telegram confirms every part, the manifest and the recovery tool. Its separate status message is updated on a best-effort basis and may be missing or stale. Receiving a single part does not mean the set is complete; the recovery tool must verify the full archive.

## 2. Verify and decrypt

Run this command in a terminal, replacing the paths with your own:

```sh
php restore.php --input=/path/to/backup --output=/path/to/new-empty-directory
```

On a compatible Linux/macOS terminal, the tool prompts for the password without displaying it. For automation, pass the password through standard input with `--password-stdin` or use the `TELEGRAM_BACKUP_PASSWORD` environment variable. Do not pass a password as a command-line argument or put it in publicly accessible scripts. On Windows, use a securely supplied environment variable or standard input.

The output directory must be new or empty. The existing site is not overwritten. Extracted files use permissions `0600` and directories `0700`; recorded source permissions are not applied automatically. The tool attempts to restore source modification times, applying directory times after extraction so creating child files does not reset them. The tool verifies part order and integrity, AES-GCM authentication of every block, sizes, file checksum chains and the required completion record. Paths that escape the output directory and symbolic links are not allowed.

Successful verification creates **PARCEL-VERIFIED.json**. A failed extraction leaves **PARCEL-INCOMPLETE.txt**: do not deploy that output. An incorrect password, a missing part, extra data or a changed byte in an archive part causes verification to fail. Remove the failed output directory and retry into a new directory after resolving the cause.

## 3. Deploy the verified result

Depending on the backup type, recovery produces:

- `files/` — the contents of the WordPress root, including `wp-content` and `wp-config.php` if they were not excluded;
- `database.sql` — tables with the current WordPress prefix;
- `PARCEL-VERIFIED.json` — the backup verification report.

Start with a separate test site and a **new, empty database**. Import `database.sql` using your hosting tools, MariaDB/MySQL or WP-CLI. The SQL contains `DROP TABLE IF EXISTS`: never import it over a live database without a separate backup and a deliberate decision to replace the data.

Copy `files/` into the new site root, check file ownership and permissions, and configure the new database credentials in `wp-config.php`. Remove or update the old `TELEGRAM_BACKUP_STORAGE_DIR` if it was defined. If the URL or path changes, use a replacement tool that handles serialized values safely, such as `wp search-replace`, starting with `--dry-run`.

The 1.0.1 recovery tool reads the same version 1 archive format as 1.0.0. Product and plugin-folder renaming do not change old archive passwords.

TG Backup settings and secrets are excluded from the database dump. You will need to configure the bot connection, password and schedule again. The old password saved in your password manager will still open the old archives.

Before switching the domain, check the home page, administrator login, media, forms, essential plugins and, where applicable, the cart and checkout in test mode. Move the verified copy into production only after these checks.

## What the backup does not include

Web server configuration, mail, hosting cron configuration, external databases, files outside the WordPress root, symbolic links and explicitly excluded paths are not included. The dump contains tables with the current prefix; other applications in the same database are not exported. Database triggers, procedures and events are not included in this version. Views encountered in place of tables cause an explicit error rather than being silently skipped.

Backing up a running site does not produce a shared transactional snapshot of its database and files. Detected changes to table structure, row counts or files stop the backup, but existing rows may still be updated between batches. For critical orders or accounting data, use a maintenance window and an additional consistent database snapshot.

# Contributing

License: GPL-3.0-or-later. Keep the plugin completely functional without payment, tracking or an author-operated backend.

There is no frontend build step: edit `assets/admin.js` and `assets/admin.css` directly. PHP classes use the `ParcelBackups` namespace and the autoloader in `tg-backup.php`. Keep PHP 8.1 compatibility. The public product name is TG Backup; the plugin folder and text domain are `tg-backup`. Legacy settings, storage constants and archive identifiers remain for compatibility. Source and recovery paths must never require WordPress to decrypt a backup.

Before submitting a change:

1. Run `php tests/archive.php`, `php tests/telegram-test.php` and `php tests/system-cron.php` (uses a fake crontab; never edits your host's jobs).
2. Run `php tests/telegram-test.php --http` for real loopback multipart tests (PHP proc_open and a free localhost port needed).
3. On a disposable WordPress, run `PARCEL_TEST=1 php tests/storage-wordpress-test.php --wp=/path/to/test-wordpress --scratch=/private/test-work`.
4. Lint every PHP file and run `node --check assets/admin.js`.
5. For engine/storage/database changes, use a disposable WordPress and MySQL/MariaDB. Create a backup, interrupt/retry it, decrypt it, import SQL into a new database and compare actual restored values and files. Never run destructive fixtures in a production site.
6. Check the admin panel at desktop/mobile widths, keyboard navigation, expired nonce handling, disabled controls, plain permalinks and an empty first run.

Keep upgrade coverage for the previous `telegram-backup` folder: saved settings, storage ownership, cron paths and version 1 archives must continue to work. Never activate both plugin copies in the same test site.

Changes to the archive format require an explicit format version and recovery compatibility policy. Add corruption/truncation/reorder/path tests. Do not relax TLS checks, reveal secrets or mark a partial delivery completed. A manual worker invocation must not fake the cron heartbeat. No source file or SQL error may be silently skipped.

Build an installable archive with `python3 scripts/build-release.py`; it appears under `dist/` with a SHA-256 checksum. Do not commit archives, credentials or real backup data.

Development is hosted at [realnoedelo/WP-Telegram-backup](https://github.com/realnoedelo/WP-Telegram-backup). Use issues for reproducible bugs and pull requests for changes. Follow [SECURITY.md](SECURITY.md) for vulnerabilities.

For a WordPress.org release, add the actual WordPress.org contributor account to readme.txt, reserve the slug, then submit for directory review. GitHub distribution does not imply acceptance by the WordPress.org directory.

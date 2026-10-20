# WordPress directory review notes

These notes explain operations retained deliberately in TG Backup 1.0.3. Narrow source-line PHPCS annotations are engineering explanations, not exemptions granted by WordPress.org. An automated pass does not establish directory approval; the Plugin Review Team may request changes.

## Backup engine, private storage and system cron

The engine uses the existing `ParcelBackups` namespace and `parcel_` database/state identifiers for compatibility with existing installations and archives. The plugin's public name is TG Backup. No archive format change is made for these review fixes.

| Check or operation | Why it is retained and how it is constrained |
| --- | --- |
| `WordPress.WP.AlternativeFunctions`: `fopen`, `fread`, `fwrite`, `fclose` | Archives and source files are processed through bounded local streams. Crash recovery requires exact offsets, truncation and OS `flock` handles. WP_Filesystem's FTP/SSH/whole-file abstractions do not provide the same checkpoint and locking semantics. The CLI worker also writes diagnostic text to STDERR through a stream. |
| `WordPress.WP.AlternativeFunctions`: `chmod`, `mkdir`, `is_writable` | Private backup directories must have mode 0700 and files/locks mode 0600. The engine checks local filesystem access because the worker and PHP CLI must access the same storage. Storage must resolve outside the site/document root; an ownership marker prevents adopting a different site's directory. |
| `WordPress.WP.AlternativeFunctions`: `rename`, `unlink`, `rmdir` | A same-directory rename commits job state atomically. Cleanup checks canonical storage boundaries, job ownership and active-job state. Direct removal lets the caller detect failures and never delegates deletion to an unrelated remote filesystem backend. |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` / `NoCaching` | A backup must read the current table structure, row values, session charset and time zone. Cached application objects are insufficient. The CLI worker reads the current core cron lock directly when no external object cache is active, avoiding a stale option cache. |
| `WordPress.DB.DirectDatabaseQuery.SchemaChange` | The annotated statements are `SHOW CREATE TABLE` reads. They do not create, drop or alter a live table. DDL in the export is written into encrypted archive records for later manual recovery. |
| `WordPress.DB.PreparedSQL.NotPrepared` / `PluginCheck.Security.DirectDB.UnescapedDBParameter` | The annotated helper calls build variable-length column/order lists from fixed SQL templates, then use `$wpdb->prepare()` with `%i` identifiers and `%d` integers. Table, column and charset names are not inserted as executable SQL. The options-table exclusion clause is fixed plugin code. Raw identifier quoting remains only in SQL text written into the backup, not queries executed on the source database. |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | `DOCUMENT_ROOT` is an OS path, not display text. It is unslashed, required to be a string and an absolute path without control characters, then canonicalized with `realpath()`. Lossy text sanitizers could change a valid path containing spaces, quotes, Unicode or backslashes and weaken the storage-boundary check. |
| `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | Backend exception messages are data passed to REST JSON, logs or CLI. HTML escaping here would corrupt data and cause double escaping. The administration interface escapes these values when inserting them into HTML. Telegram status uses plain text with no HTML/Markdown parse mode. |
| `WordPress.NamingConventions.PrefixAllGlobals`: core symbols and dynamic hook | `DOING_CRON`, `WP_USE_THEMES` and `doing_wp_cron` are exact WordPress core names required for the standalone worker to bootstrap and cooperate with native cron. The dispatched hook is restricted to the literal allowlist `parcel_scheduled_backup` / `parcel_worker`. Other runner globals/functions use the existing `parcel_` prefix. |

### System command requiring manual review

`SystemCron::process()` intentionally uses `proc_open()` and therefore retains a narrowly annotated `Generic.PHP.ForbiddenFunctions.Found` finding. This is a real system-command operation, not a false positive. Removing it would remove automatic system-cron setup requested for this plugin.

The command runner accepts argv arrays rather than shell command strings, limits output and execution time, and uses fixed command discovery paths. It never installs or removes a root crontab. Installation verifies that PHP CLI loads the same site, active plugin, Unix user and persistent storage directory. Cron arguments are shell-quoted and paths containing percent signs or control characters are rejected. A shared per-user lock serializes this plugin's crontab edits; only an exact owned, site-specific marked block may be replaced or removed. Unknown or concurrently changed contents are preserved. An external editor can still race because Unix crontab has no compare-and-swap API; the plugin refuses detected changes and does not attempt a destructive rollback.

The administration installation/removal endpoints require administrator capability and a REST nonce. Activation also attempts setup when `DISABLE_WP_CRON` is enabled; this behavior is disclosed in the readme. Deactivation attempts to remove the owned block. Hosts that deny command execution or crontab access keep the WP-Cron/manual-hosting setup path. This feature must be explained to the directory reviewer; it is not presented as already approved.

### Standalone cron access guard

`tools/cron.php` rejects every non-CLI SAPI before bootstrap or filesystem work. Its web branch contains an explicit `ABSPATH` guard, followed by an unconditional exit so that web execution is also refused after WordPress has loaded. The CLI branch does not require `ABSPATH` at entry: the worker loads WordPress itself, then validates the local root, site identity and active plugin. `tools/restore.php` uses the same web-only guard structure and remains usable without WordPress. No WordPress constants are fabricated and no direct-file-access checks are suppressed.

The non-CLI guard was already present in version 1.0.2. Version 1.0.3 makes it recognizable by Plugin Check 2.1.0, whose detector does not recognize `PHP_SAPI` checks alone. It also removes the leading shell interpreter line from both PHP files: under a web SAPI that line was treated as output before `declare(strict_types=1)`, causing a compile error before the guard could run. Both tools are invoked through the PHP executable; that command-line interface is unchanged. Real HTTP tests now verify status 403 with and without a preloaded WordPress context.

### Status-message durability

The first worker tick saves `status_send_attempted` before sending a single announcement. A lost reply can leave an uneditable message in Telegram, but never causes an automatic replacement announcement. A confirmed message is edited at checkpoints, normally no more than once per 30 seconds, with unchanged text skipped. Optional status failures have independent backoff and cannot change archive delivery or its retry counter. Completed, failed and cancelled jobs with a pending acknowledged status message receive at most one due edit per otherwise-idle worker tick, stopping after six consecutive failures; no new announcement is created. A deleted/inaccessible message disables further edits.

The text is plain UTF-8, capped at 3800 bytes, and displays an overall stage estimate rather than byte-level upload progress or an ETA. At most ten part links plus manifest/recovery links are shown, and only for Telegram receipts identifying a channel or supergroup. Private bot chats/basic groups show document/search instructions instead of fabricated permalinks. Additional delivered parts are disclosed explicitly.

### Regression checks

Run the isolated checks from the plugin directory:

```sh
php tests/archive.php
php tests/cli-entry-points.php
php tests/system-cron.php
php tests/engine-status.php
```

The fake-crontab test never accesses the host crontab. The status test uses a fake Telegram transport and exercises actual worker ticks, cancellation, ambiguous sends, deleted messages, throttling, bounded terminal retries and link/text limits.

Against a disposable WordPress installation with this plugin active:

```sh
PARCEL_TEST=1 php tests/storage-wordpress-test.php --wp=/path/to/disposable/wordpress --scratch=/path/to/scratch
```

This also tests document-root validation and live prepared SQL using a temporary table with a quoted identifier, UTF-8 text and binary bytes. It must not be run against production. Full backup/recovery tests must additionally verify database value fidelity and delivery through the intended hosting/network configuration.

## WordPress HTTP API and standalone recovery

Bot API calls use `wp_remote_post()`. WordPress owns the HTTP execution. A request-scoped `http_api_curl` callback configures streaming `CURLFile` multipart, explicit proxy credentials and supported DNS/DoH settings; these have no equivalent standard WordPress request arguments. The callback is isolated by the URL and a random per-request marker, and every temporary hook is removed in `finally`. Core Requests hooks select the bundled cURL transport per request and enforce TLS validation, no redirects and a 2 MiB response cap, including compressed responses. Tests exercise nested unrelated requests and reject intercepted responses rather than accepting an unverified delivery receipt. There is no direct `curl_init()`/`curl_exec()` call in the plugin.

`tools/restore.php` is a standalone recovery program. It rejects all non-CLI execution before reading input. It cannot require `ABSPATH`, because recovery explicitly works without WordPress. Its streams, exclusive file creation and mode 0700/0600 enforce bounded memory and safe local output; terminal output and exception data are not HTML. The hidden password prompt uses only fixed `stty` operations and shell-quotes the terminal mode captured from `stty -g`; stdin/environment password input avoids that optional terminal operation. It never executes downloaded PHP, SQL, or archive contents. File paths and completeness are authenticated and checked before a completed recovery is reported.

Authorized admin downloads stream a binary attachment from a checked local path without buffering the entire backup. Private log files explicitly use mode 0600. These source-line exceptions are documented for review rather than blanket suppression of a rule.

## Automated scan result

Plugin Check 2.1.0 was run with runtime checks against the active, installable 1.0.3 package on WordPress 7.1.3 on October 20, 2026. The scan reports **zero errors and zero warnings**. No checks were excluded. The two direct-file-access findings reported for versions 1.0.1 and 1.0.2 are resolved by the explicit web guards described above.

This automated result is not WordPress.org approval or an independent security audit. The deliberate system-command and streaming operations described above remain available for manual review.

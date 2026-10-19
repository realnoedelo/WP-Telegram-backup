# TG Backup archive format v1

[Back to README](../README.md)

Status: format version 1. Implementations: [`includes/Archive.php`](../includes/Archive.php) and the standalone [`tools/restore.php`](../tools/restore.php). License: GPL-3.0-or-later. This is a custom container, not ZIP or tar; changing its extension will not convert it. The specification and recovery tool are included with the free plugin, with no external service or developer account required.

## What is included

- `files/…`: regular files and empty directories inside the WordPress root, except user-defined exclusions.
- `database.sql`: the structure and rows of tables with the current WordPress prefix. Binary values use `FROM_BASE64('…')`; text and JSON use an explicit conversion from the connection character set. Numeric types and BIT use exact numeric literals without conversion to PHP numbers. `NULL` stays `NULL`, and computed columns are excluded from INSERT statements. Each batch is exported in UTC, and the SQL sets UTC during import, preserving the original instant represented by TIMESTAMP values. The WordPress connection's original time zone is restored after each batch.
- Triggers, stored procedures, events, database users and privileges are not exported: the format backs up site tables, not the entire MySQL server. If your installation depends on these objects, save them separately using your hosting tools.
- Options named `parcel_*`, `_transient_parcel_*` and `_site_transient_parcel_*` are excluded from the options table. The bot token, archive password and worker state are therefore not included through the plugin's settings.
- If `wp-config.php` is inside the site root and is not excluded, its site secrets are included in the **encrypted** file backup. Backups contain real user data; keep the password separately.
- Multisite, symbolic links, special files, external content directories, SQL views and nonportable filenames are not supported. An unsupported item stops the job with an explanation. You may explicitly exclude a symbolic link after arranging a separate backup of its target.

Site files are scanned and read incrementally. The writer checks each file's size, modification time, inode change time and inode number. It checks a directory's contents when resuming its scan. Table structure and row counts are compared before and after export. This is neither a filesystem snapshot nor a transactionally consistent database backup: changes that preserve row counts, and changes to files after they have been read, may go undetected. Pause updates, migrations and bulk changes for important backups; strict consistency requires a coordinated snapshot at the hosting level.

## Archive parts

Parts are named `part-00001.pvlt`, `part-00002.pvlt`, and so on, in sequence without gaps. A single source file may span several parts. Parts end only between encrypted records. The complete part, including its header, never exceeds the configured part size.

Each part starts with:

1. ASCII `PARCEL-VAULT-1\n`.
2. A single line of UTF-8 JSON followed by LF.
3. Zero or more frames; every part in a valid completed archive contains at least one frame.

JSON header:

```json
{"format":"parcel-vault","version":1,"id":"UUID","part":1,"salt":"BASE64_32_RANDOM_BYTES","iterations":210000,"sequence":0,"previous_sha256":null}
```

- `part`: the part number, starting at 1.
- `sequence`: the global number of the part's first record, starting at 0.
- `salt`: a random 32-byte salt generated once per archive and shared by all its parts.
- `previous_sha256`: the SHA-256 of every byte in the previous part, in lowercase hexadecimal; `null` for the first part.
- The exact header bytes, including the magic string and both line breaks, form part of the AAD for every record in that part. Do not reformat the JSON.

The header is public: it exposes the version, UUID, salt, sequence and hash chain. Filenames, SQL and record contents are encrypted. The delivery service can see the number and sizes of parts.

## Key and frame

Key derivation: `PBKDF2-HMAC-SHA256(password, salt, 210000, 32 bytes)`. The password is treated as UTF-8 bytes without Unicode normalization; preserve the exact string. Every frame uses AES-256-GCM with a fresh random 12-byte nonce and a 16-byte tag.

Frame layout:

| Field | Size |
|---|---:|
| Length of the following fields, unsigned big-endian | 4 bytes |
| Nonce | 12 bytes |
| GCM tag | 16 bytes |
| Ciphertext | Remainder of the frame |

AAD = the exact part header bytes + ASCII `record:` + the decimal global record number, without a trailing LF.

Before encryption, a record is compressed using raw DEFLATE at level 3. The decompressed record contains:

| Field | Size |
|---|---:|
| JSON metadata length, unsigned big-endian | 4 bytes |
| UTF-8 JSON metadata | Up to 65,536 bytes |
| Binary data | Up to 1,048,576 bytes |

A frame is accepted only after GCM authentication succeeds. The recovery tool limits frame size, metadata size and decompressed size. There is no plaintext SQL staging file: database content goes directly into encrypted records. All parts are created in private local storage.

## Record sequence

The `type` field is required:

- `start`: the first record. Contains the UUID, time, backup type, site URL, WordPress version, consistency notice and exclusions.
- `directory`: `path`, `mode`, `mtime`; creates an empty directory or describes a parent directory that already exists.
- `file_begin`: `entry`, a sequential file number starting at 1; `path`, `size`, `mode`, `mtime`. For SQL, `size` may be `null` because the final size is not known until export finishes.
- `data`: `entry`, `offset` and binary content; a nonempty chunk of up to 1 MiB. The offset is the absolute number of source bytes already written for the file, stored as a 64-bit integer. A large source file is never loaded entirely into memory.
- `file_end`: `entry`, `bytes`, `chain_sha256`.
- `complete`: appears only after every file has been closed. Contains the total `files`, `directories` and `bytes`, and the completion time. This must be the single final record. Its absence means the archive is **incomplete**, even if some files have already been extracted.

File entries cannot be nested. A directory record is not allowed inside an unfinished file entry. Final counts must match. No additional bytes or parts are allowed after `complete`.

Each file's verification chain starts with 32 zero bytes. For every chunk:

```text
chain = SHA256(previous_chain_bytes || SHA256(chunk_bytes))
```

`file_end.chain_sha256` stores the chain in hexadecimal. This is a SHA-256 chain over chunks, **not** the ordinary SHA-256 of the complete file. The chain allows checkpoints between PHP processes without serializing a cryptographic context. GCM and the global record sequence also verify the order, metadata and content of every record.

## Manifest and delivery

`manifest.json` contains the UUID, time, backup type, file count, and each part's name, size in bytes and ordinary SHA-256. It helps with transfers and download checks, but it is not a trust anchor: the manifest has no digital signature. The encrypted records and part chain establish authenticity, order and completeness. Recovery can work without the manifest.

A job is marked as delivered only after Telegram confirms every part, the manifest and the standalone `restore.php`. Each confirmation and its `message_id` are saved locally. A separate, optional status message is edited with progress and completion; status failures do not change archive delivery success. Documents are sent silently but remain separate chat messages. After a network timeout, Telegram may have received a file even though the response was lost; a retry may create a duplicate. For recovery, choose one complete, sequential set with a single UUID. Local retention policies do not delete Telegram messages.

The manifest is sent unencrypted and exposes backup metadata, including job ID, time, type, counts, part names, sizes and hashes. Captions and the edited status message also carry plaintext job metadata and error summaries. The recovery script is sent as unencrypted source code. None of these contains the archive password or decrypted site file contents. Do not execute an unknown modified recovery tool; use a trusted copy from the plugin's source code.

## Checkpoints and limits

Queue state is stored locally and updated atomically after each bounded step. After a restart, the current part is truncated to the last committed offset, and parts beyond that checkpoint are removed. Parts whose delivery has already been confirmed are not sent again. If a source file changes, create a new backup: resuming with the old index does not resolve a changed source.

Three independent limits are checked: total local storage usage, physical free space with a required reserve, and the maximum size of one encrypted archive. Compression savings are not assumed. The database estimate based on `Data_length` is approximate; the actual archive size is checked on every write. The quota includes active, failed, cancelled and completed jobs. Automatic cleanup removes only the local data of fully delivered backups; active and undelivered data are not deleted to make room.

The default storage location is a private system temporary directory. For long-term storage and a durable queue, set `TELEGRAM_BACKUP_STORAGE_DIR` to a permanent local directory outside the document root. Hosting cleanup of `/tmp` can destroy local state; it does not remove backups already delivered to Telegram.

## Safe recovery

Download every part of one job into a separate directory. For example:

```sh
php restore.php --input=/backup/site-2026-10-19 --output=/recovery/test-copy
```

A compatible Unix terminal prompts for the password without displaying it. For automation, use `--password-stdin` or the `TELEGRAM_BACKUP_PASSWORD` environment variable; passwords must not be passed as command-line arguments. The output directory's parent must already exist. The output directory itself must be new or completely empty.

The recovery tool:

- does not modify the current site or connect to a database;
- rejects file overwrites, path traversal, symbolic links inside the output, mixed or missing parts, incorrect passwords and corrupted records;
- creates files with mode `0600` and directories with mode `0700`; recorded source permissions are not applied automatically;
- attempts to restore source modification times, applying directory times after extraction;
- leaves `PARCEL-INCOMPLETE.txt` on failure; do not deploy that output;
- creates `PARCEL-VERIFIED.json` after full verification and removes the incomplete marker.

After verification, import `database.sql` into a **new test database**, deploy `files/` to a test site, and configure the new database credentials in `wp-config.php`, file ownership and permissions appropriate to the server. Check login, plugins, media and key user workflows. The SQL contains DROP/CREATE statements and is intended for deliberate import into a separate database. There is no automatic restore over a live site.

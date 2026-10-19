<?php
/** Durable, single-worker backup queue. */
namespace ParcelBackups;

if (!defined('ABSPATH')) { exit; }

final class Engine
{
    private const ACTIVE = ['queued', 'running', 'retry'];
    private const ZERO_CHAIN = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function enqueue(string $type = 'full', string $origin = 'manual'): array
    {
        self::validate_type($type);
        self::ready($type);
        $lock = Storage::lock();
        if (!$lock) { throw new \RuntimeException('The worker is busy. Try again in a few seconds.'); }
        try {
            $active = array_filter(Storage::jobs(), fn($j) => in_array($j['status'], self::ACTIVE, true));
            if ($origin === 'schedule' && $active) {
                Support::log('', 'info', 'Scheduled backup skipped: the queue is still busy.');
                return reset($active);
            }
            if (count($active) >= 3) { throw new \RuntimeException('There are already three jobs in the queue. Wait for them to finish.'); }
            Storage::cleanup();
            Storage::ensure_space(65536);
            $id = wp_generate_uuid4();
            $job = ['id' => $id, 'status' => 'queued', 'stage' => 'scan', 'type' => $type,
                'origin' => $origin, 'created_at' => Support::now(), 'updated_at' => Support::now(),
                'progress' => 0, 'message' => 'Waiting to run', 'error' => '', 'source_bytes' => 0,
                'bytes' => 0, 'files' => 0, 'parts' => [], 'attempts' => 0, 'retry_at' => 0,
                'settings_fingerprint' => self::fingerprint(),
                'scan_state' => ['pending' => [['path' => '', 'cursor' => 0]], 'index_bytes' => 0,
                    'directories' => 0, 'entries' => 0],
                'index_offset' => 0, 'file_index' => 0, 'archive_files' => 0, 'archive_directories' => 0,
                'plain_bytes' => 0, 'warning' => 'This is a backup of a live site, not a consistent snapshot of its database and files.',
                'archive_state' => Archive::initial($id)];
            Storage::job_dir($id);
            Storage::write_job($job);
            Support::log($id, 'info', 'Job added to the queue.', ['type' => $type, 'origin' => $origin]);
            return $job;
        } finally { Storage::unlock($lock); }
    }

    public static function tick(string $source = 'cron'): void
    {
        $worker = get_option('parcel_worker_state', []);
        if (!is_array($worker)) { $worker = []; }
        $worker['last_tick'] = Support::now();
        $worker['last_source'] = $source;
        if ($source === 'cron') { $worker['last_cron_tick'] = Support::now(); }
        update_option('parcel_worker_state', $worker, false);
        $lock = Storage::lock();
        if (!$lock) { return; }
        $worker['busy'] = true;
        update_option('parcel_worker_state', $worker, false);
        $job = null; $checkpoint = null;
        try {
            $jobs = array_reverse(Storage::jobs());
            foreach ($jobs as $candidate) {
                if (in_array($candidate['status'], self::ACTIVE, true) &&
                    ($candidate['status'] !== 'retry' || ($candidate['retry_at'] ?? 0) <= time())) {
                    $job = $candidate; break;
                }
            }
            if (!$job) { self::retry_terminal_status($jobs); return; }
            $checkpoint = $job;
            if (is_file(Storage::job_dir($job['id']) . '/cancel.request')) {
                self::apply_cancel($job); self::save($job); self::sync_status($job, true); return;
            }
            if (!hash_equals($job['settings_fingerprint'], self::fingerprint())) {
                throw new \RuntimeException('The settings or password have changed. Create a new job.');
            }
            self::ready($job['type']);
            $job['status'] = 'running';
            $job['error'] = '';
            $job['retry_at'] = 0;
            self::save($job); self::sync_status($job); $checkpoint = $job;
            $dir = Storage::job_dir($job['id']);
            Archive::recover($dir, $job['archive_state']);
            self::recover_index($dir, $job);
            $deadline = microtime(true) + 18;
            $settings = Settings::get();
            while (microtime(true) < $deadline && $job['status'] === 'running') {
                if (is_file($dir . '/cancel.request')) {
                    self::apply_cancel($job); self::save($job); self::sync_status($job, true); $checkpoint = $job; break;
                }
                switch ($job['stage']) {
                    case 'scan': self::scan_step($job, $deadline); break;
                    case 'database': self::database_step_utc($job); break;
                    case 'archive': self::file_step($job); break;
                    case 'send': self::send_step($job); break;
                    default: throw new \RuntimeException('Unknown job stage.');
                }
                self::save($job); self::sync_status($job, $job['status'] === 'completed'); $checkpoint = $job;
                if ($job['status'] === 'completed') {
                    try { Storage::cleanup(); }
                    catch (\Throwable $cleanupError) { Support::log($job['id'], 'warning', 'The backup was delivered, but local cleanup did not finish: ' . Support::redact($cleanupError->getMessage())); }
                }
                if ($job['stage'] === 'send' || $job['status'] !== 'running') { break; }
            }
        } catch (\Throwable $e) {
            if (is_array($checkpoint)) {
                $job = $checkpoint;
                $retryable = $e instanceof TelegramException && $e->retryable;
                $job['attempts'] = (int) $job['attempts'] + 1;
                $job['error'] = Support::redact($e->getMessage());
                if ($retryable && $job['attempts'] < 6) {
                    $job['status'] = 'retry';
                    $delay = max(min(3600, 30 * (2 ** ($job['attempts'] - 1))), (int) $e->retry_after);
                    $job['retry_at'] = time() + $delay;
                    $job['message'] = 'Delivery failed. A retry has been scheduled.';
                } else {
                    $job['status'] = 'failed';
                    $job['message'] = 'The job stopped with an error. Local data has been preserved.';
                }
                self::save($job);
                self::sync_status($job, true);
                Support::log($job['id'], 'error', $job['error'], ['stage' => $job['stage'], 'attempts' => $job['attempts']]);
            } else { Support::log('', 'error', Support::redact($e->getMessage())); }
        } finally {
            $worker = get_option('parcel_worker_state', $worker);
            $worker['busy'] = false;
            update_option('parcel_worker_state', $worker, false);
            Storage::unlock($lock);
        }
    }

    public static function cancel(string $id): array
    {
        $lock = Storage::lock();
        if (!$lock) {
            $job = self::job($id);
            if (!in_array($job['status'], self::ACTIVE, true)) { throw new \RuntimeException('Only an active job can be cancelled.'); }
            $path = Storage::job_dir($id) . '/cancel.request';
            if (file_put_contents($path, Support::now(), LOCK_EX) === false) { throw new \RuntimeException('Could not save the cancellation request.'); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
            chmod($path, 0600);
            $job['cancel_requested'] = true;
            $job['message'] = 'Cancellation requested. The job will stop after the current operation finishes.';
            return $job;
        }
        try {
            $job = self::job($id);
            if (!in_array($job['status'], self::ACTIVE, true)) { throw new \RuntimeException('Only an active job can be cancelled.'); }
            self::apply_cancel($job);
            self::save($job);
            self::sync_status($job, true);
            Support::log($id, 'info', 'Job cancelled by the user.');
            return $job;
        } finally { Storage::unlock($lock); }
    }

    public static function retry(string $id): array
    {
        $lock = Storage::lock();
        if (!$lock) { throw new \RuntimeException('The worker is busy. Try again in a few seconds.'); }
        try {
            $job = self::job($id);
            if ($job['status'] !== 'failed') { throw new \RuntimeException('Only a failed job can be retried.'); }
            if (count(array_filter(Storage::jobs(), fn($j) => in_array($j['status'], self::ACTIVE, true))) >= 3) {
                throw new \RuntimeException('There are already three jobs in the queue.');
            }
            if (!hash_equals($job['settings_fingerprint'], self::fingerprint())) {
                throw new \RuntimeException('The settings, password or recipient have changed. Create a new job.');
            }
            self::ready($job['type']);
            $job['status'] = 'queued'; $job['retry_at'] = 0; $job['attempts'] = 0;
            $job['error'] = ''; $job['message'] = 'Resuming from the saved checkpoint';
            self::save($job);
            return $job;
        } finally { Storage::unlock($lock); }
    }

    public static function status(): array
    {
        $worker = wp_parse_args(get_option('parcel_worker_state', []),
            ['last_tick' => null, 'last_cron_tick' => null, 'last_source' => null, 'busy' => false]);
        // A terminated worker may leave a stale flag; the actual lock is authoritative.
        $lock = Storage::lock();
        if ($lock) { $worker['busy'] = false; Storage::unlock($lock); } else { $worker['busy'] = true; }
        $jobs = Storage::jobs();
        foreach ($jobs as &$job) {
            if (in_array($job['status'], self::ACTIVE, true) && is_file(Storage::job_dir($job['id']) . '/cancel.request')) {
                $job['cancel_requested'] = true;
                $job['message'] = 'Cancellation requested. Waiting for the current operation to finish.';
            }
        }
        unset($job);
        return ['jobs' => $jobs, 'worker' => $worker, 'storage' => Storage::usage()];
    }

    public static function preflight(string $type = 'full'): array
    {
        self::validate_type($type);
        $errors = []; $warnings = ['A live-site backup is not a consistent snapshot. Avoid updates and bulk data changes while the backup runs.'];
        $source = 0; $files = 0; $dbBytes = 0;
        $deadline = microtime(true) + 12;
        try {
            self::ready($type);
            if ($type !== 'database') {
                $pending = [''];
                while ($pending) {
                    $relative = array_pop($pending);
                    $path = self::root() . ($relative === '' ? '' : '/' . $relative);
                    $list = self::list_directory($path);
                    foreach ($list as $name) {
                        if (microtime(true) > $deadline) { throw new \RuntimeException('The preliminary estimate did not finish in time. The queued job will perform the full check.'); }
                        $rel = $relative === '' ? $name : $relative . '/' . $name;
                        if (self::excluded($rel)) { continue; }
                        $stat = self::safe_stat(self::root() . '/' . $rel);
                        if (($stat['mode'] & 0170000) === 0040000) { $pending[] = $rel; }
                        else { $source += $stat['size']; $files++; }
                    }
                }
            }
            if ($type !== 'files') { $dbBytes = self::database_estimate(); }
        } catch (\Throwable $e) { $errors[] = Support::redact($e->getMessage()); }
        $estimate = self::estimate($source, $files, $dbBytes);
        $s = Settings::get(); $usage = Storage::usage();
        if ($estimate > (int) $s['max_backup_mb'] * 1048576) { $errors[] = 'The conservative estimate exceeds the maximum backup size. Increase the limit or add exclusions.'; }
        if ($usage['used_bytes'] + $estimate > $usage['quota_bytes']) { $errors[] = 'Insufficient storage quota. Delete unnecessary local backups or increase the quota.'; }
        if ($usage['free_bytes'] - $estimate < $usage['reserve_bytes']) { $errors[] = 'The backup would leave less than the required disk space reserve.'; }
        return ['ok' => !$errors, 'source_bytes' => $source + $dbBytes, 'estimated_bytes' => $estimate,
            'required_bytes' => $estimate, 'usage' => $usage, 'warnings' => $warnings, 'errors' => $errors,
            'files' => $files, 'database_estimated_bytes' => $dbBytes];
    }

    private static function scan_step(array &$job, float $deadline): void
    {
        $dir = Storage::job_dir($job['id']);
        if ($job['type'] === 'database') { $job['scan_state']['pending'] = []; }
        $handled = 0;
        while ($job['scan_state']['pending'] && $handled < 200 && microtime(true) < $deadline) {
            $current = array_shift($job['scan_state']['pending']);
            $relative = $current['path'];
            $path = self::root() . ($relative === '' ? '' : '/' . $relative);
            self::safe_stat($path);
            $names = self::list_directory($path);
            $stamp = hash('sha256', implode("\0", $names));
            if (isset($current['stamp']) && !hash_equals($current['stamp'], $stamp)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
                throw new \RuntimeException('Directory contents changed during scanning: ' . $relative);
            }
            $current['stamp'] = $stamp;
            while ($current['cursor'] < count($names) && $handled < 200 && microtime(true) < $deadline) {
                $name = $names[$current['cursor']++]; $handled++;
                $rel = $relative === '' ? $name : $relative . '/' . $name;
                if (self::excluded($rel)) { continue; }
                self::valid_path($rel);
                $stat = self::safe_stat(self::root() . '/' . $rel);
                $directory = ($stat['mode'] & 0170000) === 0040000;
                $record = ['path' => $rel, 'directory' => $directory, 'size' => $directory ? 0 : $stat['size'],
                    'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'inode' => $stat['ino'], 'mode' => $stat['mode'] & 0777];
                $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                Storage::ensure_space(strlen($line), $job['id']);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                $h = fopen($dir . '/file-index.ndjson', 'ab');
                if (!$h) { throw new \RuntimeException('Could not write the file index.'); }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                try { Archive::write_all($h, $line); fflush($h); if (function_exists('fsync')) { fsync($h); } } finally { fclose($h); }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
                chmod($dir . '/file-index.ndjson', 0600);
                $job['scan_state']['index_bytes'] += strlen($line);
                $job['scan_state']['entries']++;
                if ($directory) {
                    $job['scan_state']['directories']++;
                    $job['scan_state']['pending'][] = ['path' => $rel, 'cursor' => 0];
                } else { $job['files']++; $job['source_bytes'] += $stat['size']; }
            }
            if ($current['cursor'] < count($names)) { array_unshift($job['scan_state']['pending'], $current); }
        }
        $job['message'] = sprintf('Files checked: %d. Estimating space…', $job['files']);
        $job['progress'] = 5;
        if (!$job['scan_state']['pending']) {
            $dbBytes = $job['type'] === 'files' ? 0 : self::database_estimate();
            $estimate = self::estimate($job['source_bytes'], $job['files'], $dbBytes);
            $settings = Settings::get();
            if ($estimate > (int) $settings['max_backup_mb'] * 1048576) {
                throw new \RuntimeException('The conservative estimate exceeds the maximum backup size. Increase the limit or exclude unnecessary files.');
            }
            Storage::ensure_space($estimate, $job['id']);
            $job['estimated_bytes'] = $estimate; $job['database_estimated_bytes'] = $dbBytes;
            self::record($job, 'start', ['id' => $job['id'], 'created_at' => $job['created_at'], 'backup_type' => $job['type'],
                'site' => home_url('/'), 'wordpress_version' => get_bloginfo('version'),
                'consistency' => 'live-nontransactional', 'excludes' => self::exclude_patterns()]);
            $job['stage'] = $job['type'] === 'files' ? 'archive' : 'database';
            $job['progress'] = 10;
        }
    }

    private static function database_step_utc(array &$job): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
        $original = $wpdb->get_var('SELECT @@session.time_zone');
        self::db_error();
        if (!is_string($original) || $original === '') { throw new \RuntimeException('Could not determine the database session time zone.'); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
        $wpdb->query("SET SESSION time_zone = '+00:00'"); self::db_error();
        try { self::database_step($job); }
        finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
            $wpdb->query($wpdb->prepare('SET SESSION time_zone = %s', $original));
            self::db_error();
        }
    }

    private static function database_step(array &$job): void
    {
        global $wpdb;
        if (!isset($job['db_state'])) {
            $tables = self::tables();
            // The server reports the encoding of returned text, including changes made by connection hooks.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
            $charset = $wpdb->get_var('SELECT @@session.character_set_results');
            self::db_error();
            if (!is_string($charset) || !preg_match('/^[a-zA-Z0-9_]+$/D', $charset)) { throw new \RuntimeException('Could not determine the database connection character set.'); }
            $job['db_state'] = ['tables' => $tables, 'index' => 0, 'phase' => 'table', 'offset' => 0,
                'bytes' => 0, 'chain' => self::ZERO_CHAIN, 'entry' => 1, 'charset' => $charset];
            self::record($job, 'file_begin', ['entry' => 1, 'path' => 'database.sql', 'size' => null, 'mode' => 0600, 'mtime' => time()]);
            self::sql($job, "-- TG Backup: live database export, no transaction/snapshot guarantee.\n-- Restore into a NEW database after reviewing configuration.\nSET NAMES " . $charset . ";\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
            return;
        }
        $db =& $job['db_state'];
        if ($db['index'] >= count($db['tables'])) {
            self::sql($job, "\nSET FOREIGN_KEY_CHECKS=1;\n-- End of Parcel database export.\n");
            self::record($job, 'file_end', ['entry' => 1, 'bytes' => $db['bytes'], 'chain_sha256' => $db['chain']]);
            $job['archive_files']++; $job['plain_bytes'] += $db['bytes'];
            $job['stage'] = 'archive'; $job['progress'] = 30;
            return;
        }
        $table = $db['tables'][$db['index']];
        $quoted = self::quote_id($table);
        if ($db['phase'] === 'table') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump. SHOW CREATE TABLE only reads DDL; it does not modify the schema.
            $schema = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
            self::db_error();
            if (!$schema || !isset($schema[1]) || stripos($schema[1], 'CREATE TABLE') !== 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
                throw new \RuntimeException('Views and unknown table types are not supported: ' . $table);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
            $columns = $wpdb->get_results($wpdb->prepare('SHOW FULL COLUMNS FROM %i', $table), ARRAY_A); self::db_error();
            $db['columns'] = [];
            $db['column_info'] = [];
            foreach ($columns as $column) {
                if (!preg_match('/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', $column['Extra'] ?? '')) {
                    $db['columns'][] = $column['Field'];
                    $db['column_info'][$column['Field']] = ['type' => $column['Type'], 'collation' => $column['Collation'] ?? null];
                }
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
            if (!$db['columns']) { throw new \RuntimeException('Could not identify writable columns: ' . $table); }
            $db['order_columns'] = self::primary_columns($table);
            $db['count'] = self::table_count($table);
            $db['schema_hash'] = hash('sha256', $schema[1]);
            self::sql($job, "\nDROP TABLE IF EXISTS " . $quoted . ";\n" . $schema[1] . ";\n");
            $db['offset'] = 0; $db['phase'] = 'rows';
        } else {
            $columnsSql = implode(',', array_map([self::class, 'quote_id'], $db['columns']));
            // Resume older checkpoints without executing the previously serialized ORDER BY fragment.
            if (!isset($db['order_columns'])) { $db['order_columns'] = self::primary_columns($table); }
            // Inspect only scalar byte lengths first: large BLOB/options must not exhaust PHP before a checkpoint.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump. The local builder uses fixed SQL fragments and wpdb placeholders for every dynamic identifier/value.
            $sizes = $wpdb->get_col(self::rows_query($table, $db, 25, true));
            self::db_error();
            $budget = self::database_memory_budget();
            $batchSize = 0; $batchBytes = 0;
            foreach ($sizes as $size) {
                $rowBytes = (int) $size + 64 * count($db['columns']);
                if ($batchBytes + $rowBytes > $budget) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
                    if ($batchSize === 0) { throw new \RuntimeException('A row in table ' . $table . ' is too large for the available PHP memory. Increase memory_limit or export the database using your hosting tools.'); }
                    break;
                }
                $batchBytes += $rowBytes; $batchSize++;
            }
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump. The local builder uses fixed SQL fragments and wpdb placeholders for every dynamic identifier/value.
            $rows = $batchSize > 0 ? $wpdb->get_results(self::rows_query($table, $db, $batchSize, false), ARRAY_A) : [];
            self::db_error();
            foreach ($rows as $row) {
                $values = [];
                foreach ($db['columns'] as $column) {
                    $values[] = self::sql_value($row[$column], $db['column_info'][$column], $db['charset']);
                }
                self::sql($job, 'INSERT INTO ' . $quoted . ' (' . $columnsSql . ') VALUES (' . implode(',', $values) . ");\n");
                $db['offset']++;
            }
            if ($batchSize === 0 || count($rows) < $batchSize || $db['offset'] >= $db['count']) {
                $count = self::table_count($table);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump. SHOW CREATE TABLE only reads DDL; it does not modify the schema.
                $schema = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N); self::db_error();
                if ($count !== $db['count'] || $db['offset'] !== $db['count'] || !$schema || !hash_equals($db['schema_hash'], hash('sha256', $schema[1]))) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
                    throw new \RuntimeException('The structure or row count changed while backing up table ' . $table . '. Retry the backup when the site is less active.');
                }
                $db['index']++; $db['phase'] = 'table';
            }
        }
        $job['progress'] = 10 + (int) floor(20 * $db['index'] / max(1, count($db['tables'])));
        $job['message'] = 'Database: ' . $table . ', rows ' . $db['offset'];
    }

    private static function sql(array &$job, string $sql): void
    {
        for ($offset = 0; $offset < strlen($sql); $offset += Archive::CHUNK) {
            $chunk = substr($sql, $offset, Archive::CHUNK);
            self::record($job, 'data', ['entry' => 1, 'offset' => $job['db_state']['bytes']], $chunk);
            $job['db_state']['chain'] = Archive::chain($job['db_state']['chain'], $chunk);
            $job['db_state']['bytes'] += strlen($chunk);
        }
    }

    private static function sql_value($value, array $column, string $charset): string
    {
        if ($value === null) { return 'NULL'; }
        $value = (string) $value;
        // mysqli exposes BIT as decimal text. A binary-string literal would silently change its value.
        if (preg_match('/^(?:bit|tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/i', $column['type'])) {
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $value)) {
                throw new \RuntimeException('Invalid numeric value during database export.');
            }
            return $value; // Never cast in PHP: unsigned BIGINT and DECIMAL must retain every digit.
        }
        $binary = "FROM_BASE64('" . base64_encode($value) . "')";
        // Text was received in the connection charset. Explicit conversion also makes MySQL JSON accept it.
        if ($column['collation'] !== null || preg_match('/^json\b/i', $column['type'])) {
            return 'CONVERT(' . $binary . ' USING ' . $charset . ')';
        }
        return $binary;
    }

    private static function database_memory_budget(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        $limit = -1;
        if (preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $raw, $m)) {
            $limit = (int) $m[1] * (1024 ** (int) array_search(strtoupper($m[2]), ['', 'K', 'M', 'G'], true));
        }
        $available = $limit < 0 ? 512 * 1048576 : $limit - memory_get_usage(true) - 12 * 1048576;
        if ($available < 1048576) { throw new \RuntimeException('Insufficient available PHP memory to export the database. Increase memory_limit.'); }
        return (int) min(64 * 1048576, floor($available / 6));
    }

    private static function file_step(array &$job): void
    {
        $dir = Storage::job_dir($job['id']);
        if ($job['type'] === 'database' || (!isset($job['current_file']) && $job['index_offset'] >= $job['scan_state']['index_bytes'])) {
            $s = Settings::get();
            Archive::seal($dir, $job['archive_state'], Settings::secret('password'), (int) $s['part_mb'] * 1048576,
                (int) $s['max_backup_mb'] * 1048576, ['files' => $job['archive_files'], 'directories' => $job['archive_directories'],
                    'bytes' => $job['plain_bytes'], 'completed_at' => Support::now()]);
            $job['parts'] = $job['archive_state']['parts'];
            $job['bytes'] = $job['archive_state']['total_bytes'];
            $manifest = ['format' => 'parcel-vault', 'version' => 1, 'id' => $job['id'], 'created_at' => $job['created_at'],
                'type' => $job['type'], 'encrypted' => true, 'files' => $job['archive_files'], 'bytes' => $job['bytes'],
                'parts' => $job['parts'], 'restore' => 'php restore.php --input=/path/to/parts --output=/new/empty/directory'];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            Storage::ensure_space(strlen($json), $job['id']);
            if (file_put_contents($dir . '/manifest.json', $json, LOCK_EX) === false) { throw new \RuntimeException('Could not write the manifest.'); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
            chmod($dir . '/manifest.json', 0600);
            Storage::ensure_space(filesize(PARCEL_DIR . 'tools/restore.php'), $job['id']);
            if (!copy(PARCEL_DIR . 'tools/restore.php', $dir . '/restore.php')) { throw new \RuntimeException('Could not save the recovery tool.'); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
            chmod($dir . '/restore.php', 0600);
            $job['stage'] = 'send'; $job['progress'] = 70; $job['message'] = 'The archive is ready. Waiting for delivery.';
            return;
        }
        if (!isset($job['current_file'])) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
            $h = fopen($dir . '/file-index.ndjson', 'rb');
            if (!$h) { throw new \RuntimeException('The file index is missing.'); }
            try {
                if (fseek($h, $job['index_offset']) !== 0) { throw new \RuntimeException('Could not read the file index.'); }
                $line = fgets($h, 131072);
                if ($line === false || !str_ends_with($line, "\n")) { throw new \RuntimeException('The file index is corrupted.'); }
                $next = ftell($h);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
            } finally { fclose($h); }
            $file = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            self::verify_source($file);
            if ($file['directory']) {
                self::record($job, 'directory', ['path' => 'files/' . $file['path'], 'mode' => $file['mode'], 'mtime' => $file['mtime']]);
                $job['archive_directories']++; $job['index_offset'] = $next;
                return;
            }
            $entry = $job['archive_files'] + 1;
            self::record($job, 'file_begin', ['entry' => $entry, 'path' => 'files/' . $file['path'], 'size' => $file['size'], 'mode' => $file['mode'], 'mtime' => $file['mtime']]);
            $job['current_file'] = $file + ['offset' => 0, 'chain' => self::ZERO_CHAIN, 'entry' => $entry, 'next_index_offset' => $next];
        }
        $file =& $job['current_file'];
        self::verify_source($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        $h = fopen(self::root() . '/' . $file['path'], 'rb');
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if (!$h) { throw new \RuntimeException('Could not open the source file: ' . $file['path']); }
        try {
            self::verify_stat($file, fstat($h));
            if (fseek($h, $file['offset']) !== 0) { throw new \RuntimeException('Could not resume reading the file: ' . $file['path']); }
            $remaining = $file['size'] - $file['offset'];
            if ($remaining > 0) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                $data = fread($h, min(Archive::CHUNK, $remaining));
                if ($data === false || $data === '') { throw new \RuntimeException('Unexpected end of file: ' . $file['path']); }
                self::record($job, 'data', ['entry' => $file['entry'], 'offset' => $file['offset']], $data);
                $file['chain'] = Archive::chain($file['chain'], $data);
                $file['offset'] += strlen($data);
            }
            self::verify_stat($file, fstat($h));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        } finally { fclose($h); }
        if ($file['offset'] === $file['size']) {
            self::verify_source($file);
            self::record($job, 'file_end', ['entry' => $file['entry'], 'bytes' => $file['offset'], 'chain_sha256' => $file['chain']]);
            $job['plain_bytes'] += $file['size']; $job['archive_files']++; $job['file_index']++;
            $job['index_offset'] = $file['next_index_offset'];
            unset($job['current_file']);
        }
        $job['progress'] = 30 + (int) floor(40 * $job['file_index'] / max(1, $job['files']));
        $job['message'] = sprintf('Files backed up: %d of %d', $job['file_index'], $job['files']);
    }

    private static function send_step(array &$job): void
    {
        $telegram = new Telegram();
        $dir = Storage::job_dir($job['id']);
        foreach ($job['parts'] as $index => $part) {
            if (!empty($part['sent'])) { continue; }
            $path = $dir . '/' . $part['name'];
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) !== $part['bytes'] || !hash_equals($part['sha256'], hash_file('sha256', $path) ?: '')) {
                throw new \RuntimeException('A local archive part is corrupted. Delivery has stopped.');
            }
            $message = $telegram->send_document($path, sprintf("TG Backup • %s\nJob %s\nPart %d/%d • SHA-256 %s",
                $job['created_at'], $job['id'], $index + 1, count($job['parts']), $part['sha256']));
            if (empty($message['message_id'])) { throw new \RuntimeException('Telegram did not confirm delivery of the file.'); }
            $job['parts'][$index]['sent'] = true;
            $job['parts'][$index]['message_id'] = $message['message_id'];
            $job['attempts'] = 0;
            $sent = count(array_filter($job['parts'], fn($p) => !empty($p['sent'])));
            $job['progress'] = 70 + (int) floor(27 * $sent / max(1, count($job['parts'])));
            $job['message'] = sprintf('Parts delivered: %d of %d', $sent, count($job['parts']));
            Support::log($job['id'], 'info', 'Archive part delivered to Telegram.', ['part' => $part['name'], 'message_id' => $message['message_id']]);
            return; // At most one uploaded file per worker tick.
        }
        if (empty($job['manifest_sent'])) {
            $message = $telegram->send_document($dir . '/manifest.json', 'TG Backup • Manifest for job ' . $job['id'] . '. Recovery requires ALL parts and the saved password.');
            if (empty($message['message_id'])) { throw new \RuntimeException('Telegram did not confirm delivery of the manifest.'); }
            $job['manifest_sent'] = true; $job['manifest_message_id'] = $message['message_id']; $job['attempts'] = 0;
            $job['progress'] = 98; $job['message'] = 'Archive and manifest delivered. Sending the recovery tool.';
            return;
        }
        if (empty($job['recovery_sent'])) {
            $message = $telegram->send_document($dir . '/restore.php', 'TG Backup • Standalone recovery tool. Requires PHP 8.1+, openssl and zlib. Job ' . $job['id']);
            if (empty($message['message_id'])) { throw new \RuntimeException('Telegram did not confirm delivery of the recovery tool.'); }
            $job['recovery_sent'] = true; $job['recovery_message_id'] = $message['message_id']; $job['attempts'] = 0;
            $job['progress'] = 99; $job['message'] = 'All files delivered. Finalizing the backup.';
            return;
        }
        if (!empty($job['status_message_id'])) { $job['completion_message_id'] = $job['status_message_id']; }
        $job['status'] = 'completed'; $job['stage'] = 'complete'; $job['progress'] = 100;
        $job['completed_at'] = Support::now(); $job['message'] = 'The complete backup has been delivered to Telegram.'; $job['error'] = '';
        Support::log($job['id'], 'info', 'The complete backup has been delivered.');
    }

    /** Best-effort status updates are independent of archive delivery and its retry counters. */
    private static function retry_terminal_status(array $jobs): void
    {
        if (!Settings::get()['consent']) { return; }
        $fingerprint = self::fingerprint();
        foreach ($jobs as $job) {
            if (!in_array($job['status'], ['completed', 'failed', 'cancelled'], true) ||
                empty($job['status_message_id']) || !empty($job['status_edit_disabled']) ||
                ($job['status_failures'] ?? 0) >= 6 || ($job['status_retry_at'] ?? 0) > time() ||
                !hash_equals($job['settings_fingerprint'], $fingerprint) ||
                ($job['status_hash'] ?? '') === hash('sha256', self::status_text($job))) { continue; }
            self::sync_status($job, true);
            return; // At most one due terminal edit per idle worker tick, never another sendMessage.
        }
    }

    /** Persist the initial attempt before I/O; subsequent requests only edit the acknowledged message. */
    private static function sync_status(array &$job, bool $force = false): void
    {
        try {
            if (!Settings::get()['consent'] || !hash_equals($job['settings_fingerprint'], self::fingerprint())) { return; }
            $now = time();
            $text = self::status_text($job);
            $hash = hash('sha256', $text);
            if (empty($job['status_message_id'])) {
                if (!empty($job['status_send_attempted']) || $job['status'] !== 'running') { return; }
                // Save before I/O: a lost Telegram reply must never trigger a second announcement.
                $job['status_send_attempted'] = true;
                $job['status_last_attempt'] = $now;
                self::save($job);
                $message = (new Telegram())->send_message($text);
                if (empty($message['message_id']) || !ctype_digit((string) $message['message_id']) || (int) $message['message_id'] < 1) {
                    throw new \RuntimeException('Telegram did not confirm the status message ID. The announcement will not be repeated.');
                }
                $job['status_message_id'] = (int) $message['message_id'];
                $job['status_chat'] = is_array($message['chat'] ?? null)
                    ? array_intersect_key($message['chat'], array_flip(['id', 'type', 'username'])) : [];
            } else {
                if (!empty($job['status_edit_disabled']) || ($job['status_retry_at'] ?? 0) > $now ||
                    ($job['status_hash'] ?? '') === $hash || (!$force && $now - ($job['status_last_attempt'] ?? 0) < 30)) { return; }
                $job['status_last_attempt'] = $now;
                self::save($job);
                (new Telegram())->edit_message((int) $job['status_message_id'], $text);
            }
            $job['status_hash'] = $hash;
            $job['status_failures'] = 0; $job['status_retry_at'] = 0; $job['status_error'] = '';
            self::save($job);
        } catch (\Throwable $error) {
            $job['status_failures'] = min(20, (int) ($job['status_failures'] ?? 0) + 1);
            $job['status_error'] = Support::redact($error->getMessage());
            $job['status_retry_at'] = time() + max(min(3600, 30 * (2 ** min(7, $job['status_failures']))),
                $error instanceof TelegramException ? (int) $error->retry_after : 0);
            if ($error instanceof TelegramException && !$error->retryable && in_array($error->getCode(), [400, 403, 404], true)) {
                $job['status_edit_disabled'] = true;
            }
            try {
                self::save($job);
                Support::log($job['id'], 'warning', 'Telegram status could not be updated; backup processing continues. No replacement announcement will be sent.',
                    ['error' => $job['status_error']]);
            } catch (\Throwable $ignored) { /* Optional status reporting must not turn a successful delivery into a failed backup. */ }
        }
    }

    /** Plain text only: no HTML/Markdown parse mode; bound bytes below Telegram's character limit. */
    private static function status_text(array $job): string
    {
        $states = ['queued' => 'Queued', 'running' => 'In progress', 'retry' => 'Waiting to retry',
            'completed' => 'Complete', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
        $stages = ['scan' => 'Scanning files', 'database' => 'Exporting database', 'archive' => 'Building archive',
            'send' => 'Uploading documents', 'complete' => 'Delivered'];
        $parts = $job['parts'] ?? [];
        $sent = count(array_filter($parts, static fn($part) => !empty($part['sent'])));
        $text = 'TG Backup — ' . ($states[$job['status']] ?? 'In progress') . "\nJob: " . $job['id'] .
            "\nCreated: " . $job['created_at'] . "\nProgress: " . max(0, min(100, (int) $job['progress'])) . '% (overall estimate)' .
            "\nStage: " . ($stages[$job['stage']] ?? 'Preparing') .
            "\nArchive: " . round((int) $job['bytes'] / 1048576, 1) . ' MiB' .
            "\nParts delivered: " . $sent . '/' . count($parts);
        if ($job['status'] === 'retry' && !empty($job['retry_at'])) { $text .= "\nRetry after: " . gmdate('Y-m-d H:i:s', $job['retry_at']) . ' UTC'; }
        if (!empty($job['error'])) { $text .= "\nReason: " . self::status_clip(Support::redact($job['error']), 500); }
        $prefix = self::status_link_prefix($job['status_chat'] ?? []);
        if ($prefix !== '') {
            $links = 0;
            foreach ($parts as $part) {
                if (empty($part['sent']) || empty($part['message_id']) || !ctype_digit((string) $part['message_id'])) { continue; }
                if ($links >= 10) { break; }
                $text .= "\n" . basename($part['name']) . ': ' . $prefix . $part['message_id']; $links++;
            }
            if ($sent > $links) { $text .= "\n" . ($sent - $links) . ' additional parts are in this chat; search for the Job ID above.'; }
            foreach (['manifest' => 'manifest.json', 'recovery' => 'restore.php'] as $key => $name) {
                if (!empty($job[$key . '_message_id']) && ctype_digit((string) $job[$key . '_message_id'])) {
                    $text .= "\n" . $name . ': ' . $prefix . $job[$key . '_message_id'];
                }
            }
        } elseif ($sent > 0) {
            $text .= "\nFiles are the document messages in this chat. Search for the Job ID above; direct file links are unavailable here.";
        }
        if ($job['status'] === 'completed') {
            $text .= "\nSave ALL parts, manifest.json, restore.php and your password. Test recovery separately. Local cleanup does not delete Telegram files.";
        } elseif ($job['status'] === 'failed' || $job['status'] === 'cancelled') {
            $text .= "\nThis backup is unfinished. Delivered parts remain in the chat.";
        }
        return self::status_clip($text, 3800);
    }

    private static function status_link_prefix(array $chat): string
    {
        if (!in_array($chat['type'] ?? '', ['channel', 'supergroup'], true)) { return ''; }
        if (is_string($chat['username'] ?? null) && preg_match('/^[A-Za-z][A-Za-z0-9_]{4,63}$/D', $chat['username'])) {
            return 'https://t.me/' . $chat['username'] . '/';
        }
        if (preg_match('/^-100([0-9]+)$/D', (string) ($chat['id'] ?? ''), $match)) { return 'https://t.me/c/' . $match[1] . '/'; }
        return '';
    }

    private static function status_clip(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) { return $text; }
        $text = substr($text, 0, $bytes - 3);
        while ($text !== '' && !preg_match('//u', $text)) { $text = substr($text, 0, -1); }
        return $text . '...';
    }

    private static function record(array &$job, string $type, array $meta = [], string $data = ''): void
    {
        $s = Settings::get();
        Archive::append(Storage::job_dir($job['id']), $job['archive_state'], Settings::secret('password'),
            (int) $s['part_mb'] * 1048576, (int) $s['max_backup_mb'] * 1048576, $type, $meta, $data);
        $job['bytes'] = $job['archive_state']['total_bytes'];
    }

    private static function ready(string $type = 'full'): void
    {
        $s = Settings::get();
        if ($type !== 'database' && defined('WP_CONTENT_DIR')) {
            $content = realpath(WP_CONTENT_DIR);
            if ($content === false || !str_starts_with($content, self::root() . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('An external WP_CONTENT_DIR is not supported for file backups. Use a database-only backup.');
            }
        }
        if (is_multisite()) { throw new \RuntimeException('WordPress Multisite is not supported yet.'); }
        if (PHP_INT_SIZE < 8) { throw new \RuntimeException('Large files require 64-bit PHP.'); }
        foreach (['openssl_encrypt', 'gzdeflate', 'curl_init'] as $fn) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
            if (!function_exists($fn)) { throw new \RuntimeException('A required PHP function is unavailable on the server: ' . $fn); }
        }
        if (!$s['consent']) { throw new \RuntimeException('Confirm that encrypted backups may be sent to Telegram.'); }
        if (Settings::secret('token') === '' || trim((string) $s['chat_id']) === '') { throw new \RuntimeException('Enter the bot token and chat ID.'); }
        if (strlen(Settings::secret('password')) < 12) { throw new \RuntimeException('Set an archive password of at least 12 characters.'); }
        Storage::root();
    }

    private static function fingerprint(): string
    {
        $s = Settings::get();
        $keep = ['chat_id', 'thread_id', 'api_mode', 'api_url', 'part_mb', 'max_backup_mb', 'excludes'];
        $values = array_intersect_key($s, array_flip($keep));
        foreach (['password', 'token'] as $key) { $values[$key] = hash('sha256', Settings::secret($key)); }
        return hash('sha256', wp_json_encode($values));
    }

    private static function tables(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        self::db_error();
        if (!$tables) { throw new \RuntimeException('No WordPress tables were found with the expected prefix.'); }
        sort($tables, SORT_STRING);
        return $tables;
    }

    private static function database_estimate(): int
    {
        global $wpdb;
        $bytes = 0;
        foreach (self::tables() as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table), ARRAY_A);
            self::db_error();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
            if (!$row || !isset($row['Data_length'])) { throw new \RuntimeException('Could not estimate the database table: ' . $table); }
            // InnoDB allocated bytes are an estimate; the hard limit is also checked on every encrypted write.
            $bytes += max(65536, (int) $row['Data_length']);
        }
        return $bytes;
    }

    private static function table_where(string $table): string
    {
        global $wpdb;
        if ($table !== $wpdb->options) { return ''; }
        return " WHERE option_name NOT LIKE 'parcel\\_%' AND option_name NOT LIKE '\\_transient\\_parcel\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_parcel\\_%'";
    }

    private static function primary_columns(string $table): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump.
        $indexes = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
        self::db_error();
        $columns = [];
        foreach ($indexes as $index) {
            if ($index['Key_name'] === 'PRIMARY') { $columns[(int) $index['Seq_in_index']] = $index['Column_name']; }
        }
        ksort($columns);
        return array_values($columns);
    }

    private static function table_count(string $table): int
    {
        global $wpdb;
        // table_where returns only the fixed plugin-option exclusion clause, never caller SQL.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Backup export needs live schema/session/row data; caching would produce an inconsistent dump. The local builder uses fixed SQL fragments and wpdb placeholders for every dynamic identifier/value.
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . self::table_where($table), $table));
        self::db_error();
        return $count;
    }

    /** Build a bounded live read: every identifier/value is passed through a wpdb placeholder. */
    private static function rows_query(string $table, array $db, int $limit, bool $lengths): string
    {
        global $wpdb;
        if ($limit < 1 || $limit > 25 || (int) $db['offset'] < 0 || !$db['columns'] ||
            !is_string($db['charset']) || !preg_match('/^[a-zA-Z0-9_]+$/D', $db['charset'])) {
            throw new \RuntimeException('Invalid database export checkpoint.');
        }
        $expressions = []; $args = [];
        foreach ($db['columns'] as $column) {
            $expression = '%i'; $args[] = $column;
            if ($lengths) {
                if ($db['column_info'][$column]['collation'] !== null || preg_match('/^json\b/i', $db['column_info'][$column]['type'])) {
                    $expression = 'CONVERT(%i USING %i)'; $args[] = $db['charset'];
                }
                $expression = 'COALESCE(OCTET_LENGTH(' . $expression . '),0)';
            }
            $expressions[] = $expression;
        }
        $query = 'SELECT ' . ($lengths ? '(' . implode('+', $expressions) . ')' : implode(',', $expressions)) .
            ' FROM %i' . self::table_where($table);
        $args[] = $table;
        if ($db['order_columns']) {
            $query .= ' ORDER BY ' . implode(',', array_fill(0, count($db['order_columns']), '%i'));
            array_push($args, ...$db['order_columns']);
        }
        $query .= ' LIMIT %d OFFSET %d'; $args[] = $limit; $args[] = (int) $db['offset'];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed SQL templates above are concatenated; identifiers use %i and integers use %d.
        return $wpdb->prepare($query, $args);
    }

    public static function quote_id(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    private static function db_error(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Database read error: ' . Support::redact($wpdb->last_error)); }
    }

    private static function estimate(int $source, int $files, int $database): int
    {
        return (int) ceil($source * 1.02 + $database * 2.2 + $files * 1500 + 2097152);
    }

    private static function exclude_patterns(): array
    {
        return array_values(array_filter(array_map(static function ($p) { return trim(str_replace('\\', '/', $p), " \t\r\n/"); },
            preg_split('/\r\n|\r|\n/', (string) Settings::get()['excludes']))));
    }

    private static function excluded(string $path): bool
    {
        foreach (self::exclude_patterns() as $pattern) {
            if (strpos($pattern, '/') === false) {
                foreach (explode('/', $path) as $segment) { if (fnmatch($pattern, $segment)) { return true; } }
            } elseif ($path === $pattern || str_starts_with($path, $pattern . '/') || fnmatch($pattern, $path, FNM_PATHNAME)) { return true; }
        }
        return false;
    }

    private static function root(): string
    {
        $root = realpath(ABSPATH);
        if ($root === false) { throw new \RuntimeException('The WordPress directory was not found.'); }
        return rtrim($root, '/\\');
    }

    private static function list_directory(string $path): array
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if (!is_readable($path)) { throw new \RuntimeException('The directory is not readable: ' . $path); }
        $names = scandir($path, SCANDIR_SORT_ASCENDING);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($names === false) { throw new \RuntimeException('Could not read the directory: ' . $path); }
        return array_values(array_diff($names, ['.', '..']));
    }

    private static function safe_stat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if (!$stat) { throw new \RuntimeException('The source path is missing or unavailable: ' . $path); }
        $kind = $stat['mode'] & 0170000;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($kind === 0120000) { throw new \RuntimeException('Symbolic links are not backed up. Add this path to the exclusions: ' . $path); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($kind !== 0100000 && $kind !== 0040000) { throw new \RuntimeException('Unsupported source file type: ' . $path); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if (!is_readable($path)) { throw new \RuntimeException('The source file is not readable: ' . $path); }
        return $stat;
    }

    private static function valid_path(string $path): void
    {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, '\\') || str_contains($path, ':') || preg_match('/[\x00-\x1f\x7f]/', $path) ||
            !preg_match('//u', $path) || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)~', $path)) {
            throw new \RuntimeException('Unsupported filename. A safe UTF-8 path is required.');
        }
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/[. ]$/', $segment) || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $segment)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
                throw new \RuntimeException('Nonportable filename: ' . $path . '. Rename the file or add it to the exclusions.');
            }
        }
    }

    private static function verify_source(array $file): void
    {
        self::valid_path($file['path']);
        // Validate every ancestor; a directory replaced by a symlink must never escape the site root.
        $path = self::root();
        foreach (explode('/', $file['path']) as $segment) { $path .= '/' . $segment; self::safe_stat($path); }
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, self::root() . '/')) { throw new \RuntimeException('The source path resolves outside the site root.'); }
        $stat = self::safe_stat($path);
        if (!$file['directory']) { self::verify_stat($file, $stat); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        elseif (($stat['mode'] & 0170000) !== 0040000) { throw new \RuntimeException('The directory changed its file type: ' . $file['path']); }
    }

    private static function verify_stat(array $file, $stat): void
    {
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $file['size'] ||
            $stat['mtime'] !== $file['mtime'] || $stat['ctime'] !== $file['ctime'] || $stat['ino'] !== $file['inode']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
            throw new \RuntimeException('The file changed during backup: ' . $file['path'] . '. Create a new job.');
        }
    }

    private static function recover_index(string $dir, array $job): void
    {
        $path = $dir . '/file-index.ndjson';
        $expected = $job['scan_state']['index_bytes'];
        if (!is_file($path)) {
            if ($expected !== 0) { throw new \RuntimeException('The file index is missing.'); }
            return;
        }
        clearstatcache(true, $path);
        if (filesize($path) < $expected) { throw new \RuntimeException('The file index is truncated.'); }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        $h = fopen($path, 'c+b');
        if (!$h) { throw new \RuntimeException('Could not open the file index.'); }
        try { if (!ftruncate($h, $expected)) { throw new \RuntimeException('Could not restore the file index.'); } }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        finally { fclose($h); }
    }

    private static function save(array &$job): void { $job['updated_at'] = Support::now(); Storage::write_job($job); }
    private static function apply_cancel(array &$job): void
    {
        $job['status'] = 'cancelled'; $job['cancel_requested'] = false;
        $job['message'] = 'Job cancelled. Delivered parts remain in Telegram.';
        $marker = Storage::job_dir($job['id']) . '/cancel.request';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only validated engine-owned local files/directories; deletion failure must remain detectable.
        if (is_file($marker)) { @unlink($marker); }
    }
    private static function validate_type(string $type): void
    {
        if (!in_array($type, ['full', 'database', 'files'], true)) { throw new \RuntimeException('Unknown backup type.'); }
    }
    private static function job(string $id): array
    {
        $job = Storage::read_job($id);
        if (!$job) { throw new \RuntimeException('Job not found.'); }
        return $job;
    }
}

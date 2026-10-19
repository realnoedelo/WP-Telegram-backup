<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }

/** Owns one explicitly marked block in the current, non-root Unix user's crontab. */
final class SystemCron
{
    private const OPTION = 'parcel_system_cron';
    private const MAX_CRONTAB = 1048576;
    private static ?array $environment = null;

    public static function site_id(): string
    {
        global $wpdb;
        return hash('sha256', (realpath(ABSPATH) ?: ABSPATH) . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . ($wpdb->prefix ?? ''));
    }

    public static function status(bool $fresh = false): array
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        $key = 'parcel_system_cron_status_' . $uid;
        if (!$fresh) {
            $cached = get_transient($key);
            if (is_array($cached) && ($cached['site'] ?? '') === self::site_id()) { return $cached; }
        }
        $result = ['available' => false, 'installed' => false, 'present' => false, 'mode' => 'wp-cron',
            'error' => '', 'command' => '', 'user' => '', 'site' => self::site_id()];
        try {
            $env = self::environment();
            $result['user'] = $env['user'];
            self::check_user($env);
            $text = self::read_crontab($env);
            $block = self::block($text);
            $result['present'] = $block !== null;
            self::persistent_root();
            if ($env['php_error'] !== '') { throw new \RuntimeException($env['php_error']); }
            $command = self::command($env);
            $result['command'] = $command;
            if ($block !== null) { self::validate_owned_body($block['body'], $command, $env['uid']); }
            $result['available'] = true;
            $result['installed'] = $block !== null && $block['body'] === $command;
            $result['mode'] = $result['installed'] ? 'system' : 'wp-cron';
            if ($block !== null && !$result['installed']) { $result['error'] = 'The worker path has changed. Reinstall the managed cron schedule.'; }
        } catch (\Throwable $e) { $result['error'] = Support::redact($e->getMessage()); }
        set_transient($key, $result, 30);
        return $result;
    }

    public static function install(): array
    {
        self::invalidate();
        $env = self::environment(); self::check_user($env);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($env['php_error'] !== '') { throw new \RuntimeException($env['php_error']); }
        $root = self::persistent_root();
        $command = self::command($env);
        $lock = self::edit_lock($env);
        try {
            $before = self::read_crontab($env);
            $block = self::block($before);
            if ($block !== null) { self::validate_owned_body($block['body'], $command, $env['uid']); }
            self::verify_cli($env, $root);
            if ($block === null || $block['body'] !== $command) {
                $after = self::replace($before, $block, $command);
                // crontab has no compare-and-swap API. Refuse a detected concurrent external edit.
                if (self::read_crontab($env) !== $before) { throw new \RuntimeException('Crontab changed concurrently. Retry installation; the other changes were not overwritten.'); }
                self::write_crontab($env, $after);
                $verified = self::read_crontab($env);
                if (!self::equivalent($verified, $after)) { throw new \RuntimeException('Crontab was changed concurrently or by the server. Check the schedule; no automatic rollback was attempted to avoid overwriting other jobs.'); }
                $saved = self::block($verified);
                if ($saved === null || $saved['body'] !== $command) { throw new \RuntimeException('The server did not confirm installation of the managed cron block.'); }
            }
            update_option(self::OPTION, ['uid' => $env['uid'], 'user' => $env['user'], 'site' => self::site_id(),
                'command' => $command, 'installed_at' => Support::now()], false);
            Support::log('', 'info', 'System cron installed for user ' . $env['user'] . '.');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        } finally { flock($lock, LOCK_UN); fclose($lock); self::invalidate(); }
        return self::status(true);
    }

    public static function remove(): array
    {
        self::invalidate();
        $env = self::environment(); self::check_user($env);
        $saved = get_option(self::OPTION, []);
        if (is_array($saved) && isset($saved['uid']) && (int) $saved['uid'] !== $env['uid']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
            throw new \RuntimeException('The schedule was installed by a different OS user. Remove it as ' . ($saved['user'] ?? 'the owner') . '; the other crontab has not been changed.');
        }
        $lock = self::edit_lock($env);
        try {
            $before = self::read_crontab($env);
            $block = self::block($before);
            if ($block !== null) {
                $command = $env['php_error'] === '' ? self::command($env) : '';
                self::validate_owned_body($block['body'], $command, $env['uid']);
                $after = substr($before, 0, $block['start']) . substr($before, $block['end']);
                if (self::read_crontab($env) !== $before) { throw new \RuntimeException('Crontab changed concurrently. Retry removal; the other changes have been preserved.'); }
                self::write_crontab($env, $after);
                if (!self::equivalent(self::read_crontab($env), $after)) { throw new \RuntimeException('Could not confirm removal of the cron block. Check the OS user schedule.'); }
            }
            delete_option(self::OPTION);
            Support::log('', 'info', 'The managed system cron block has been removed.');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        } finally { flock($lock, LOCK_UN); fclose($lock); self::invalidate(); }
        return self::status(true);
    }

    private static function environment(): array
    {
        if (self::$environment !== null) { return self::$environment; }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        $account = $uid >= 0 && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        $env = ['uid' => $uid, 'user' => is_array($account) ? $account['name'] : (string) $uid,
            'crontab' => '', 'php' => '', 'error' => '', 'php_error' => '', 'lock_dir' => ''];
        if (DIRECTORY_SEPARATOR !== '/' || !in_array(PHP_OS_FAMILY, ['Linux', 'Darwin', 'BSD', 'Solaris'], true)) {
            $env['error'] = 'Automatic system cron setup is available only on Unix hosting.';
        } elseif ($uid < 0) { $env['error'] = 'PHP cannot identify the current OS user: posix_geteuid is required.'; }
        elseif ($uid === 0) { $env['error'] = 'Installing or changing cron as root is not allowed. Run this action as the PHP site user.'; }
        elseif (!function_exists('proc_open')) { $env['error'] = 'The hosting provider has disabled proc_open. Use WP-Cron or configure cron in the hosting control panel.'; }
        else {
            foreach (['/usr/bin/crontab', '/bin/crontab'] as $path) {
                if (is_file($path) && is_executable($path)) { $env['crontab'] = $path; break; }
            }
            if ($env['crontab'] === '') { $env['error'] = 'The crontab command is unavailable on this server. Use WP-Cron or the hosting control panel.'; }
        }
        if ($env['error'] === '') {
            // Shared per-user lock must not live in a site's storage or a PHP-FPM PrivateTmp namespace.
            $lockCandidates = ['/run/lock', '/var/lock'];
            if (is_array($account) && !empty($account['dir'])) { $lockCandidates[] = $account['dir']; }
            if (PHP_OS_FAMILY !== 'Linux') { $lockCandidates[] = '/tmp'; }
            foreach ($lockCandidates as $directory) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check actual local OS permissions for streaming and flock; FTP filesystem permissions are unrelated.
                if (is_dir($directory) && is_writable($directory)) { $env['lock_dir'] = realpath($directory) ?: $directory; break; }
            }
            if ($env['lock_dir'] === '') { $env['error'] = 'No shared writable cron lock directory is available (/run/lock, /var/lock or the user home directory). Automatic crontab changes are unavailable.'; }
        }
        if ($env['error'] === '') {
            $candidates = array_unique([PHP_SAPI === 'cli' ? PHP_BINARY : '', PHP_BINDIR . '/php', dirname(PHP_BINARY) . '/php',
                '/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8.5', '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php8.1']);
            foreach ($candidates as $path) {
                if ($path === '' || !is_file($path) || !is_executable($path)) { continue; }
                $path = realpath($path) ?: $path;
                try {
                    self::safe_path($path);
                    $code = 'echo json_encode(["sapi"=>PHP_SAPI,"version"=>PHP_VERSION_ID,"bits"=>PHP_INT_SIZE,"curl"=>extension_loaded("curl"),"openssl"=>extension_loaded("openssl"),"zlib"=>extension_loaded("zlib")]);';
                    $probe = self::process([$path, '-r', $code], '', 2.0);
                    $data = json_decode($probe['stdout'], true);
                    if ($probe['code'] === 0 && is_array($data) && ($data['sapi'] ?? '') === 'cli' && ($data['version'] ?? 0) >= 80100 &&
                        ($data['bits'] ?? 0) >= 8 && !empty($data['curl']) && !empty($data['openssl']) && !empty($data['zlib'])) {
                        $env['php'] = $path; break;
                    }
                } catch (\Throwable $e) { /* Try the next installed absolute PHP CLI binary. */ }
            }
            if ($env['php'] === '') { $env['php_error'] = 'No 64-bit PHP CLI 8.1+ with cURL, OpenSSL and zlib was found. Web PHP and CLI may have different extensions.'; }
        }
        return self::$environment = $env;
    }

    private static function check_user(array $env): void
    {
        // Recheck the uid, including cached environments: root is never permitted.
        if ($env['uid'] === 0) { throw new \RuntimeException('Reading or changing the managed crontab as root is not allowed. Use the PHP site user.'); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($env['error'] !== '') { throw new \RuntimeException($env['error']); }
        if ($env['uid'] < 0 || $env['crontab'] === '') { throw new \RuntimeException('System cron is unavailable to the current user.'); }
    }

    private static function persistent_root(): string
    {
        if (!defined('TELEGRAM_BACKUP_STORAGE_DIR') && !defined('PARCEL_STORAGE_DIR')) {
            throw new \RuntimeException('System cron requires persistent storage: set TELEGRAM_BACKUP_STORAGE_DIR outside the site directory. PHP-FPM and CLI may use different temporary directories.');
        }
        $root = Storage::root(); self::safe_path($root);
        return $root;
    }

    private static function command(array $env): string
    {
        $php = self::safe_path($env['php']);
        $runner = self::safe_path(realpath(PARCEL_DIR . 'tools/cron.php') ?: '');
        $root = self::safe_path(realpath(ABSPATH) ?: '');
        // Percent has its own crontab meaning even inside shell quotes, so safe_path explicitly rejects it.
        return '* * * * * ' . escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' ' .
            escapeshellarg('--root=' . $root) . ' ' . escapeshellarg('--site=' . self::site_id()) . ' > /dev/null 2>&1';
    }

    private static function safe_path(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || preg_match('/[%\x00-\x1f\x7f]/', $path)) {
            throw new \RuntimeException('Cron paths must be absolute and must not contain %, newlines or control characters.');
        }
        return $path;
    }

    private static function verify_cli(array $env, string $root): void
    {
        $probe = self::process([$env['php'], PARCEL_DIR . 'tools/cron.php', '--check', '--root=' . realpath(ABSPATH), '--site=' . self::site_id()], '', 12.0);
        $data = json_decode(trim($probe['stdout']), true);
        if ($probe['code'] !== 0 || !is_array($data) || empty($data['ok'])) {
            $detail = trim($probe['stderr']);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
            throw new \RuntimeException('The WordPress startup check through PHP CLI failed.' . ($detail !== '' ? ' ' . Support::redact($detail) : ' Check the CLI PHP configuration and plugin loading.'));
        }
        if (($data['site'] ?? '') !== self::site_id() || ($data['storage'] ?? '') !== $root ||
            (int) ($data['uid'] ?? -1) !== $env['uid'] || ($data['plugin'] ?? '') !== realpath(PARCEL_FILE)) {
            throw new \RuntimeException('CLI and web PHP use a different site, user or storage directory. The schedule was not installed.');
        }
    }

    private static function read_crontab(array $env): string
    {
        $result = self::process([$env['crontab'], '-l']);
        if ($result['code'] === 0) {
            if (strlen($result['stdout']) > self::MAX_CRONTAB) { throw new \RuntimeException('Crontab is too large for safe automatic editing.'); }
            return $result['stdout'];
        }
        if ($result['code'] === 1 && preg_match('/\bno crontab for\b/i', $result['stderr']) && trim($result['stdout']) === '') { return ''; }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        throw new \RuntimeException('The OS user cannot read its crontab: ' . Support::redact(trim($result['stderr']) ?: 'access denied by the hosting provider.'));
    }

    private static function write_crontab(array $env, string $text): void
    {
        $text = self::without_generated_headers($text);
        if (strlen($text) > self::MAX_CRONTAB || str_contains($text, "\0")) { throw new \RuntimeException('Invalid crontab content.'); }
        $result = self::process([$env['crontab'], '-'], $text);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is JSON/CLI data; the admin UI escapes it when rendering HTML.
        if ($result['code'] !== 0) { throw new \RuntimeException('The hosting provider did not allow writing crontab: ' . Support::redact(trim($result['stderr']) ?: 'the command failed.')); }
    }

    /** Byte offsets retain every unrelated comment, environment assignment and cron job. */
    private static function block(string $text): ?array
    {
        $id = self::site_id();
        $begin = '# BEGIN TELEGRAM BACKUP ' . $id; $end = '# END TELEGRAM BACKUP ' . $id;
        $startOffset = null; $bodyStart = null; $endOffset = null; $bodyEnd = null;
        $offset = 0;
        foreach (preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $plain = rtrim($line, "\r\n");
            if (str_contains($plain, $begin) || str_contains($plain, $end)) {
                if ($plain === $begin && $startOffset === null && $endOffset === null) { $startOffset = $offset; $bodyStart = $offset + strlen($line); }
                elseif ($plain === $end && $startOffset !== null && $endOffset === null) { $bodyEnd = $offset; $endOffset = $offset + strlen($line); }
                else { throw new \RuntimeException('The managed cron block is malformed or duplicated. Fix it manually; other jobs have not been changed.'); }
            }
            $offset += strlen($line);
        }
        if ($startOffset === null && $endOffset === null) { return null; }
        if ($startOffset === null || $endOffset === null || $bodyStart === null || $bodyEnd === null) { throw new \RuntimeException('An unterminated managed block was found in crontab. Automatic editing has stopped.'); }
        $body = substr($text, $bodyStart, $bodyEnd - $bodyStart);
        $body = str_replace("\r\n", "\n", $body);
        if (!str_ends_with($body, "\n")) { throw new \RuntimeException('The managed block is missing a trailing newline.'); }
        return ['start' => $startOffset, 'end' => $endOffset, 'body' => substr($body, 0, -1)];
    }

    private static function validate_owned_body(string $body, string $command, int $uid): void
    {
        if ($command !== '' && hash_equals($command, $body)) { return; }
        $saved = get_option(self::OPTION, []);
        if (is_array($saved) && ($saved['site'] ?? '') === self::site_id() && (int) ($saved['uid'] ?? -1) === $uid &&
            is_string($saved['command'] ?? null) && hash_equals($saved['command'], $body)) { return; }
        throw new \RuntimeException('The managed cron block was edited manually. Unknown lines cannot be deleted automatically; inspect the block in the hosting control panel.');
    }

    private static function replace(string $text, ?array $block, string $command): string
    {
        $new = '# BEGIN TELEGRAM BACKUP ' . self::site_id() . "\n" . $command . "\n# END TELEGRAM BACKUP " . self::site_id() . "\n";
        if ($block !== null) { return substr($text, 0, $block['start']) . $new . substr($text, $block['end']); }
        return $text . ($text !== '' && !str_ends_with($text, "\n") ? "\n" : '') . $new;
    }

    private static function equivalent(string $a, string $b): bool
    {
        // Some cron implementations prepend their generated installation header on `crontab -l`.
        return str_replace("\r\n", "\n", self::without_generated_headers($a)) === str_replace("\r\n", "\n", self::without_generated_headers($b));
    }

    private static function without_generated_headers(string $text): string
    {
        while (str_starts_with($text, '# DO NOT EDIT THIS FILE - edit the master and reinstall.')) {
            $lines = explode("\n", $text);
            if (!isset($lines[1], $lines[2]) || !str_starts_with($lines[1], '# (') || !str_starts_with($lines[2], '# (')) { break; }
            $text = implode("\n", array_slice($lines, 3));
        }
        return $text;
    }

    private static function edit_lock(array $env)
    {
        if (empty($env['lock_dir'])) { throw new \RuntimeException('The shared directory for the user crontab lock is unavailable.'); }
        $path = $env['lock_dir'] . '/.tg-backup-crontab-' . $env['uid'] . '.lock';
        if (is_link($path)) { throw new \RuntimeException('An unsafe symbolic link was found in place of the cron lock.'); }
        if (file_exists($path) && fileowner($path) !== $env['uid']) { throw new \RuntimeException('The shared cron lock file belongs to another user.'); }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        $handle = fopen($path, 'c+');
        if (!$handle) { throw new \RuntimeException('Could not open the cron configuration lock.'); }
        $stat = fstat($handle); $disk = lstat($path);
        if (!$stat || !$disk || $stat['uid'] !== $env['uid'] || ($stat['mode'] & 0170000) !== 0100000 ||
            $stat['ino'] !== $disk['ino'] || $stat['dev'] !== $disk['dev']) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
            fclose($handle); throw new \RuntimeException('The shared cron lock has an unsafe owner or file type.');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
        chmod($path, 0600);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new \RuntimeException('Cron setup is already running. Try again in a few seconds.'); }
        return $handle;
    }

    private static function invalidate(): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        delete_transient('parcel_system_cron_status_' . $uid);
    }

    /** Execute only an argv array, with bounded output/time and no shell interpolation. */
    private static function process(array $argv, string $input = '', float $timeout = 4.0): array
    {
        if (!function_exists('proc_open')) { throw new \RuntimeException('proc_open is unavailable on this hosting account.'); }
        $pipes = [];
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- System cron requires fixed-argv proc_open: no shell, non-root user, bounded time/output; documented for manual directory review.
        $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C'], ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException('Could not start the system command.'); }
        foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
        $out = ''; $err = ''; $offset = 0; $code = null; $deadline = microtime(true) + $timeout;
        try {
            while (true) {
                if (isset($pipes[0])) {
                    if ($offset < strlen($input)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                        $written = @fwrite($pipes[0], substr($input, $offset, 8192));
                        if ($written === false) { throw new \RuntimeException('The command refused to accept the crontab data.'); }
                        $offset += $written;
                    }
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                    if ($offset >= strlen($input)) { fclose($pipes[0]); unset($pipes[0]); }
                }
                $chunk = stream_get_contents($pipes[1]); if ($chunk !== false) { $out .= $chunk; }
                $chunk = stream_get_contents($pipes[2]); if ($chunk !== false) { $err .= $chunk; }
                if (strlen($out) > self::MAX_CRONTAB || strlen($err) > 65536) { throw new \RuntimeException('The system command returned too much output.'); }
                $state = proc_get_status($process);
                if (!$state['running']) { $code = $state['exitcode']; break; }
                if (microtime(true) >= $deadline) { throw new \RuntimeException('The system command timed out. Check the availability of cron and PHP CLI on the hosting account.'); }
                usleep(10000);
            }
            $chunk = stream_get_contents($pipes[1]); if ($chunk !== false) { $out .= $chunk; }
            $chunk = stream_get_contents($pipes[2]); if ($chunk !== false) { $err .= $chunk; }
        } finally {
            $state = proc_get_status($process);
            if ($state['running']) { proc_terminate($process, 15); usleep(50000); $state = proc_get_status($process); if ($state['running']) { proc_terminate($process, 9); } }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $closed = proc_close($process);
            if ($code === null || $code < 0) { $code = $closed; }
        }
        return ['code' => $code, 'stdout' => $out, 'stderr' => $err];
    }
}

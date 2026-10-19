#!/usr/bin/env php
<?php
/**
 * TG Backup standalone recovery tool. GPL-3.0-or-later.
 * Requires 64-bit PHP 8.1+, openssl and zlib. No WordPress or Composer needed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this recovery tool from the command line.'); }

final class ParcelRestore
{
    private const MAGIC = "PARCEL-VAULT-1\n";
    private const ZERO_CHAIN = '0000000000000000000000000000000000000000000000000000000000000000';
    private string $output;
    private array $seen = [];
    private ?array $current = null;
    private $handle = null;
    private int $files = 0;
    private int $directories = 0;
    private int $bytes = 0;
    private bool $started = false;
    private bool $complete = false;
    private ?array $start = null;
    private array $directoryTimes = [];

    public function run(string $input, string $output, string $password): array
    {
        if (PHP_INT_SIZE < 8 || PHP_VERSION_ID < 80100) { throw new RuntimeException('64-bit PHP 8.1 or newer is required.'); }
        if (!function_exists('openssl_decrypt') || !function_exists('gzinflate')) { throw new RuntimeException('PHP openssl and zlib extensions are required.'); }
        $input = realpath($input) ?: throw new RuntimeException('Input directory does not exist.');
        if (!is_dir($input) || !is_readable($input)) { throw new RuntimeException('Input must be a readable directory.'); }
        $parts = glob($input . '/part-*.pvlt') ?: [];
        sort($parts, SORT_STRING);
        if (!$parts) { throw new RuntimeException('No part-00001.pvlt files found in the input directory.'); }
        foreach ($parts as $index => $part) {
            if (basename($part) !== sprintf('part-%05d.pvlt', $index + 1) || is_link($part) || !is_file($part)) {
                throw new RuntimeException('Parts must be regular files, numbered consecutively from part-00001.pvlt.');
            }
        }
        $this->output = $this->prepareOutput($output);
        if ($this->output === $input || str_starts_with($input, $this->output . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Output may not contain the input directory.');
        }
        $marker = $this->output . '/PARCEL-INCOMPLETE.txt';
        $this->writeFile($marker, "RESTORATION INCOMPLETE. Do not deploy these files until PARCEL-VERIFIED.json exists.\n");
        $id = null; $salt = null; $key = null; $sequence = 0; $previous = null;
        try {
            foreach ($parts as $index => $part) {
                if ($this->complete) { throw new RuntimeException('Unexpected part after the authenticated completion record.'); }
                $h = fopen($part, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                if (!$h) { throw new RuntimeException('Cannot read part: ' . basename($part)); }
                try {
                    $magic = $this->readExact($h, strlen(self::MAGIC));
                    $line = fgets($h, 8193);
                    if ($magic !== self::MAGIC || $line === false || !str_ends_with($line, "\n") || strlen($line) > 8192) {
                        throw new RuntimeException('Invalid vault header in ' . basename($part));
                    }
                    $header = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($header) || ($header['format'] ?? '') !== 'parcel-vault' || ($header['version'] ?? 0) !== 1 ||
                        ($header['iterations'] ?? 0) !== 210000 || ($header['part'] ?? 0) !== $index + 1 ||
                        ($header['sequence'] ?? -1) !== $sequence || ($header['previous_sha256'] ?? null) !== $previous) {
                        throw new RuntimeException('Missing, reordered, altered or incompatible archive parts.');
                    }
                    if ($index === 0) {
                        $id = $header['id'] ?? null; $salt = $header['salt'] ?? null;
                        if (!is_string($id) || !is_string($salt)) { throw new RuntimeException('Invalid archive identifier or salt.'); }
                        $rawSalt = base64_decode($salt, true);
                        if ($rawSalt === false || strlen($rawSalt) !== 32) { throw new RuntimeException('Invalid archive salt.'); }
                        $key = hash_pbkdf2('sha256', $password, $rawSalt, 210000, 32, true);
                    } elseif (($header['id'] ?? null) !== $id || ($header['salt'] ?? null) !== $salt) {
                        throw new RuntimeException('Parts belong to different archives.');
                    }
                    $headerBytes = $magic . $line;
                    $records = 0;
                    while (true) {
                        $lengthBytes = fread($h, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                        if ($lengthBytes === false) { throw new RuntimeException('Failed to read archive.'); }
                        if ($lengthBytes === '' && feof($h)) { break; }
                        if ($this->complete) { throw new RuntimeException('Unexpected data after archive completion.'); }
                        if (strlen($lengthBytes) !== 4) { throw new RuntimeException('Truncated record length.'); }
                        $length = unpack('N', $lengthBytes)[1];
                        if ($length < 28 || $length > 1200000) { throw new RuntimeException('Invalid encrypted record length.'); }
                        $frame = $this->readExact($h, $length);
                        $plain = openssl_decrypt(substr($frame, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                            substr($frame, 0, 12), substr($frame, 12, 16), $headerBytes . 'record:' . $sequence);
                        if ($plain === false) { throw new RuntimeException('Authentication failed. Wrong password, altered header or corrupted record.'); }
                        $expanded = @gzinflate($plain, 1114120);
                        if ($expanded === false || strlen($expanded) < 4) { throw new RuntimeException('Invalid or oversized compressed record.'); }
                        $metaLength = unpack('N', substr($expanded, 0, 4))[1];
                        if ($metaLength < 2 || $metaLength > 65536 || $metaLength + 4 > strlen($expanded)) { throw new RuntimeException('Invalid record metadata.'); }
                        $meta = json_decode(substr($expanded, 4, $metaLength), true, 64, JSON_THROW_ON_ERROR);
                        if (!is_array($meta)) { throw new RuntimeException('Invalid metadata type.'); }
                        $data = substr($expanded, 4 + $metaLength);
                        if (strlen($data) > 1048576) { throw new RuntimeException('Oversized source chunk.'); }
                        $this->record($meta, $data);
                        $sequence++; $records++;
                    }
                    if ($records === 0) { throw new RuntimeException('Archive part has no authenticated records.'); }
                } finally { fclose($h); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                $previous = hash_file('sha256', $part);
                if ($previous === false) { throw new RuntimeException('Cannot verify part hash.'); }
            }
            if (!$this->complete || $this->current !== null || $this->handle !== null) {
                throw new RuntimeException('Archive is incomplete: authenticated completion record is missing.');
            }
            foreach (array_reverse($this->directoryTimes) as $directory) { @touch($directory['path'], $directory['mtime']); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            $report = ['verified' => true, 'format' => 'parcel-vault', 'version' => 1, 'id' => $id,
                'files' => $this->files, 'directories' => $this->directories, 'bytes' => $this->bytes,
                'parts' => count($parts), 'verified_at' => gmdate('c'), 'backup' => $this->start,
                'notice' => 'Cryptographic completeness verified. Inspect the files and test the database in an isolated environment before deployment.'];
            $this->writeFile($this->output . '/PARCEL-VERIFIED.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            if (!unlink($marker)) { throw new RuntimeException('Recovery succeeded but could not remove the incomplete marker.'); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            return $report;
        } finally {
            if (is_resource($this->handle)) { fclose($this->handle); $this->handle = null; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            if (function_exists('sodium_memzero') && is_string($key)) { sodium_memzero($key); }
        }
    }

    private function record(array $m, string $data): void
    {
        $type = $m['type'] ?? '';
        if (!$this->started && $type !== 'start') { throw new RuntimeException('Archive must begin with a start record.'); }
        if ($type !== 'data' && $data !== '') { throw new RuntimeException('Unexpected data on a metadata record.'); }
        switch ($type) {
            case 'start':
                if ($this->started || $this->current !== null) { throw new RuntimeException('Duplicate start record.'); }
                $this->started = true; $this->start = $m;
                break;
            case 'directory':
                if ($this->current !== null) { throw new RuntimeException('Directory inside an unfinished file.'); }
                $path = $this->path($m['path'] ?? null);
                if (isset($this->seen[$path])) { throw new RuntimeException('Duplicate archive path.'); }
                $this->seen[$path] = true;
                $this->makeParents($path . '/placeholder');
                $this->directoryTimes[] = ['path' => $path, 'mtime' => $this->integer($m, 'mtime')];
                $this->directories++;
                break;
            case 'file_begin':
                if ($this->current !== null) { throw new RuntimeException('Nested file entry.'); }
                $entry = $this->integer($m, 'entry');
                if ($entry !== $this->files + 1) { throw new RuntimeException('File entry sequence mismatch.'); }
                $path = $this->path($m['path'] ?? null);
                if (isset($this->seen[$path]) || file_exists($path) || is_link($path)) { throw new RuntimeException('Duplicate or existing output path.'); }
                $size = $m['size'] ?? null;
                if ($size !== null && (!is_int($size) || $size < 0)) { throw new RuntimeException('Invalid file size.'); }
                $this->seen[$path] = true;
                $this->makeParents($path);
                $this->handle = fopen($path, 'xb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                if (!$this->handle) { throw new RuntimeException('Cannot create output file.'); }
                chmod($path, 0600); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                $this->current = ['entry' => $entry, 'path' => $path, 'size' => $size, 'bytes' => 0,
                    'chain' => self::ZERO_CHAIN, 'mtime' => $this->integer($m, 'mtime')];
                break;
            case 'data':
                if ($this->current === null || !is_resource($this->handle) ||
                    $this->integer($m, 'entry') !== $this->current['entry'] ||
                    $this->integer($m, 'offset') !== $this->current['bytes'] || $data === '') {
                    throw new RuntimeException('Invalid file chunk order or offset.');
                }
                $free = disk_free_space($this->output);
                if ($free !== false && $free < strlen($data) + 1048576) { throw new RuntimeException('Insufficient free disk space for restoration.'); }
                $this->writeAll($this->handle, $data);
                $this->current['chain'] = hash('sha256', hex2bin($this->current['chain']) . hash('sha256', $data, true));
                $this->current['bytes'] += strlen($data);
                if ($this->current['size'] !== null && $this->current['bytes'] > $this->current['size']) { throw new RuntimeException('File exceeds declared size.'); }
                break;
            case 'file_end':
                if ($this->current === null || $this->integer($m, 'entry') !== $this->current['entry'] ||
                    $this->integer($m, 'bytes') !== $this->current['bytes'] ||
                    !is_string($m['chain_sha256'] ?? null) || !hash_equals($this->current['chain'], $m['chain_sha256']) ||
                    ($this->current['size'] !== null && $this->current['size'] !== $this->current['bytes'])) {
                    throw new RuntimeException('File integrity or size mismatch.');
                }
                if (!fflush($this->handle)) { throw new RuntimeException('Cannot flush restored file.'); }
                if (function_exists('fsync') && !fsync($this->handle)) { throw new RuntimeException('Cannot sync restored file.'); }
                fclose($this->handle); $this->handle = null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                // Intentionally safe permissions; original mode is informational and never grants execution.
                @touch($this->current['path'], $this->current['mtime']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
                $this->bytes += $this->current['bytes']; $this->files++; $this->current = null;
                break;
            case 'complete':
                if ($this->current !== null || $this->integer($m, 'files') !== $this->files ||
                    $this->integer($m, 'directories') !== $this->directories || $this->integer($m, 'bytes') !== $this->bytes) {
                    throw new RuntimeException('Final completeness counts do not match the archive.');
                }
                $this->complete = true;
                break;
            default: throw new RuntimeException('Unsupported archive record type.');
        }
    }

    private function path($relative): string
    {
        if (!is_string($relative) || $relative === '' || strlen($relative) > 4096 || !preg_match('//u', $relative) ||
            preg_match('/[\x00-\x1f\x7f\\\\:]/', $relative) || str_starts_with($relative, '/') ||
            preg_match('~(^|/)\.\.?(/|$)~', $relative) || str_contains($relative, '//') || str_ends_with($relative, '/')) {
            throw new RuntimeException('Unsafe or invalid archive path.');
        }
        if ($relative !== 'database.sql' && !str_starts_with($relative, 'files/')) {
            throw new RuntimeException('Archive path is outside the approved files/database layout.');
        }
        foreach (explode('/', $relative) as $segment) {
            if (preg_match('/[. ]$/', $segment) || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $segment)) {
                throw new RuntimeException('Archive contains a path unsafe on common restore platforms.');
            }
        }
        return $this->output . '/' . $relative;
    }

    private function makeParents(string $path): void
    {
        $parent = dirname($path);
        $relative = substr($parent, strlen($this->output));
        $cursor = $this->output;
        foreach (explode('/', trim($relative, '/')) as $segment) {
            if ($segment === '') { continue; }
            $cursor .= '/' . $segment;
            if (is_link($cursor)) { throw new RuntimeException('Symlinks are not allowed in output paths.'); }
            if (!file_exists($cursor)) {
                if (!mkdir($cursor, 0700)) { throw new RuntimeException('Cannot create output directory.'); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            } elseif (!is_dir($cursor)) { throw new RuntimeException('Output parent is not a directory.'); }
        }
    }

    private function prepareOutput(string $output): string
    {
        if ($output === '' || str_contains($output, "\0")) { throw new RuntimeException('Specify a new empty output directory.'); }
        if (is_link($output)) { throw new RuntimeException('Output may not be a symlink.'); }
        $parent = realpath(dirname($output));
        if ($parent === false || !is_dir($parent)) { throw new RuntimeException('Output parent directory must already exist.'); }
        $name = basename($output);
        if ($name === '.' || $name === '..' || $name === '') { throw new RuntimeException('Output must be a named new directory.'); }
        $path = $parent . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) { throw new RuntimeException('Output may not be a symlink.'); }
        if (file_exists($path)) {
            if (!is_dir($path)) { throw new RuntimeException('Output exists and is not a directory.'); }
            $entries = scandir($path);
            if ($entries === false || count($entries) !== 2) { throw new RuntimeException('Output directory must be empty; nothing will be overwritten.'); }
        } elseif (!mkdir($path, 0700)) { throw new RuntimeException('Cannot create output directory.'); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
        chmod($path, 0700); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
        return realpath($path) ?: throw new RuntimeException('Cannot resolve output directory.');
    }

    private function integer(array $m, string $key): int
    {
        if (!isset($m[$key]) || !is_int($m[$key]) || $m[$key] < 0) { throw new RuntimeException('Invalid integer in ' . $key); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is data for JSON or CLI; the UI escapes at its output boundary.
        return $m[$key];
    }

    private function readExact($h, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($h, $length - strlen($data)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            if ($chunk === false || $chunk === '') { throw new RuntimeException('Truncated archive.'); }
            $data .= $chunk;
        }
        return $data;
    }

    private function writeAll($h, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $n = fwrite($h, substr($data, $offset)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            if ($n === false || $n === 0) { throw new RuntimeException('Cannot write restored file; check disk capacity.'); }
            $offset += $n;
        }
    }

    private function writeFile(string $path, string $data): void
    {
        $h = fopen($path, 'xb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
        if (!$h) { throw new RuntimeException('Cannot create verification marker.'); }
        try { $this->writeAll($h, $data); fflush($h); } finally { fclose($h); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
        chmod($path, 0600); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
    }
}

function parcel_restore_password(bool $stdin): string
{
    $env = getenv('TELEGRAM_BACKUP_PASSWORD');
    if ($env === false || $env === '') { $env = getenv('PARCEL_BACKUP_PASSWORD'); }
    if ($env !== false && $env !== '') { return $env; }
    if ($stdin) {
        $line = fgets(STDIN, 65537);
        if ($line === false) { throw new RuntimeException('No password on standard input.'); }
        return rtrim($line, "\r\n");
    }
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new RuntimeException('Use --password-stdin or TELEGRAM_BACKUP_PASSWORD for non-interactive recovery. Never pass a password as a CLI argument.');
    }
    if (PHP_OS_FAMILY === 'Windows' || !function_exists('shell_exec')) {
        throw new RuntimeException('Hidden password prompt is unavailable. Use --password-stdin or TELEGRAM_BACKUP_PASSWORD.');
    }
    fwrite(STDERR, 'Archive password (hidden): '); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
    $mode = shell_exec('stty -g');
    if (!is_string($mode) || trim($mode) === '') { throw new RuntimeException('Cannot disable password echo; use --password-stdin.'); }
    shell_exec('stty -echo');
    try { $line = fgets(STDIN, 65537); }
    finally { shell_exec('stty ' . escapeshellarg(trim($mode))); fwrite(STDERR, "\n"); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
    if ($line === false) { throw new RuntimeException('Password was not read.'); }
    return rtrim($line, "\r\n");
}

// Library mode is for independent recovery tests; normal invocation always executes the CLI.
if (!defined('PARCEL_RESTORE_LIBRARY')) {
    try {
        $parcel_restore_args = getopt('', ['input:', 'output:', 'password-stdin', 'help']);
        if (isset($parcel_restore_args['help'])) {
            fwrite(STDOUT, "TG Backup recovery\nUsage: php restore.php --input=/path/to/parts --output=/new/empty/folder [--password-stdin]\nPassword: hidden terminal prompt, stdin flag, or TELEGRAM_BACKUP_PASSWORD environment variable.\nA nonzero exit leaves PARCEL-INCOMPLETE.txt. Never use an incomplete restore.\nNo existing website or database is modified.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            exit(0);
        }
        if (empty($parcel_restore_args['input']) || empty($parcel_restore_args['output'])) { throw new RuntimeException('Required: --input=/path/to/parts --output=/new/empty/folder. Use --help for instructions.'); }
        $parcel_restore_password = parcel_restore_password(isset($parcel_restore_args['password-stdin']));
        if ($parcel_restore_password === '') { throw new RuntimeException('Password cannot be empty.'); }
        $parcel_restore_report = (new ParcelRestore())->run($parcel_restore_args['input'], $parcel_restore_args['output'], $parcel_restore_password);
        if (function_exists('sodium_memzero')) { sodium_memzero($parcel_restore_password); }
        fwrite(STDOUT, sprintf("VERIFIED: %d files, %d directories, %d bytes, %d parts.\nOutput: %s\nInspect PARCEL-VERIFIED.json. Database SQL was extracted, not imported.\n", // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
            $parcel_restore_report['files'], $parcel_restore_report['directories'], $parcel_restore_report['bytes'], $parcel_restore_report['parts'], realpath($parcel_restore_args['output'])));
        exit(0);
    } catch (Throwable $parcel_restore_e) {
        fwrite(STDERR, 'RESTORE FAILED: ' . $parcel_restore_e->getMessage() . "\nNo completed restore should be deployed. Use a fresh empty output directory for the next attempt.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI recovery has no WordPress filesystem API; streams and private permissions are required.
        exit(1);
    }
}

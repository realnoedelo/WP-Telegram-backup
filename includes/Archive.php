<?php
/** Authenticated, resumable multipart vault writer. */
namespace ParcelBackups;

if (!defined('ABSPATH')) { exit; }

final class Archive
{
    public const MAGIC = "PARCEL-VAULT-1\n";
    public const CHUNK = 1048576;
    public const ITERATIONS = 210000;
    private static array $keys = [];

    public static function initial(string $id): array
    {
        return ['id' => $id, 'salt' => base64_encode(random_bytes(32)), 'sequence' => 0,
            'part' => 0, 'offset' => 0, 'total_bytes' => 0, 'parts' => [], 'header' => '', 'sealed' => false];
    }

    /** Discard writes made after the last durable job checkpoint. */
    public static function recover(string $dir, array $state): void
    {
        if ($state['part'] > 0) {
            $current = $dir . '/' . self::part_name($state['part']);
            clearstatcache(true, $current);
            if (!is_file($current) || is_link($current) || filesize($current) < $state['offset']) {
                throw new \RuntimeException('The current archive part is missing or truncated. Create a new job.');
            }
        }
        foreach (glob($dir . '/part-*.pvlt') ?: [] as $path) {
            if (!preg_match('/^part-(\d{5})\.pvlt$/D', basename($path), $m)) { continue; }
            $part = (int) $m[1];
            if ($part > (int) $state['part']) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only validated engine-owned local files/directories; deletion failure must remain detectable.
                if (!unlink($path)) { throw new \RuntimeException('Could not delete the unfinished archive part.'); }
            } elseif ($part === (int) $state['part'] && !$state['sealed']) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                $h = fopen($path, 'c+b');
                if (!$h || !ftruncate($h, (int) $state['offset'])) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                    if (is_resource($h)) { fclose($h); }
                    throw new \RuntimeException('Could not restore the archive checkpoint.');
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
                fclose($h);
            }
        }
        foreach ($state['parts'] as $part) {
            $path = $dir . '/' . $part['name'];
            if (!is_file($path) || filesize($path) !== $part['bytes']) {
                throw new \RuntimeException('A completed local archive part is missing.');
            }
        }
    }

    public static function append(string $dir, array &$state, string $password, int $partLimit,
        int $backupLimit, string $type, array $meta = [], string $data = ''): void
    {
        if ($state['sealed']) { throw new \RuntimeException('The archive is already complete.'); }
        if (strlen($data) > self::CHUNK) { throw new \RuntimeException('The archive chunk is too large.'); }
        $meta['type'] = $type;
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) { throw new \RuntimeException('The archive metadata is too large.'); }
        $plain = pack('N', strlen($json)) . $json . $data;
        $compressed = gzdeflate($plain, 3);
        if ($compressed === false) { throw new \RuntimeException('Chunk compression failed.'); }
        // Frame: uint32 length, random 96-bit nonce, 128-bit GCM tag, ciphertext.
        $frameBytes = 4 + 12 + 16 + strlen($compressed);
        if ($state['part'] === 0) { self::start_part($dir, $state, $backupLimit); }
        if ($state['offset'] + $frameBytes > $partLimit) {
            self::close_part($dir, $state);
            self::start_part($dir, $state, $backupLimit);
        }
        if ($state['offset'] + $frameBytes > $partLimit) {
            throw new \RuntimeException('The part size limit is too small for an archive chunk.');
        }
        self::check_limit($state, $frameBytes, $backupLimit);
        Storage::ensure_space($frameBytes, $state['id']);
        $nonce = random_bytes(12);
        $aad = $state['header'] . 'record:' . $state['sequence'];
        $tag = '';
        $cipher = openssl_encrypt($compressed, 'aes-256-gcm', self::key($password, $state['salt']),
            OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
        if ($cipher === false || strlen($tag) !== 16) { throw new \RuntimeException('Could not encrypt the archive.'); }
        $frame = pack('N', strlen($cipher) + 28) . $nonce . $tag . $cipher;
        $path = $dir . '/' . self::part_name($state['part']);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        $h = fopen($path, 'c+b');
        if (!$h) { throw new \RuntimeException('Could not open the archive part.'); }
        try {
            if (fseek($h, (int) $state['offset']) !== 0) { throw new \RuntimeException('Could not seek to the archive offset.'); }
            self::write_all($h, $frame);
            if (!fflush($h)) { throw new \RuntimeException('Could not save the archive chunk.'); }
            if (function_exists('fsync') && !fsync($h)) { throw new \RuntimeException('Could not sync the archive chunk to disk.'); }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        } finally { fclose($h); }
        $state['offset'] += strlen($frame);
        $state['total_bytes'] += strlen($frame);
        $state['sequence']++;
    }

    public static function seal(string $dir, array &$state, string $password, int $partLimit,
        int $backupLimit, array $summary): void
    {
        self::append($dir, $state, $password, $partLimit, $backupLimit, 'complete', $summary);
        self::close_part($dir, $state);
        $state['sealed'] = true;
    }

    public static function chain(string $previous, string $data): string
    {
        return hash('sha256', hex2bin($previous) . hash('sha256', $data, true));
    }

    private static function key(string $password, string $salt): string
    {
        $cache = hash('sha256', $password . "\0" . $salt);
        if (!isset(self::$keys[$cache])) {
            $raw = base64_decode($salt, true);
            if ($raw === false || strlen($raw) !== 32) { throw new \RuntimeException('Invalid archive salt.'); }
            self::$keys = [$cache => hash_pbkdf2('sha256', $password, $raw, self::ITERATIONS, 32, true)];
        }
        return self::$keys[$cache];
    }

    private static function start_part(string $dir, array &$state, int $backupLimit): void
    {
        if ($state['part'] >= 99999) { throw new \RuntimeException('The maximum number of archive parts has been exceeded.'); }
        $state['part']++;
        $previous = $state['parts'] ? end($state['parts'])['sha256'] : null;
        $header = ['format' => 'parcel-vault', 'version' => 1, 'id' => $state['id'],
            'part' => $state['part'], 'salt' => $state['salt'], 'iterations' => self::ITERATIONS,
            'sequence' => $state['sequence'], 'previous_sha256' => $previous];
        $state['header'] = self::MAGIC . json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        self::check_limit($state, strlen($state['header']), $backupLimit);
        Storage::ensure_space(strlen($state['header']), $state['id']);
        $path = $dir . '/' . self::part_name($state['part']);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        $h = fopen($path, 'xb');
        if (!$h) { throw new \RuntimeException('Could not create a new archive part.'); }
        try { self::write_all($h, $state['header']); fflush($h); if (function_exists('fsync')) { fsync($h); } }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
        finally { fclose($h); }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
        chmod($path, 0600);
        $state['offset'] = strlen($state['header']);
        $state['total_bytes'] += $state['offset'];
    }

    private static function close_part(string $dir, array &$state): void
    {
        $name = self::part_name($state['part']);
        $path = $dir . '/' . $name;
        clearstatcache(true, $path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size !== $state['offset'] || $hash === false) { throw new \RuntimeException('Verification of the written archive part failed.'); }
        $state['parts'][] = ['name' => $name, 'bytes' => $size, 'sha256' => $hash, 'sent' => false];
    }

    private static function check_limit(array $state, int $additional, int $limit): void
    {
        if ($state['total_bytes'] + $additional > $limit) {
            throw new \RuntimeException('The archive has reached its maximum size. Increase the limit or exclude unnecessary files.');
        }
    }

    private static function part_name(int $part): string { return sprintf('part-%05d.pvlt', $part); }

    public static function write_all($handle, string $bytes): void
    {
        $offset = 0; $length = strlen($bytes);
        while ($offset < $length) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
            $n = fwrite($handle, substr($bytes, $offset));
            if ($n === false || $n === 0) { throw new \RuntimeException('Could not write the file: check available disk space.'); }
            $offset += $n;
        }
    }
}

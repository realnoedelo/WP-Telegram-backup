<?php
/** Real loopback HTTP and standalone CLI checks; no WordPress installation is loaded. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$base = sys_get_temp_dir() . '/tg-backup-entry-test-' . bin2hex(random_bytes(5));
$passed = 0;
$server = null;
function entryCheck(bool $ok, string $message): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('ASSERT: ' . $message); }
    $passed++;
    echo "PASS $message\n";
}
function entryRemove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $file) { entryRemove($file->getPathname()); }
        rmdir($path);
    } else { unlink($path); }
}
function entryCommand(array $arguments, string $directory, array $environment): array {
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file='], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start CLI fixture.'); }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $output = ''; $error = ''; $deadline = microtime(true) + 10;
    try {
        do {
            $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (microtime(true) >= $deadline) { throw new RuntimeException('CLI fixture timed out.'); }
            usleep(10000);
        } while (true);
        $output .= stream_get_contents($pipes[1]); $error .= stream_get_contents($pipes[2]);
        return [$status['exitcode'], $output, $error];
    } finally {
        if (proc_get_status($process)['running']) { proc_terminate($process); }
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
}
function entryRequest(string $address, string $path): array {
    $socket = stream_socket_client('tcp://' . $address, $errno, $error, 2);
    if (!$socket) { throw new RuntimeException('Loopback request failed: ' . $error); }
    try {
        stream_set_timeout($socket, 5);
        fwrite($socket, "GET $path HTTP/1.0\r\nHost: $address\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        if (stream_get_meta_data($socket)['timed_out']) { throw new RuntimeException('HTTP guard request timed out.'); }
        preg_match('/^HTTP\/1\.[01] ([0-9]{3}) /', $response, $match);
        return [(int) ($match[1] ?? 0), $response];
    } finally { fclose($socket); }
}

mkdir($base, 0700);
try {
    foreach (['tools', 'site', 'state'] as $name) { mkdir($base . '/' . $name, 0700); }
    // Verbatim copies preserve the shipped guards while isolating the test from a real site.
    foreach (['cron', 'restore'] as $name) {
        if (!copy(dirname(__DIR__) . '/tools/' . $name . '.php', $base . '/tools/' . $name . '.php')) {
            throw new RuntimeException('Cannot copy tool fixture.');
        }
        file_put_contents($base . '/included-' . $name . '.php', "<?php\n"
            . "define('ABSPATH', __DIR__ . '/site/');\n"
            . "require __DIR__ . '/tools/" . $name . ".php';\n"
            . "file_put_contents(__DIR__ . '/continued', 'unexpected execution');\n");
    }
    file_put_contents($base . '/site/wp-load.php', "<?php file_put_contents(__DIR__ . '/../bootstrapped', 'unexpected WordPress bootstrap');");
    file_put_contents($base . '/probe.php', <<<'PHP'
<?php
register_shutdown_function(function (): void {
    file_put_contents(__DIR__ . '/state/' . hash('sha256', $_SERVER['REQUEST_URI']) . '.json', json_encode([
        'abspath' => defined('ABSPATH'),
        'cron_started' => array_key_exists('parcel_cron_exit', $GLOBALS),
        'restore_started' => array_key_exists('parcel_restore_args', $GLOBALS),
        'wordpress_loaded' => in_array(__DIR__ . '/site/wp-load.php', get_included_files(), true),
    ], JSON_THROW_ON_ERROR));
});
PHP
    );
    $environment = getenv();
    unset($environment['PHP_CLI_SERVER_WORKERS'], $environment['TELEGRAM_BACKUP_PASSWORD'], $environment['PARCEL_BACKUP_PASSWORD']);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) { throw new RuntimeException('Cannot reserve loopback port: ' . $error); }
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $server = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $base . '/probe.php', '-d', 'auto_append_file=', '-S', $address, '-t', $base],
        [0 => ['pipe', 'r'], 1 => ['file', $base . '/http.log', 'a'], 2 => ['file', $base . '/http.log', 'a']], $pipes, $base, $environment);
    if (!is_resource($server)) { throw new RuntimeException('Cannot start loopback PHP server.'); }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        if (!proc_get_status($server)['running']) { break; }
        usleep(20000);
    }
    if (!$ready) { throw new RuntimeException('PHP server did not start: ' . file_get_contents($base . '/http.log')); }
    foreach (['cron', 'restore'] as $name) {
        foreach ([false, true] as $included) {
            $path = $included ? '/included-' . $name . '.php' : '/tools/' . $name . '.php';
            [$status, $response] = entryRequest($address, $path);
            $state = json_decode(file_get_contents($base . '/state/' . hash('sha256', $path) . '.json'), true, 8, JSON_THROW_ON_ERROR);
            entryCheck($status === 403 && $state === [
                'abspath' => $included, 'cron_started' => false, 'restore_started' => false, 'wordpress_loaded' => false,
            ], $name . ($included ? ' included with ABSPATH' : ' directly without ABSPATH') . ' refuses HTTP before runtime work: ' . json_encode(['status' => $status, 'state' => $state]));
        }
    }
    entryCheck(!file_exists($base . '/continued') && !file_exists($base . '/bootstrapped'),
        'HTTP guards exit without returning to the caller or loading WordPress');

    [$code, $output, $error] = entryCommand([$base . '/tools/restore.php', '--help'], $base, $environment);
    entryCheck($code === 0 && str_contains($output, 'Usage: php restore.php') && $error === '',
        'standalone restore --help works without WordPress or ABSPATH');
    foreach ([[], ['--root=' . $base . '/site', '--site=invalid']] as $arguments) {
        [$code, $output, $error] = entryCommand(array_merge([$base . '/tools/cron.php'], $arguments), $base, $environment);
        entryCheck($code > 0 && $output === '' && str_contains($error, 'System cron:') && !file_exists($base . '/bootstrapped'),
            'cron ' . ($arguments ? 'invalid arguments' : 'missing arguments') . ' fails explicitly before bootstrap');
    }
    echo "TOTAL $passed checks passed. Only an ephemeral loopback server was used.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    entryRemove($base);
}

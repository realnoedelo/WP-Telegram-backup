<?php
/** Local-only SOCKS5 test fixture. No production use. */
if (PHP_SAPI !== 'cli' || !isset($argv[1], $argv[2]) || !preg_match('/^127\.0\.0\.1:[0-9]+$/D', $argv[1])) { http_response_code(404); exit(1); }
$server = stream_socket_server('tcp://' . $argv[1], $errno, $error);
if (!$server) { throw new RuntimeException('SOCKS fixture cannot listen'); }
function take_bytes($stream, int $size): string {
    $data = '';
    while (strlen($data) < $size) {
        $chunk = fread($stream, $size - strlen($data));
        if ($chunk === false || $chunk === '') { throw new RuntimeException('Client closed'); }
        $data .= $chunk;
    }
    return $data;
}
function write_bytes($stream, string $data): void {
    for ($offset = 0; $offset < strlen($data);) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) { throw new RuntimeException('Write closed'); }
        $offset += $written;
    }
}
while ($client = stream_socket_accept($server, -1)) {
    $upstream = null;
    try {
        stream_set_timeout($client, 10);
        $hello = take_bytes($client, 2);
        if (ord($hello[0]) !== 5) { throw new RuntimeException('Not SOCKS5'); }
        $methods = take_bytes($client, ord($hello[1]));
        if (!str_contains($methods, "\x02")) { write_bytes($client, "\x05\xff"); throw new RuntimeException('Authentication required'); }
        write_bytes($client, "\x05\x02");
        $auth = take_bytes($client, 2);
        $username = take_bytes($client, ord($auth[1]));
        $password = take_bytes($client, ord(take_bytes($client, 1)));
        if (ord($auth[0]) !== 1 || $username !== 'parcel-test' || $password !== 'private-proxy-value') {
            write_bytes($client, "\x01\x01"); throw new RuntimeException('Wrong test credentials');
        }
        write_bytes($client, "\x01\x00");
        $request = take_bytes($client, 4);
        if (ord($request[0]) !== 5 || ord($request[1]) !== 1) { throw new RuntimeException('CONNECT required'); }
        $type = ord($request[3]);
        $destination = match ($type) {
            1 => inet_ntop(take_bytes($client, 4)),
            3 => take_bytes($client, ord(take_bytes($client, 1))),
            4 => inet_ntop(take_bytes($client, 16)),
            default => throw new RuntimeException('Unsupported address type'),
        };
        $port = unpack('n', take_bytes($client, 2))[1];
        // This fixture deliberately cannot connect to anything except loopback.
        if (!in_array($destination, ['localhost', '127.0.0.1', '::1'], true)) { throw new RuntimeException('Non-loopback forbidden'); }
        file_put_contents($argv[2], json_encode(['auth_ok' => true, 'address_type' => $type, 'destination' => $destination, 'port' => $port]) . "\n", FILE_APPEND | LOCK_EX);
        $upstream = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);
        if (!$upstream) { throw new RuntimeException('Cannot reach fixture receiver'); }
        write_bytes($client, "\x05\x00\x00\x01\x7f\x00\x00\x01" . pack('n', $port));
        stream_set_timeout($upstream, 10);
        while (!feof($client) && !feof($upstream)) {
            $read = [$client, $upstream]; $write = null; $except = null;
            if (stream_select($read, $write, $except, 10) < 1) { break; }
            foreach ($read as $source) {
                $bytes = fread($source, 65536);
                if ($bytes === false || $bytes === '') { break 2; }
                write_bytes($source === $client ? $upstream : $client, $bytes);
            }
        }
    } catch (Throwable $error) {
        // A readiness probe and rejected credentials intentionally end here.
    } finally {
        if (is_resource($upstream)) { fclose($upstream); }
        fclose($client);
    }
}

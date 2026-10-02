<?php

// Minimal NDJSON engine stand-in: replaces "John Doe" with [PRIVATE_PERSON] in every string value.
// Usage: php fake_server.php <socket-path> <mode>
//   mode = normal | close   (close the connection after every response) | slow (never answer) | error

declare(strict_types=1);

[$_, $path, $mode] = $argv + [null, null, 'normal'];
@unlink($path);
$server = stream_socket_server('unix://' . $path, $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
fwrite(STDOUT, "ready\n");

$connections = 0;
while ($conn = @stream_socket_accept($server, -1)) {
    ++$connections;
    while (false !== ($line = fgets($conn))) {
        $req = json_decode($line, true);
        if ('slow' === $mode) {
            sleep(5);
            continue;
        }
        $walk = static function ($v) use (&$walk) {
            if (is_string($v)) {
                return str_replace('John Doe', '[PRIVATE_PERSON]', $v);
            }

            return is_array($v) ? array_map($walk, $v) : $v;
        };
        $resp = 'error' === $mode
            ? ['id' => $req['id'], 'error' => 'boom']
            : ['id' => $req['id'], 'payload' => $walk($req['payload']), 'entities' => 1, 'took_ms' => 0.1, 'conn' => $connections];
        fwrite($conn, json_encode($resp) . "\n");
        if ('close' === $mode) {
            break;
        }
    }
    fclose($conn);
}

<?php
// Isolated behavior check for the EOL state-corruption backport, never run on a live pool.
if (PHP_SAPI !== 'cli') {
    if (isset($_GET['oom'])) {
        register_shutdown_function(function () { echo 'UNSAFE_SHUTDOWN'; });
        $allocation = str_repeat('x', 32 * 1024 * 1024);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['version' => PHP_VERSION, 'pid' => getmypid()]);
    exit;
}
$command = [PHP_BINARY, '-n', '-d', 'memory_limit=16M', '-r',
    'register_shutdown_function(function () { echo "UNSAFE_SHUTDOWN"; }); $x = str_repeat("x", 32 * 1024 * 1024);'];
$p = proc_open('exec ' . implode(' ', array_map('escapeshellarg', $command)), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($p);
if ($status !== 1 || strpos($out, 'UNSAFE_SHUTDOWN') !== false || strpos($err, 'Allowed memory size') === false) {
    throw new RuntimeException('Unexpected CLI OOM behavior: ' . $status . $out . $err);
}
echo "CLI OOM exits immediately without shutdown callbacks OK\n";
$config = '/tmp/php-security-test.conf';
file_put_contents($config, "[global]\nerror_log=/tmp/php-security-test.log\ndaemonize=no\n[www]\nlisten=127.0.0.1:9000\nuser=www-data\ngroup=www-data\npm=static\npm.max_children=1\nphp_admin_value[memory_limit]=16M\ncatch_workers_output=yes\n");
// Loopback belongs to this isolated container, not an ephemeral container address.
$p = proc_open('exec php-fpm -n -y ' . escapeshellarg($config), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
$request = function ($query) {
    $socket = false;
    for ($i = 0; $i < 100; $i++) {
        $socket = @stream_socket_client('tcp://localhost:9000', $errno, $errstr, 0.1);
        if ($socket) { break; }
        usleep(100000);
    }
    if (!$socket) { throw new RuntimeException('FPM did not listen.'); }
    stream_set_timeout($socket, 10);
    $record = function ($type, $body) { return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body; };
    $length = function ($n) { return $n < 128 ? chr($n) : pack('N', $n | 0x80000000); };
    $params = '';
    foreach (['SCRIPT_FILENAME'=>__FILE__, 'REQUEST_METHOD'=>'GET', 'QUERY_STRING'=>$query, 'SERVER_PROTOCOL'=>'HTTP/1.1'] as $key=>$value) {
        $params .= $length(strlen($key)) . $length(strlen($value)) . $key . $value;
    }
    $data = $record(1, pack('nCxxxxx', 1, 0)) . $record(4, $params) . $record(4, '') . $record(5, '');
    while ($data !== '') { $n = fwrite($socket, $data); if (!$n) { throw new RuntimeException('FastCGI write failed.'); } $data = substr($data, $n); }
    $raw = stream_get_contents($socket); fclose($socket); $body = '';
    while (strlen($raw) >= 8) {
        $h = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', substr($raw, 0, 8));
        if ($h['type'] === 6) { $body .= substr($raw, 8, $h['length']); }
        $raw = substr($raw, 8 + $h['length'] + $h['padding']);
    }
    return $body;
};
try {
    $before = json_decode(explode("\r\n\r\n", $request(''), 2)[1] ?? '', true);
    $oom = $request('oom=1');
    $after = json_decode(explode("\r\n\r\n", $request(''), 2)[1] ?? '', true);
    if (!$before || !$after || $before['pid'] === $after['pid'] || $after['version'] !== PHP_VERSION || strpos($oom, 'UNSAFE_SHUTDOWN') !== false) {
        throw new RuntimeException('FPM worker recovery failed: ' . json_encode([$before, $oom, $after]));
    }
    echo "FPM OOM replaces the worker and serves the next request OK\n";
} finally { proc_terminate($p, 3); proc_close($p); }

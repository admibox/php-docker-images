<?php
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['sapi' => PHP_SAPI, 'version' => PHP_VERSION]);
    exit;
}

$process = proc_open('exec php-fpm -F -O', [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'w'],
    2 => STDERR,
], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start PHP-FPM.');
}
try {
    $socket = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://localhost:9000', $errorNumber, $errorMessage, 0.1);
        if ($socket !== false) {
            break;
        }
        usleep(100000);
    }
    if ($socket === false) {
        throw new RuntimeException('PHP-FPM did not start.');
    }
    stream_set_timeout($socket, 10);
    $record = function ($type, $body) {
        return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
    };
    $length = function ($value) {
        return $value < 128 ? chr($value) : pack('N', $value | 0x80000000);
    };
    $parameters = '';
    foreach ([
        'SCRIPT_FILENAME' => __FILE__,
        'SCRIPT_NAME' => '/verify-fpm.php',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/verify-fpm.php',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
    ] as $name => $value) {
        $parameters .= $length(strlen($name)) . $length(strlen($value)) . $name . $value;
    }
    $request = $record(1, pack('nCxxxxx', 1, 0)) . $record(4, $parameters) . $record(4, '') . $record(5, '');
    while ($request !== '') {
        $written = fwrite($socket, $request);
        if (!$written) {
            throw new RuntimeException('FastCGI write failed.');
        }
        $request = substr($request, $written);
    }
    $response = stream_get_contents($socket);
    fclose($socket);
    $body = '';
    $errors = '';
    while (strlen($response) >= 8) {
        $header = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', substr($response, 0, 8));
        $content = substr($response, 8, $header['length']);
        if ($header['type'] === 6) {
            $body .= $content;
        } elseif ($header['type'] === 7) {
            $errors .= $content;
        }
        $response = substr($response, 8 + $header['length'] + $header['padding']);
    }
    $result = json_decode(explode("\r\n\r\n", $body, 2)[1] ?? '', true);
    if ($errors !== '' || $result !== ['sapi' => 'fpm-fcgi', 'version' => PHP_VERSION]) {
        throw new RuntimeException('FastCGI verification failed: ' . $errors . $body);
    }
    echo 'FastCGI PHP ' . PHP_VERSION . " OK\n";
} finally {
    proc_terminate($process, 3);
    proc_close($process);
}

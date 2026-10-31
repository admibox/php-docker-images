<?php
// Runs inside the image at build time. Loads every compiled module in isolation, records its
// version and which modules are enabled by default, and prints the complete manifest as JSON.
// A module that does not load fails the build.
$static = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($static['php'] !== PHP_VERSION) {
    fwrite(STDERR, 'Built PHP ' . PHP_VERSION . ' does not match expected ' . $static['php'] . "\n");
    exit(1);
}
$directory = ini_get('extension_dir');
$modules = array_map(function ($path) {
    return basename($path, '.so');
}, glob($directory . '/*.so'));
sort($modules);
$zend = ['opcache', 'xdebug'];
$loadedNames = ['opcache' => 'Zend OPcache'];
// Serializers and sockets are optional dependencies of other modules; preload them when present.
$preload = array_values(array_intersect(['igbinary', 'msgpack', 'sockets'], $modules));

$extensions = [];
foreach ($modules as $module) {
    $options = ['-n'];
    foreach ($preload as $dependency) {
        if ($dependency !== $module) {
            $options[] = '-d ' . escapeshellarg("extension=$directory/$dependency.so");
        }
    }
    $directive = in_array($module, $zend, true) ? 'zend_extension' : 'extension';
    $options[] = '-d ' . escapeshellarg("$directive=$directory/$module.so");
    $name = $loadedNames[$module] ?? $module;
    $code = 'if (!extension_loaded(' . var_export($name, true) . ')) { exit(1); } echo json_encode(phpversion(' . var_export($name, true) . '));';
    $command = escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $options) . ' -r ' . escapeshellarg($code) . ' 2>&1';
    exec($command, $output, $status);
    $version = json_decode(implode("\n", $output), true);
    if ($status !== 0 || (!is_string($version) && $version !== false)) {
        fwrite(STDERR, "Module $module does not load:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
    $extensions[$module] = $version === false ? null : $version;
    $output = [];
}

$enabled = [];
foreach (glob(PHP_CONFIG_FILE_SCAN_DIR . '/*.ini') as $ini) {
    foreach (file($ini, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*(?:zend_)?extension\s*=\s*"?([^"\s]+)"?/', $line, $match)) {
            $enabled[] = preg_replace('#^.*/|\.so$#', '', $match[1]);
        }
    }
}
$enabled = array_values(array_unique($enabled));
sort($enabled);

echo json_encode([
    'image' => 'ecommpro/php',
    'php' => PHP_VERSION,
    'branch' => $static['branch'],
    'built_at' => gmdate('c'),
    'source' => $static['source'],
    'patches' => $static['patches'],
    'catalog' => $static['catalog'],
    'declared_enabled_by_default' => $static['enabled_by_default'],
    'tools' => $static['tools'],
    'extensions' => $extensions,
    'enabled_by_default' => $enabled,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

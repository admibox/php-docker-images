<?php
// Runs inside a built image: php /verification/verify-image.php /verification/build/<branch>/manifest.json
// Checks the image manifest against the generation manifest and exercises features the module
// list alone does not reveal (GD image formats, igbinary round trip).
$expected = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents('/usr/local/share/php-image-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$fail = function ($message) {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
};
if (PHP_VERSION !== $expected['php'] || $manifest['php'] !== $expected['php']) {
    $fail('PHP version mismatch: running ' . PHP_VERSION . ', manifest ' . $manifest['php'] . ', expected ' . $expected['php']);
}
$aliases = ['libsodium' => ['sodium'], 'xmlrw' => ['soap', 'xmlreader', 'xmlwriter']];
// (xmlreader/xmlwriter are built in, so they never have a .so and never count as enabled-by-default modules.)
$builtInAllowed = ['xmlreader', 'xmlwriter', 'ftp', 'sodium', 'opcache'];
$directory = ini_get('extension_dir');
foreach ($expected['extensions'] as $recipe) {
    foreach ($aliases[$recipe] ?? [$recipe] as $module) {
        if (array_key_exists($module, $manifest['extensions'])) {
            echo str_pad($module, 12) . ($manifest['extensions'][$module] ?? '(no version)') . "\n";
            continue;
        }
        $loadedName = $module === 'opcache' ? 'Zend OPcache' : $module;
        if (in_array($module, $builtInAllowed, true) && extension_loaded($loadedName)) {
            echo str_pad($module, 12) . "built-in\n";
            continue;
        }
        $fail("Catalog extension $module is neither a compiled module nor built in");
    }
}
// GD must have the image formats applications rely on (the 7.3 r1 image shipped without FreeType).
if (is_file("$directory/gd.so")) {
    $code = 'foreach (["FreeType Support", "JPEG Support", "PNG Support", "WebP Support"] as $feature) { if (empty(gd_info()[$feature])) { fwrite(STDERR, "GD missing $feature\n"); exit(1); } }';
    exec(escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg("extension=$directory/gd.so") . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $fail(implode("\n", $output));
    }
    echo "gd features  FreeType, JPEG, PNG, WebP\n";
}
if (is_file("$directory/igbinary.so")) {
    $code = '$v = ["name" => "test", "values" => [null, true, 123, 1.5]]; exit(igbinary_unserialize(igbinary_serialize($v)) === $v ? 0 : 1);';
    exec(escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg("extension=$directory/igbinary.so") . ' -r ' . escapeshellarg($code), $output, $status);
    if ($status !== 0) {
        $fail('igbinary round trip failed');
    }
    echo "igbinary     round trip OK\n";
}
if ($manifest['branch'] !== $expected['branch']) {
    $fail('Manifest branch metadata mismatch');
}
// Enabled-by-default modules: exactly the declared ones that exist as shared objects (built-ins need no ini).
$expectedEnabled = [];
foreach ($expected['enabled_by_default'] as $recipe) {
    foreach ($aliases[$recipe] ?? [$recipe] as $module) {
        if (is_file("$directory/$module.so")) {
            $expectedEnabled[] = $module;
        }
    }
}
sort($expectedEnabled);
if ($expectedEnabled !== $manifest['enabled_by_default']) {
    $fail('Enabled-by-default mismatch. Declared: ' . implode(' ', $expectedEnabled) . ' | Image: ' . implode(' ', $manifest['enabled_by_default']));
}
echo 'PHP ' . PHP_VERSION . ' verified, ' . count($manifest['extensions']) . " modules, enabled by default: " . implode(' ', $manifest['enabled_by_default']) . "\n";

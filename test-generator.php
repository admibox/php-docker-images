<?php
// Generator regression check: php test-generator.php (run in the generator container).
$directory = sys_get_temp_dir() . '/php-image-generator-' . bin2hex(random_bytes(6));
putenv('BUILD_OUTPUT_DIR=' . $directory);
$check = function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
};
$generate = function ($branch) use ($directory) {
    putenv('PHP_BRANCH=' . $branch);
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build.php'), $status);
    if ($status !== 0) {
        fwrite(STDERR, "FAIL: generation failed for PHP $branch\n");
        exit(1);
    }
    return [file_get_contents("$directory/$branch/Dockerfile"), json_decode(file_get_contents("$directory/$branch/manifest.json"), true)];
};
$catalog = array_keys(include __DIR__ . '/extensions.php');
foreach (array_keys(include __DIR__ . '/versions.php') as $branch) {
    [$dockerfile, $manifest] = $generate($branch);
    $check(strpos($dockerfile, 'AS php-base') !== false, "$branch: missing source build stage");
    $check(preg_match('/^FROM debian:\w+-slim AS image$/m', $dockerfile) === 1, "$branch: final stage must start from the recipe Debian image");
    $check(strpos($dockerfile, 'COPY --from=extensions /usr/local /usr/local') !== false, "$branch: PHP not copied into the final stage");
    $check(strpos($dockerfile, 'php-runtime-packages.txt') !== false, "$branch: runtime package list missing");
    $check(preg_match('/getcomposer.org\/download\/2\.[0-9.]+\/composer.phar/', $dockerfile) === 1, "$branch: Composer not resolved");
    $check(strpos($dockerfile, 'COPY files/idle /usr/local/bin/idle') !== false, "$branch: idle missing");
    $check(strpos($dockerfile, 'WORKDIR /app') !== false, "$branch: WORKDIR missing");
    $check(strpos($dockerfile, 'FROM ecommpro/') === false, "$branch: depends on a published tag");
    $check(strpos($dockerfile, "\nRUN --mount=") !== false, "$branch: broken instruction boundary");
    $check(strpos($dockerfile, 'php-image-manifest.json') !== false, "$branch: manifest step missing");
    $check(strpos($manifest['php'], $branch . '.') === 0, "$branch: unexpected PHP version {$manifest['php']}");
    $expected = version_compare($branch, '8.0', '<') ? array_merge($catalog, array_keys(include __DIR__ . '/extensions.legacy.php')) : $catalog;
    $missing = array_diff($expected, $manifest['extensions']);
    $check($missing === [], "$branch: catalog entries dropped: " . implode(', ', $missing));
    if (version_compare($branch, '8.1', '>=')) {
        $check(strpos($dockerfile, 'pecl install') === false, "$branch: PECL used on a PIE branch");
        $check(strpos($dockerfile, 'from=pie') !== false, "$branch: PIE not mounted");
    } else {
        $check(strpos($dockerfile, '$PIE_INSTALL') === false, "$branch: PIE used on a PHP < 8.1 branch");
        $check(!preg_match('/pecl (?:install|download)\s+(?:-f\s+)?[a-z_]+(?![\w.\/-])/', $dockerfile), "$branch: unresolved PECL package");
    }
    echo "PHP {$manifest['php']}: " . count($manifest['extensions']) . " extensions OK\n";
}
echo "Generator OK\n";

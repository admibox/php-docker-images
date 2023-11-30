<?php
$buildDir = __DIR__ . '/build';
$extensionsDir = __DIR__ . '/extensions';
$versions = include(__DIR__ . '/versions.php');

$tools = array_map(function($file) {
    return pathinfo($file, PATHINFO_BASENAME);
},  array_filter(glob(__DIR__ . '/cli-tools/*'), function($file) {
    return ! is_link($file) && strpos($file, 'update-cli-tools') === false;
}));

$allExtensions = include(__DIR__ . '/extensions.php');

foreach($versions as $versionArr) {
    $extensions = [];

    $version = $versionArr['version'];
    $base = $versionArr['base'] ?? $version;

    foreach($allExtensions as $file) {
        $max = substr(preg_replace('#(-cli|-fpm)$#', '', $version), 0);

        $candidates = array_reduce(
            array_merge(glob($extensionsDir . '/' . $file), glob($extensionsDir . '/' . "$file:*")),
            function($result, $file) {
                $basename = pathinfo($file, PATHINFO_BASENAME);
                $v = explode(':', $basename);
                $result[$v[1] ?? '100'] = $basename;
                return $result;
            },
            []
        );

        uksort($candidates, 'version_compare');

        $keys = array_reverse(array_keys($candidates));

        do {
            $key = array_pop($keys);
        } while($key && version_compare($key, $max, 'lt'));

        $candidate = $candidates[$key];

        $content = trim(file_get_contents($extensionsDir . '/' . $candidate), " \t\n\r\0\x0B\\");

        if ($content) {
            $extensions[$file] = $content;
        }
    }

    ob_start();
    if (preg_match('/-cli$/', $version)) {
        include(__DIR__ . '/' . 'block.cli');
    }
    $cli = ob_get_clean();

    ob_start();
    include __DIR__ . '/' . (getenv('MODE') === 'production' ? 'layout.base.production' : 'layout.base.development');
    $contents = ob_get_clean();

    $toDir = $buildDir . '/' . $version;
    if (!file_exists($toDir)) {
        mkdir($toDir, 0755, true);
    }

    file_put_contents($toDir . '/Dockerfile', $contents);
    echo "$toDir created.\n";
}
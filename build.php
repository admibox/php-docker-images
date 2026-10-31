<?php
// Generates build/<branch>/Dockerfile and build/<branch>/manifest.json for one PHP branch.
//
//   PHP_BRANCH=8.4 php build.php
//   php build.php --list        prints the configured branches
//
// The Dockerfile has a php-base stage (vendored upstream recipe), an extensions stage with one
// cached RUN per recipe, and fpm / cli targets that share the compiled extensions.

const PIE_VERSION = '1.5.5';

$root = __DIR__;
$branches = include $root . '/versions.php';

if (in_array('--list', $argv, true)) {
    echo implode("\n", array_keys($branches)), "\n";
    exit;
}

$branch = getenv('PHP_BRANCH');
if (!$branch || !isset($branches[$branch])) {
    fwrite(STDERR, "PHP_BRANCH must be one of: " . implode(', ', array_keys($branches)) . "\n");
    exit(2);
}
$settings = $branches[$branch];
$sourceDirectory = "upstream/$branch";
if (!is_file("$root/$sourceDirectory/Dockerfile")) {
    throw new RuntimeException("$sourceDirectory is missing: run ./vendor-php.py $branch");
}
$hasPatches = isset($settings['patches']);
// PIE needs PHP 8.1 to run. Older branches resolve PECL releases at generation time and install the archives.
$usesPie = version_compare($branch, '8.1', '>=');

$buildDirectory = (getenv('BUILD_OUTPUT_DIR') ?: $root . '/build') . '/' . $branch;
$extensionsDirectory = $root . '/extensions';

$catalog = include $root . '/extensions.php';
if (version_compare($branch, '8.0', '<')) {
    $catalog = array_merge($catalog, include $root . '/extensions.legacy.php');
}
// Catalog entries whose module names differ from the entry name.
$modulesOf = ['libsodium' => ['sodium'], 'xmlrw' => ['soap']];

// --- PECL resolution for PHP < 8.1: latest stable release whose declared PHP range includes this release.
function peclGet(string $path): ?string
{
    $url = 'https://pecl.php.net/rest/' . $path;
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
    if ($body !== false) {
        return $body;
    }
    if (preg_match('#^HTTP/\S+ 404\b#', $http_response_header[0] ?? '')) {
        return null;
    }
    throw new RuntimeException("PECL request failed: $url");
}

function peclLatestCompatible(string $package, string $phpVersion): string
{
    static $cache = [];
    if (isset($cache[$package])) {
        return $cache[$package];
    }
    $releases = peclGet("r/$package/allreleases.xml");
    if ($releases === null) {
        throw new RuntimeException("$package is not on PECL");
    }
    foreach ((new SimpleXMLElement($releases))->r as $release) {
        $version = (string) $release->v;
        // Stable means a plain numeric version: PECL labels some release candidates "stable".
        if ((string) $release->s !== 'stable' || !preg_match('/^[0-9]+(?:\.[0-9]+)+$/', $version)) {
            continue;
        }
        $package_xml = peclGet("r/$package/package.$version.xml");
        if ($package_xml === null) {
            continue;
        }
        $php = (new SimpleXMLElement($package_xml))->dependencies->required->php ?? null;
        $min = isset($php->min) ? (string) $php->min : '0.0.0';
        $max = isset($php->max) ? (string) $php->max : '99.99.99';
        if (version_compare($phpVersion, $min, '>=') && version_compare($phpVersion, $max, '<=')) {
            fprintf(STDERR, "%-10s %s\n", $package, $version);
            return $cache[$package] = $version;
        }
    }
    throw new RuntimeException("No stable PECL release of $package supports PHP $phpVersion");
}

// --- Recipe selection: extensions/<name> is the base; extensions/<name>:X.Y applies to PHP >= X.Y.
$recipes = [];
$recipeFiles = [];
foreach ($catalog as $name => $enabledByDefault) {
    $candidates = [];
    foreach (array_merge(glob("$extensionsDirectory/$name") ?: [], glob("$extensionsDirectory/$name:*") ?: []) as $file) {
        $basename = basename($file);
        $parts = explode(':', $basename, 2);
        $candidates[$parts[1] ?? 'base'] = $basename;
    }
    $selected = $candidates['base'] ?? null;
    $selectedVersion = '0';
    foreach ($candidates as $minimum => $basename) {
        if ($minimum !== 'base' && version_compare($minimum, $branch, '<=') && version_compare($minimum, $selectedVersion, '>=')) {
            $selected = $basename;
            $selectedVersion = $minimum;
        }
    }
    if ($selected === null) {
        throw new RuntimeException("No recipe for extension $name");
    }
    $recipeFiles[] = $selected;
    $content = trim(file_get_contents("$extensionsDirectory/$selected"), " \t\n\r\0\x0B\\");
    if ($content === '') {
        continue; // empty recipe: extension not available on this branch
    }
    $content = str_replace('docker-php-ext-install ', 'docker-php-ext-install -j "${BUILD_JOBS}" ', $content);

    if ($usesPie) {
        if (preg_match('/\bpecl\s+(install|download)\b/', $content)) {
            throw new RuntimeException("$selected uses PECL; PHP >= 8.1 recipes must use PIE (\$PIE_INSTALL vendor/package).");
        }
    } else {
        // Resolve "pecl install name" / "pecl download name" to the latest compatible stable release.
        $content = preg_replace_callback('/\b(pecl (?:install|download)\s+(?:-f\s+)?)([a-z_]+)(?![\w.\/-])/', function ($match) use ($settings) {
            return $match[1] . $match[2] . '-' . peclLatestCompatible($match[2], $settings['php']);
        }, $content);
        if (version_compare($branch, '8.0', '<')) {
            // The PECL client of PHP 7 fails on current channel metadata: download the pinned archives
            // over HTTPS first (keeps "printf answers | pecl install" pipelines intact), then install them.
            preg_match_all('/pecl install\s+(?:-f\s+)?([a-z_]+-[0-9][a-zA-Z0-9.]*)(?!\S)/', $content, $packages);
            foreach (array_unique($packages[1]) as $package) {
                $content = preg_replace('/(pecl install\s+(?:-f\s+)?)' . preg_quote($package, '/') . '(?!\S)/', '${1}/tmp/' . $package . '.tgz', $content);
                $content = "curl -fL --retry 3 https://pecl.php.net/get/$package.tgz -o /tmp/$package.tgz \\\n&& " . $content;
            }
            $content = preg_replace('/pecl download\s+([a-z_]+-[0-9][a-zA-Z0-9.]*)/',
                'curl -fL --retry 3 https://pecl.php.net/get/$1.tgz -o $1.tgz', $content);
        }
    }
    // Enforce the declared default state after the recipe, whatever the installer did:
    // enabled entries get an ini (when the module exists as a shared object), disabled ones lose it.
    $modules = $modulesOf[$name] ?? [$name];
    $enforce = [];
    foreach ($modules as $module) {
        $enforce[] = $enabledByDefault
            ? "{ test -f \"\$(php-config --extension-dir)/$module.so\" && docker-php-ext-enable $module || true; }"
            : "rm -f \"\$PHP_INI_DIR/conf.d/docker-php-ext-$module.ini\"";
    }
    // Strip in the same layer that produced the module: a later strip would only add a copy on top.
    $enforce[] = 'find "$(php-config --extension-dir)" -name \'*.so\' -exec strip --strip-all {} +';
    $recipes[$name] = $content . " \\\n&& " . implode(" \\\n&& ", $enforce);
}

// --- Upstream recipe: the official Dockerfile becomes the php-base stage.
$upstream = file_get_contents("$root/$sourceDirectory/Dockerfile");
if ($upstream === false) {
    throw new RuntimeException("Missing $sourceDirectory/Dockerfile");
}
if (!preg_match('/^ENV PHP_VERSION (' . preg_quote($branch, '/') . '\.[0-9]+)$/m', $upstream, $versionMatch)) {
    throw new RuntimeException("$sourceDirectory/Dockerfile does not build PHP $branch");
}
$phpVersion = $versionMatch[1];
$upstream = preg_replace('/^FROM (.+)$/m', 'FROM $1 AS php-base', $upstream, 1);
$upstream = preg_replace('/^(FROM .+ AS php-base)$/m',
    "$1\nARG APT_REFRESH=cached\nRUN echo \"Package refresh: \$APT_REFRESH\" && apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*",
    $upstream, 1);
$upstream = preg_replace_callback('/^COPY (.+) (\/[^ ]+)$/m', function ($match) use ($sourceDirectory) {
    $sources = array_map(function ($source) use ($sourceDirectory) {
        return "$sourceDirectory/$source";
    }, explode(' ', $match[1]));
    return 'COPY ' . implode(' ', $sources) . ' ' . $match[2];
}, $upstream);

// The final stage starts from the same Debian image as the upstream recipe and re-declares its ENV.
if (!preg_match('/^FROM (\S+) AS php-base$/m', $upstream, $baseMatch)) {
    throw new RuntimeException('Cannot find the upstream base image');
}
$baseImage = $baseMatch[1];
preg_match_all('/^ENV (?:[^\n]*\\\\\n)*[^\n]*/m', $upstream, $envMatches);
$upstreamEnv = implode("\n", $envMatches[0]);

$patches = $hasPatches
    ? json_decode(file_get_contents("$root/$sourceDirectory/patches.json"), true, 512, JSON_THROW_ON_ERROR)
    : null;
if ($phpVersion !== $settings['php']) {
    throw new RuntimeException("$sourceDirectory builds PHP $phpVersion but versions.php says {$settings['php']}; run ./vendor-php.py $branch");
}
$source = trim(file_get_contents("$root/$sourceDirectory/SOURCE"));

// Static part of the manifest installed in the image; files/image-manifest.php completes it at build time.
$imageInfo = [
    'php' => $phpVersion,
    'branch' => $branch,
    'source' => $source,
    'patches' => $patches ? [
        'vendor' => $patches['vendor'] ?? null,
        'package_version' => $patches['package_version'] ?? null,
        'included_patches' => $patches['included_patches'] ?? [],
    ] : null,
    'catalog' => array_keys($recipes),
    'enabled_by_default' => array_values(array_filter(array_keys($recipes), fn ($n) => $catalog[$n])),
];

// Composer: current stable release, verified by checksum in the Dockerfile.
$composerVersions = @file_get_contents('https://getcomposer.org/versions', false, stream_context_create(['http' => ['timeout' => 20]]));
if ($composerVersions === false) {
    throw new RuntimeException('Cannot read https://getcomposer.org/versions');
}
$composer = json_decode($composerVersions, true, 512, JSON_THROW_ON_ERROR)['stable'][0];
if (!preg_match('/^2\.[0-9]+\.[0-9]+$/', $composer['version'])) {
    throw new RuntimeException('Unexpected Composer release metadata');
}
$checksum = @file_get_contents("https://getcomposer.org/download/{$composer['version']}/composer.phar.sha256sum", false, stream_context_create(['http' => ['timeout' => 20]]));
if ($checksum === false || !preg_match('/^([0-9a-f]{64})\s+composer\.phar\s*$/', $checksum, $sha)) {
    throw new RuntimeException('Cannot read the Composer checksum for ' . $composer['version']);
}
$composer['sha256'] = $sha[1];
fprintf(STDERR, "%-10s %s\n", 'composer', $composer['version']);
$imageInfo['tools'] = ['composer' => $composer['version']];
$imageJson = json_encode($imageInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

ob_start();
include $root . '/layout.dockerfile';
$dockerfile = ob_get_clean();

if (!is_dir($buildDirectory)) {
    mkdir($buildDirectory, 0755, true);
}
file_put_contents("$buildDirectory/Dockerfile", $dockerfile);
file_put_contents("$buildDirectory/manifest.json", json_encode([
    'php' => $phpVersion,
    'branch' => $branch,
    'pie' => $usesPie ? PIE_VERSION : null,
    'source' => $source,
    'patches' => $patches,
    'recipes' => $recipeFiles,
    'extensions' => array_keys($recipes),
    'enabled_by_default' => array_values(array_filter(array_keys($recipes), fn ($n) => $catalog[$n])),
    'tools' => ['composer' => $composer['version']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "$buildDirectory/Dockerfile created for PHP $phpVersion\n";

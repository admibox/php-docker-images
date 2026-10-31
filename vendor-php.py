#!/usr/bin/env python3
"""Materialize a PHP branch described in versions.php into upstream/<branch>/.

    ./vendor-php.py 8.4            vendor the branch as described
    ./vendor-php.py 8.4 --update   first point the branch at the latest docker-library recipe
                                   (latest PHP patch release); refused for branches with patches
    ./vendor-php.py --all          vendor every branch

Layers (see versions.php): the official docker-library recipe at a commit is downloaded; the PHP
version and tarball checksum are set from php.net when the recipe is older than the configured
release; when the recipe comes from an older Debian variant it is adapted to the configured Debian;
when the branch has patches, the Sury packaging archive is downloaded, verified, filtered, every
selected patch is checked against the checksum-verified official tarball, and the Dockerfile is
extended to apply the series and repack the patched source before extensions are built.
"""
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent
HELPERS = ['docker-php-source', 'docker-php-ext-configure', 'docker-php-ext-enable', 'docker-php-ext-install', 'docker-php-entrypoint']
# Never taken from a packaging series: Debian-specific packaging, README edits, DNS telemetry.
ALWAYS_SKIPPED = re.compile(r'telemetry|README', re.I)
# Runtime library detection that works on merged-/usr Debian (bookworm and later).
LDD_BLOCK = (
    "\tfind /usr/local -type f -executable -exec ldd '{}' ';' \\\n"
    "\t\t| awk '/=>/ { so = $(NF-1); if (index(so, \"/usr/local/\") == 1) { next }; gsub(\"^/(usr/)?\", \"\", so); printf \"*%s\\n\", so }'  \\\n"
    "\t\t| sort -u \\\n"
    "\t\t| xargs -r dpkg-query --search \\\n"
    "\t\t| awk 'sub(\":$\", \"\", $1) { print $1 }'  \\\n"
    "\t\t| sort -u \\\n"
    "\t\t| xargs -r apt-mark manual \\"
)


def download(url):
    with urllib.request.urlopen(url, timeout=120) as response:
        return response.read()


def read_versions():
    """Evaluate versions.php with the generator container (the host has no PHP)."""
    command = ['php', '-r', 'echo json_encode(include "versions.php");']
    if shutil.which('php') is None:
        engine = ['sudo', '-n', 'nerdctl'] if os.environ.get('USE_SUDO', '1') == '1' else [os.environ.get('CONTAINER_ENGINE', 'nerdctl')]
        command = [*engine, 'run', '--rm', '--network', 'none', '-v', f'{ROOT}:/work:ro', '-w', '/work',
                   os.environ.get('PHP_GENERATOR', 'php:8.2-cli'), *command]
    return json.loads(subprocess.run(command, cwd=ROOT, capture_output=True, text=True, check=True).stdout)


def update_versions_file(branch, php, commit):
    text = (ROOT / 'versions.php').read_text()
    block = re.search(r"^    '" + re.escape(branch) + r"' => \[\n(.*?)^    \],\n", text, re.M | re.S)
    if not block:
        sys.exit(f'Cannot find the {branch} entry in versions.php')
    body = block.group(1)
    body = re.sub(r"'php' => '[^']+'", f"'php' => '{php}'", body)
    body = re.sub(r"'commit' => '[0-9a-f]+'", f"'commit' => '{commit}'", body)
    (ROOT / 'versions.php').write_text(text[:block.start(1)] + body + text[block.end(1):])


def vendor(branch, settings, update):
    recipe = settings['recipe']
    debian = recipe['debian']
    variant = recipe.get('variant', debian)
    patches = settings.get('patches')
    commit = recipe['commit']
    php = settings['php']

    if update:
        if patches:
            sys.exit(f'PHP {branch} has a patch series; it does not follow docker-library. Edit versions.php instead.')
        commit = json.loads(download('https://api.github.com/repos/docker-library/php/commits/master'))['sha']
        latest = download(f'https://raw.githubusercontent.com/docker-library/php/{commit}/{branch}/{variant}/fpm/Dockerfile').decode()
        php = re.search(r'^ENV PHP_VERSION (\S+)$', latest, re.M)[1]
        if php == settings['php'] and commit == recipe['commit']:
            print(f'PHP {branch}: already at {php} ({commit[:12]})')
        else:
            update_versions_file(branch, php, commit)
            print(f'PHP {branch}: {settings["php"]} ({recipe["commit"][:12]}) -> {php} ({commit[:12]})')

    base = f'https://raw.githubusercontent.com/docker-library/php/{commit}'
    target = ROOT / 'upstream' / branch
    target.mkdir(parents=True, exist_ok=True)
    for stale in list(target.glob('*')):
        if stale.is_dir():
            for f in stale.rglob('*'):
                if f.is_file():
                    f.unlink()
            for d in sorted(stale.rglob('*'), reverse=True):
                d.rmdir()
            stale.rmdir()
        else:
            stale.unlink()

    dockerfile = download(f'{base}/{branch}/{variant}/fpm/Dockerfile').decode()
    for name in HELPERS:
        try:
            content = download(f'{base}/{branch}/{variant}/fpm/{name}')
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise
            content = download(f'{base}/{name}')
        (target / name).write_bytes(content)
        (target / name).chmod(0o755)
    (target / 'LICENSE').write_bytes(download(f'{base}/LICENSE'))

    recipe_php = re.search(r'^ENV PHP_VERSION (\S+)$', dockerfile, re.M)[1]
    if recipe_php != php:
        if not patches:
            sys.exit(f'PHP {branch}: recipe builds {recipe_php} but versions.php says {php}; run with --update or fix versions.php')
        release = json.loads(download(f'https://www.php.net/releases/index.php?json&version={php}'))
        checksum = next(item['sha256'] for item in release['source'] if item['filename'].endswith('.tar.xz'))
        dockerfile = dockerfile.replace(recipe_php, php)
        dockerfile = re.sub(r'ENV PHP_SHA256="[^"]+"', f'ENV PHP_SHA256="{checksum}"', dockerfile)
    sha256 = re.search(r'ENV PHP_SHA256="([^"]+)"', dockerfile)[1]

    source_lines = [f'https://github.com/docker-library/php/tree/{commit}/{branch}/{variant}/fpm']
    if variant != debian:
        assert f'FROM debian:{variant}-slim' in dockerfile
        dockerfile = dockerfile.replace(f'FROM debian:{variant}-slim', f'FROM debian:{debian}-slim')
        source_lines.append(f'Recipe adapted from {variant} to {debian}.')

    provenance = None
    if patches:
        archive_name = f"php{branch}_{patches['package'].replace('+', '%2B')}.debian.tar.xz"
        url = f"https://packages.sury.org/php/pool/main/p/php{branch}/{archive_name}"
        data = download(url)
        if hashlib.sha256(data).hexdigest() != patches['sha256']:
            sys.exit(f'PHP {branch}: Sury archive checksum mismatch for {url}')
        included, excluded = [], []
        (target / 'patches').mkdir()
        with tarfile.open(fileobj=io.BytesIO(data)) as package:
            series = package.extractfile('debian/patches/series').read().decode().splitlines()
            changelog = package.extractfile('debian/changelog').read().decode()
            for name in series:
                if not name or name.startswith('#'):
                    continue
                selected = (name[:4] >= patches['from'] or name in patches.get('also', [])) \
                    and name not in patches.get('skip', []) and not ALWAYS_SKIPPED.search(name)
                (included if selected else excluded).append(name)
                if selected:
                    (target / 'patches' / name).write_bytes(package.extractfile('debian/patches/' + name).read())
        (target / 'patches' / 'series').write_text('\n'.join(included) + '\n')
        cves = sorted({cve for name in included for cve in re.findall(r'CVE-\d{4}-\d+', name)})
        # The October 2026 backport names no CVE in its file name; record it from the patch header.
        for name in included:
            cves += re.findall(r'CVE-\d{4}-\d+', (target / 'patches' / name).read_text(errors='replace')[:2000])
        provenance = {
            'php': php, 'vendor': patches['vendor'], 'url': url,
            'package_version': re.search(r'\(([^)]+)\)', changelog)[1],
            'archive_sha256': patches['sha256'],
            'selection': {k: patches[k] for k in ('from', 'also', 'skip') if k in patches},
            'included_patches': included, 'excluded_patches': excluded,
            'cves_mentioned': sorted(set(cves)),
        }
        (target / 'patches.json').write_text(json.dumps(provenance, indent=2) + '\n')
        source_lines.append(url)
        source_lines.append('Selected patches recorded in patches.json.')

        # Build tools for patching and reconfiguring; compiler flags current GCC needs for old sources.
        assert '\t\tdpkg-dev \\\n' in dockerfile and 'ENV PHP_CPPFLAGS=' in dockerfile
        dockerfile = dockerfile.replace('\t\tdpkg-dev \\\n', '\t\tdpkg-dev \\\n\t\tpatch \\\n\t\tbison \\\n', 1)
        dockerfile = dockerfile.replace('ENV PHP_CPPFLAGS=',
            'ENV PHP_CFLAGS="$PHP_CFLAGS -Wno-deprecated-declarations -Wno-error=incompatible-pointer-types"\nENV PHP_CPPFLAGS=', 1)
        assert 'COPY docker-php-source /usr/local/bin/' in dockerfile
        dockerfile = dockerfile.replace('COPY docker-php-source /usr/local/bin/',
            'COPY docker-php-source /usr/local/bin/\nCOPY patches /usr/local/share/php-source-patches/\nCOPY patches.json /usr/local/share/php-source-patches.json', 1)
        marker = '\tcd /usr/src/php; \\\n'
        assert marker in dockerfile
        dockerfile = dockerfile.replace(marker, marker
            + '\twhile IFS= read -r patch_file; do patch --batch --forward -p1 < "/usr/local/share/php-source-patches/$patch_file"; done < /usr/local/share/php-source-patches/series; \\\n'
            + '\t./buildconf --force; \\\n'
            + f'\ttar -cJf /usr/src/php.tar.xz --transform="s,^,php-{php}/," .; \\\n', 1)
        start = dockerfile.find('\tfind /usr/local -type f -executable -exec ldd')
        end = dockerfile.find('\n\t; \\', start)
        assert start != -1 and end != -1
        dockerfile = dockerfile[:start] + LDD_BLOCK + dockerfile[end:]

        # Every selected patch must apply to the official tarball, in order.
        source = download(f'https://www.php.net/distributions/php-{php}.tar.xz')
        if hashlib.sha256(source).hexdigest() != sha256:
            sys.exit(f'PHP {branch}: php-{php}.tar.xz checksum mismatch')
        with tempfile.TemporaryDirectory(prefix=f'php-{php}-patch-check-') as tmp:
            with tarfile.open(fileobj=io.BytesIO(source)) as archive:
                archive.extractall(tmp, filter='data')
            for name in included:
                result = subprocess.run(['patch', '--batch', '--forward', '-p1', '-i', str(target / 'patches' / name)],
                                        cwd=Path(tmp) / f'php-{php}', capture_output=True, text=True)
                if result.returncode != 0:
                    sys.exit(f'PHP {branch}: patch {name} does not apply:\n{result.stdout}{result.stderr}')

    (target / 'Dockerfile').write_text(dockerfile)
    (target / 'SOURCE').write_text('\n'.join(source_lines) + '\n')
    summary = f'PHP {php} on {debian}, recipe {commit[:12]}'
    if provenance:
        summary += f", {len(provenance['included_patches'])} patches from {patches['vendor']} {patches['package']}"
    print(f'upstream/{branch}: {summary}')


args = sys.argv[1:]
update = '--update' in args
branches = [a for a in args if not a.startswith('--')]
versions = read_versions()
if '--all' in args:
    branches = list(versions)
if not branches:
    sys.exit(__doc__)
for branch in branches:
    if branch not in versions:
        sys.exit(f'PHP {branch} is not configured in versions.php')
    vendor(branch, versions[branch], update)

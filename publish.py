#!/usr/bin/env python3
"""Publish locally built and verified images to the registry.

    ./publish.py            publish every branch that has build/<branch>/manifest.json
    ./publish.py 8.4 8.3    publish only these branches

For each branch the local image ecommpro/php:<php-version> is pushed and its remote config digest is
compared with the local image id; then the aliases ecommpro/php:<branch> and the transitional
<tag>-fpm / <tag>-cli names are pushed and verified the same way. Each published image is recorded
in releases/<php-version>.json (image manifest plus digest and tags). A version tag that already
exists remotely with a different image is refused unless --force is given.
"""
import datetime
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REGISTRY = os.environ.get('REGISTRY', 'ecommpro/php')
DOCKER_CONFIG = Path(os.environ.get('DOCKER_CONFIG', Path.home() / '.docker'))
RELEASES = ROOT / 'releases'
ACCEPT = ', '.join([
    'application/vnd.oci.image.index.v1+json', 'application/vnd.docker.distribution.manifest.list.v2+json',
    'application/vnd.oci.image.manifest.v1+json', 'application/vnd.docker.distribution.manifest.v2+json'])

args = [a for a in sys.argv[1:] if not a.startswith('--')]
force = '--force' in sys.argv
branches = args or sorted(p.parent.name for p in (ROOT / 'build').glob('*/manifest.json'))
if not branches:
    sys.exit('Nothing to publish: run ./build.sh first.')

credentials = json.loads((DOCKER_CONFIG / 'config.json').read_text())
auth = credentials.get('auths', {}).get('https://index.docker.io/v1/', {}).get('auth')


def engine(*command, log=None):
    base = ['sudo', '-n', 'env', f'DOCKER_CONFIG={DOCKER_CONFIG}'] if os.environ.get('USE_SUDO', '1') == '1' else ['env', f'DOCKER_CONFIG={DOCKER_CONFIG}']
    return subprocess.run([*base, os.environ.get('CONTAINER_ENGINE', 'nerdctl'), *command],
                          stdout=log or subprocess.PIPE, stderr=log or subprocess.PIPE, check=True, text=log is None)


def remote(tag):
    """Return {'digest', 'config'} of the linux/amd64 image behind a remote tag, or None."""
    request = urllib.request.Request(f'https://auth.docker.io/token?service=registry.docker.io&scope=repository:{REGISTRY}:pull')
    if auth:
        request.add_header('Authorization', 'Basic ' + auth)
    with urllib.request.urlopen(request, timeout=60) as response:
        token = json.load(response)['token']

    def fetch(reference):
        request = urllib.request.Request(f'https://registry-1.docker.io/v2/{REGISTRY}/manifests/{reference}',
                                         headers={'Authorization': 'Bearer ' + token, 'Accept': ACCEPT})
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.load(response), response.headers['Docker-Content-Digest']

    try:
        body, digest = fetch(tag)
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return None
        raise
    if 'manifests' in body:
        selected = [m for m in body['manifests'] if m.get('platform', {}).get('os') == 'linux' and m.get('platform', {}).get('architecture') == 'amd64']
        if len(selected) != 1:
            raise RuntimeError(f'Expected one linux/amd64 manifest for {tag}')
        body, _ = fetch(selected[0]['digest'])
    return {'digest': digest, 'config': body['config']['digest']}


def push(ref, image_id, immutable):
    tag = ref.split(':', 1)[1]
    before = remote(tag)
    if before and before['config'] == image_id:
        print(f'  {ref} already published')
    else:
        if before and immutable and not force:
            raise RuntimeError(f'{ref} exists remotely with a different image; pass --force to overwrite it')
        for attempt in range(3):
            try:
                engine('push', '--quiet', ref)
                break
            except subprocess.CalledProcessError as error:
                if attempt == 2:
                    raise RuntimeError(f'push failed for {ref}: {error.stderr}')
                time.sleep(3)
    after = remote(tag)
    if not after or after['config'] != image_id:
        raise RuntimeError(f'Remote image mismatch after push: {ref}')
    print(f'  {ref} {after["digest"]}')
    return after


RELEASES.mkdir(exist_ok=True)
for branch in branches:
    generation = json.loads((ROOT / 'build' / branch / 'manifest.json').read_text())
    php = generation['php']
    ref = f'{REGISTRY}:{php}'
    aliases = [f'{REGISTRY}:{branch}'] + [f'{REGISTRY}:{tag}-{target}' for tag in (php, branch) for target in ('fpm', 'cli')]
    print(ref)
    inspected = json.loads(engine('image', 'inspect', ref).stdout)[0]
    if inspected['Os'] != 'linux' or inspected['Architecture'] != 'amd64':
        raise RuntimeError(f'{ref} is not linux/amd64')
    image_id = inspected['Id']
    manifest = json.loads(engine('run', '--rm', '--network', 'none', ref, 'cat', '/usr/local/share/php-image-manifest.json').stdout)
    if manifest['php'] != php:
        raise RuntimeError(f'{ref} contains PHP {manifest["php"]}')
    for alias in aliases:
        try:
            alias_id = json.loads(engine('image', 'inspect', alias).stdout)[0]['Id']
        except subprocess.CalledProcessError:
            alias_id = None
        if alias_id != image_id:
            engine('tag', ref, alias)
    published = push(ref, image_id, immutable=True)
    for alias in aliases:
        push(alias, image_id, immutable=False)
    record = dict(manifest)
    record.update({
        'tags': [ref, *aliases],
        'digest': published['digest'],
        'image_id': image_id,
        'published_at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
    })
    (RELEASES / f'{php}.json').write_text(json.dumps(record, indent=2) + '\n')
print(f'Done. Records in {RELEASES.relative_to(ROOT)}/')

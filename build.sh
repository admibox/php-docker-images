#!/usr/bin/env bash
# Build and verify one PHP branch locally. Never publishes.
#
#   ./build.sh 8.4
#
# Tags: ecommpro/php:<php-version> and ecommpro/php:<branch>, plus the transitional aliases
# <tag>-fpm and <tag>-cli (same image).
# Environment: REGISTRY (ecommpro/php), BUILD_JOBS (8), APT_REFRESH (cache key for apt upgrade),
# CONTAINER_ENGINE (nerdctl), USE_SUDO (1), PHP_GENERATOR (php:8.2-cli).
set -euo pipefail
cd "$(dirname "$0")"

branch="${1:?Usage: ./build.sh <php-branch>}"
[[ "$branch" =~ ^[0-9]+\.[0-9]+$ ]] || { echo 'Invalid PHP branch' >&2; exit 2; }

registry="${REGISTRY:-ecommpro/php}"
engine=("${CONTAINER_ENGINE:-nerdctl}")
if [[ "${USE_SUDO:-1}" == 1 ]]; then engine=(sudo -n "${engine[@]}"); fi
generator="${PHP_GENERATOR:-php:8.2-cli}"

mkdir -p "build/$branch"
exec 9>"build/$branch/.lock"
flock -n 9 || { echo "Another build of PHP $branch is running." >&2; exit 1; }

# The generator resolves PECL and Composer releases, so it needs network access.
"${engine[@]}" run --rm --user "$(id -u):$(id -g)" \
    -v "$PWD:/work" -w /work -e PHP_BRANCH="$branch" "$generator" php build.php

php_version=$(python3 -c "import json; print(json.load(open('build/$branch/manifest.json'))['php'])")
has_patches=$(python3 -c "import json; print(1 if json.load(open('build/$branch/manifest.json'))['patches'] else 0)")
revision=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)
if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then revision="$revision-dirty"; fi

image="$registry:$php_version"
tags=("$image" "$registry:$branch" "$image-fpm" "$image-cli" "$registry:$branch-fpm" "$registry:$branch-cli")
tag_args=(); for tag in "${tags[@]}"; do tag_args+=(-t "$tag"); done
log="build/$branch/build-$(date -u +%Y%m%dT%H%M%SZ).log"
start=$SECONDS
"${engine[@]}" build --progress plain --target image \
    --build-arg "APT_REFRESH=${APT_REFRESH:-cached}" \
    --build-arg "BUILD_JOBS=${BUILD_JOBS:-8}" \
    --build-arg "SOURCE_REVISION=$revision" \
    --build-arg "BUILD_DATE=$(date -u +%FT%TZ)" \
    "${tag_args[@]}" -f "build/$branch/Dockerfile" . 2>&1 | tee "$log"
printf 'build: %ss\n' "$((SECONDS - start))" | tee -a "$log"

run=("${engine[@]}" run --rm --network none)
version_output=$("${run[@]}" "$image" php -v 2>&1)
printf '%s\n' "$version_output"
if [[ "$version_output" == *Warning* || "$version_output" == *Fatal* ]]; then
    echo 'PHP startup reported an error.' >&2
    exit 1
fi
"${run[@]}" "$image" php-fpm -t
"${run[@]}" "$image" composer --version
# idle must stop promptly as the container's main process, both through the image's stop signal
# (SIGQUIT, what `stop` and compose use) and through SIGTERM.
for signal in QUIT TERM; do
    name="php-idle-check-$$"
    "${engine[@]}" run -d --name "$name" --network none "$image" idle >/dev/null
    sleep 1
    "${engine[@]}" kill -s "$signal" "$name" >/dev/null
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        state=$("${engine[@]}" inspect -f '{{.State.Status}} {{.State.ExitCode}}' "$name")
        [[ "$state" == exited* ]] && break
        sleep 0.5
    done
    "${engine[@]}" rm -f "$name" >/dev/null
    [[ "$state" == "exited 0" ]] || { echo "idle did not exit cleanly on SIG$signal (state: $state)" >&2; exit 1; }
    echo "idle stops on SIG$signal"
done
"${run[@]}" -v "$PWD:/verification:ro" "$image" php /verification/verify-image.php "/verification/build/$branch/manifest.json"
"${run[@]}" -v "$PWD:/verification:ro" "$image" php /verification/verify-fpm.php
if [[ "$has_patches" == 1 ]]; then
    # Security backport branches: the OOM fix must exit the worker and FPM must replace it.
    "${run[@]}" -v "$PWD:/verification:ro" "$image" php /verification/verify-legacy-security.php
fi
"${run[@]}" "$image" cat /usr/local/share/php-image-manifest.json > "build/$branch/image.manifest.json"
echo "Built and verified $image (${tags[*]:1})"

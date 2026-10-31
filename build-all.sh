#!/usr/bin/env bash
# Build and verify every configured branch. Never publishes.
# A failed branch is recorded and the remaining branches continue; exit code is nonzero if any failed.
# Results, logs and manifests go to build/runs/<run-id>/.
set -euo pipefail
cd "$(dirname "$0")"
run_id="${BUILD_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
[[ "$run_id" =~ ^[a-zA-Z0-9_.-]+$ ]] || exit 2
output="$PWD/build/runs/$run_id"
mkdir -p "$output"
exec 8>build/.all.lock
flock -n 8 || { echo 'Another complete build is running.' >&2; exit 1; }
printf '%s\n' "$$" > "$output/pid"

engine=("${CONTAINER_ENGINE:-nerdctl}")
if [[ "${USE_SUDO:-1}" == 1 ]]; then engine=(sudo -n "${engine[@]}"); fi
branches=$("${engine[@]}" run --rm --user "$(id -u):$(id -g)" \
    -v "$PWD:/work" -w /work "${PHP_GENERATOR:-php:8.2-cli}" php build.php --list)

# Refresh Debian packages once per run (the key busts the apt upgrade layer cache).
export APT_REFRESH="$run_id"
failed=0
for branch in $branches; do
    printf '%s %s started\n' "$(date -u +%FT%TZ)" "$branch" | tee -a "$output/status.log"
    if ./build.sh "$branch" > "$output/$branch.log" 2>&1; then
        printf '%s %s passed\n' "$(date -u +%FT%TZ)" "$branch" | tee -a "$output/status.log"
        cp "build/$branch/manifest.json" "$output/$branch-manifest.json"
        cp "build/$branch/image.manifest.json" "$output/$branch-image.manifest.json"
    else
        printf '%s %s FAILED (see %s)\n' "$(date -u +%FT%TZ)" "$branch" "$output/$branch.log" | tee -a "$output/status.log"
        failed=1
    fi
done
printf '%s\n' "$failed" > "$output/exit-code"
exit "$failed"

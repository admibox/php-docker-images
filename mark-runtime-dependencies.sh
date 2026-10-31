#!/bin/sh
set -eu

dependencies=$(mktemp)
trap 'rm -f "$dependencies"' EXIT
for module in "$(php-config --extension-dir)"/*.so; do
    links=$(ldd "$module")
    if printf '%s\n' "$links" | grep -q 'not found'; then
        printf 'Unresolved dependencies in %s:\n%s\n' "$module" "$links" >&2
        exit 1
    fi
    printf '%s\n' "$links" | awk '$2 == "=>" && $3 ~ /^\// { print $3 }' >> "$dependencies"
done
sort -u "$dependencies" | while IFS= read -r library; do
    # Libraries installed from source are already retained under /usr/local.
    case "$library" in /usr/local/*) continue ;; esac
    resolved=$(readlink -f "$library")
    owners=$(dpkg-query --search "$resolved" 2>/dev/null || dpkg-query --search "${resolved#/usr}" 2>/dev/null)
    printf '%s\n' "$owners" | sed 's/: \/.*$//' | while IFS= read -r package; do
        apt-mark manual "$package"
    done
done

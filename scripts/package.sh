#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
output="${1:-$root/dist}"
mkdir -p "$output"
output="$(cd "$output" && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/guardlms"
for entry in autoload.php bootstrap.php common.php admin.php callback.php index.php install.php uninstall.php plugin.php settings.php src lang assets cli LICENSE README.md README.nl.md; do
    cp -R "$root/$entry" "$stage/guardlms/"
done
(cd "$stage" && zip -qr "$output/guardlms-chamilo-0.1.0.zip" guardlms)
echo "$output/guardlms-chamilo-0.1.0.zip"

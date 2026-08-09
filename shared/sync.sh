#!/usr/bin/env bash
# Sync the canonical shared client into each module's vendored lib dir.
# The module copies are build artifacts — edit shared/src/ only.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(dirname "$here")"

targets=(
  "$root/whmcs/lib"
  "$root/blesta/apis/lib"
  "$root/wisecp/lib"
)

for dir in "${targets[@]}"; do
  mkdir -p "$dir"
  cp "$here/src/JabaliApiClient.php" "$dir/"
  cp "$here/src/JabaliApiException.php" "$dir/"
  echo "synced -> $dir"
done

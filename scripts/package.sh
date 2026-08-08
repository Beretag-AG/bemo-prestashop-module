#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(sed -n "s/^[[:space:]]*const VERSION = '\([^']*\)';/\1/p" "$repository_root/bemoliveshopping.php")"

if [[ -z "$version" ]]; then
  echo "Unable to read the module version." >&2
  exit 1
fi

temporary_root="$(mktemp -d)"
trap 'rm -rf "$temporary_root"' EXIT

mkdir -p "$repository_root/dist"
git -C "$repository_root" archive --format=tar --prefix=bemoliveshopping/ HEAD \
  | tar -xf - -C "$temporary_root"

artifact="$repository_root/dist/bemoliveshopping.zip"
rm -f "$artifact"

(
  cd "$temporary_root"
  zip -q -r "$artifact" bemoliveshopping
)

echo "$artifact"

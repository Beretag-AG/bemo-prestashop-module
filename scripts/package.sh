#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(git -C "$repository_root" show HEAD:bemoliveshopping.php \
  | sed -n "s/^[[:space:]]*const VERSION = '\([^']*\)';/\1/p")"

if [[ -z "$version" ]]; then
  echo "Unable to read the module version from HEAD." >&2
  exit 1
fi

temporary_root="$(mktemp -d)"
trap 'rm -rf "$temporary_root"' EXIT

source_tree="$(git -C "$repository_root" rev-parse HEAD^{tree})"
source_date_epoch="$(git -C "$repository_root" show -s --format=%ct HEAD)"

mkdir -p "$repository_root/dist"
git -C "$repository_root" archive --format=tar --mtime="@$source_date_epoch" --prefix=bemoliveshopping/ "$source_tree" \
  | tar -xf - -C "$temporary_root"

artifact="$repository_root/dist/bemoliveshopping.zip"
rm -f "$artifact"

(
  cd "$temporary_root"
  TZ=UTC zip -X -q -r "$artifact" bemoliveshopping
)

echo "$artifact"

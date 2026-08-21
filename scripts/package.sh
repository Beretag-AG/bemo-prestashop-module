#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(git -C "$repository_root" show HEAD:bemoliveshopping.php \
  | sed -n "s/^[[:space:]]*const VERSION = '\([^']*\)';/\1/p")"
distribution="${1:-production}"

if [[ "$distribution" != "production" && "$distribution" != "staging" ]]; then
  echo "Usage: $0 [production|staging]" >&2
  exit 1
fi

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

if [[ "$distribution" == "staging" ]]; then
  sed -i.bak "s/define('BEMO_DISTRIBUTION_ENVIRONMENT', 'production')/define('BEMO_DISTRIBUTION_ENVIRONMENT', 'staging')/" \
    "$temporary_root/bemoliveshopping/config/distribution.php"
  rm "$temporary_root/bemoliveshopping/config/distribution.php.bak"
  if ! grep -F "define('BEMO_DISTRIBUTION_ENVIRONMENT', 'staging');" \
    "$temporary_root/bemoliveshopping/config/distribution.php" >/dev/null; then
    echo "Unable to lock the staging archive to BEMO staging." >&2
    exit 1
  fi
fi

suffix=""
if [[ "$distribution" == "staging" ]]; then
  suffix="-staging"
fi
artifact="$repository_root/dist/bemoliveshopping-$version$suffix.zip"
rm -f "$artifact"

(
  cd "$temporary_root"
  TZ=UTC zip -X -q -r "$artifact" bemoliveshopping
)

echo "$artifact"

#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
artifact="$($repository_root/scripts/package.sh)"
entries="$(unzip -Z1 "$artifact")"

if grep -Ev '^bemoliveshopping/' <<<"$entries" >/dev/null; then
  echo "Archive contains files outside bemoliveshopping/." >&2
  exit 1
fi

for required in \
  bemoliveshopping/bemoliveshopping.php \
  bemoliveshopping/.htaccess \
  bemoliveshopping/config.xml \
  bemoliveshopping/LICENSE.md \
  bemoliveshopping/config/autoload.php; do
  if ! grep -Fx "$required" <<<"$entries" >/dev/null; then
    echo "Archive is missing $required." >&2
    exit 1
  fi
done

for excluded in tests/ docs/ .github/; do
  if grep -F "$excluded" <<<"$entries" >/dev/null; then
    echo "Archive unexpectedly includes $excluded." >&2
    exit 1
  fi
done

echo "Package structure is valid: $artifact"

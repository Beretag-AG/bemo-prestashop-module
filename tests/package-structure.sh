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
  bemoliveshopping/config/autoload.php \
  bemoliveshopping/controllers/front/buy.php \
  bemoliveshopping/controllers/front/productlinks.php \
  bemoliveshopping/src/Checkout/index.php \
  bemoliveshopping/src/Pairing/index.php \
  bemoliveshopping/src/Webhook/index.php \
  bemoliveshopping/upgrade/index.php \
  bemoliveshopping/upgrade/upgrade-0.2.0.php \
  bemoliveshopping/upgrade/upgrade-0.3.0.php \
  bemoliveshopping/upgrade/upgrade-0.3.1.php; do
  if ! grep -Fx "$required" <<<"$entries" >/dev/null; then
    echo "Archive is missing $required." >&2
    exit 1
  fi
done

if ! unzip -p "$artifact" bemoliveshopping/config.xml \
  | grep -F '<author><![CDATA[BEMO]]></author>' >/dev/null; then
  echo "Packaged module metadata must identify the author as BEMO." >&2
  exit 1
fi

if ! unzip -p "$artifact" bemoliveshopping/bemoliveshopping.php \
  | grep -F "\$this->author = 'BEMO';" >/dev/null; then
  echo "Packaged module runtime metadata must identify the author as BEMO." >&2
  exit 1
fi

for excluded in tests/ docs/ .github/; do
  if grep -F "$excluded" <<<"$entries" >/dev/null; then
    echo "Archive unexpectedly includes $excluded." >&2
    exit 1
  fi
done

while IFS= read -r php_entry; do
  if ! unzip -p "$artifact" "$php_entry" | grep -F "defined('_PS_VERSION_')" >/dev/null; then
    echo "Packaged PHP file is missing the PrestaShop context guard: $php_entry" >&2
    exit 1
  fi
done < <(grep -E '\.php$' <<<"$entries")

echo "Package structure is valid: $artifact"

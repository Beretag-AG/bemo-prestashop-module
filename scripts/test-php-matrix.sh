#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! -x "$repository_root/vendor/bin/phpunit" ]]; then
  echo "Run composer install before the test matrix." >&2
  exit 1
fi

php_binaries=()
while IFS= read -r -d '' php_binary; do
  php_binaries+=("$php_binary")
done < <("$repository_root/scripts/php-matrix.sh")

for php_binary in "${php_binaries[@]}"; do
  echo "Testing with $($php_binary -r 'echo PHP_VERSION;')"
  "$php_binary" "$repository_root/vendor/bin/phpunit" --configuration "$repository_root/phpunit.xml.dist"
done

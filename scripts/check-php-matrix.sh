#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_binaries=()
while IFS= read -r -d '' php_binary; do
  php_binaries+=("$php_binary")
done < <("$repository_root/scripts/php-matrix.sh")

php_files=()
while IFS= read -r -d '' php_file; do
  php_files+=("$php_file")
done < <(git -C "$repository_root" ls-files -z '*.php' ':(exclude)tests/**')

for php_binary in "${php_binaries[@]}"; do
  echo "Linting with $($php_binary -r 'echo PHP_VERSION;')"
  for php_file in "${php_files[@]}"; do
    "$php_binary" -l "$repository_root/$php_file" >/dev/null
  done
done

echo "PHP syntax is valid across the supported runtime matrix."

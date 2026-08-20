#!/usr/bin/env bash
set -euo pipefail

resolve_brew_php() {
  local formula="$1"

  if ! command -v brew >/dev/null 2>&1; then
    return 1
  fi

  local prefix
  prefix="$(brew --prefix "$formula" 2>/dev/null)" || return 1

  [[ -x "$prefix/bin/php" ]] || return 1
  printf '%s\n' "$prefix/bin/php"
}

php_70="${PHP_70_BIN:-}"
php_81="${PHP_81_BIN:-}"

if [[ -z "$php_70" ]]; then
  php_70="$(resolve_brew_php php@7.0)" || {
    echo "PHP 7.0 not found. Set PHP_70_BIN or install shivammathur/php/php@7.0." >&2
    exit 1
  }
fi

if [[ -z "$php_81" ]]; then
  php_81="$(resolve_brew_php php@8.1)" || {
    echo "PHP 8.1 not found. Set PHP_81_BIN or install php@8.1." >&2
    exit 1
  }
fi

printf '%s\0' "$php_70" "$php_81"

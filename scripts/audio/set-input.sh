#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if [[ $# -lt 1 ]]; then
  echo "Použití: $0 <vstup>" >&2
  echo "Dostupné vstupy zjistíte příkazem: ${PHP_BIN} artisan audio:input --help" >&2
  exit 1
fi

INPUT_IDENTIFIER="${1}"
shift

cd "${PROJECT_ROOT}"
"${PHP_BIN}" artisan audio:input "${INPUT_IDENTIFIER}" "$@"

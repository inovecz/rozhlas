#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if [[ $# -lt 1 ]]; then
  echo "Použití: $0 <výstup>" >&2
  echo "Dostupné výstupy zjistíte příkazem: ${PHP_BIN} artisan audio:output --help" >&2
  exit 1
fi

OUTPUT_IDENTIFIER="${1}"
shift

cd "${PROJECT_ROOT}"
"${PHP_BIN}" artisan audio:output "${OUTPUT_IDENTIFIER}" "$@"

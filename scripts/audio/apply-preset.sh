#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if [[ $# -lt 1 ]]; then
  echo "Použití: $0 <preset>" >&2
  echo "Příklady: ${PHP_BIN} artisan audio:preset microphone | system_audio | central_file" >&2
  exit 1
fi

PRESET_IDENTIFIER="${1}"
shift

cd "${PROJECT_ROOT}"
"${PHP_BIN}" artisan audio:preset "${PRESET_IDENTIFIER}" "$@"

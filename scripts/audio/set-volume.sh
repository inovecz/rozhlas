#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

if [[ $# -lt 1 ]]; then
  cat >&2 <<'USAGE'
Použití: set-volume.sh <scope> [hodnota] [--mute|--unmute]

Kde:
  <scope>  Identifikátor hlasitosti (např. master, input, output)
  hodnota  Nepovinná hodnota 0-100. Pokud není zadána, příkaz pouze vypíše stav.
USAGE
  exit 1
fi

SCOPE="${1}"
shift

cd "${PROJECT_ROOT}"
"${PHP_BIN}" artisan audio:volume "${SCOPE}" "$@"

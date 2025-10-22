#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required to run the installer." >&2
  exit 1
fi

php artisan app:install "$@"

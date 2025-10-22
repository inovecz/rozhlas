#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required to run the installer." >&2
  exit 1
fi

php artisan app:install "$@"

read -r -p "Is ALSA mixer (alsamixer) available on this system? [Y/n] " ALSA_AVAILABLE
ALSA_AVAILABLE=${ALSA_AVAILABLE:-Y}
case "${ALSA_AVAILABLE,,}" in
  y|yes)
    echo "ALSAmixer availability confirmed."
    ;;
  n|no)
    echo "Warning: ALSA mixer is not available. Some audio routing features may require manual configuration." >&2
    ;;
  *)
    echo "ALSAmixer availability confirmed (default)."
    ;;
esac

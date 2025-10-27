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
CONFIGURE_SCRIPT="$ROOT_DIR/scripts/configure_tlv320.sh"

run_tlv320_configuration() {
  local card_index="$1"
  if ! command -v alsamixer >/dev/null 2>&1; then
    echo "Warning: alsamixer command not found; skipping TLV320 configuration." >&2
    echo "Install alsa-utils and rerun the installer if you need TLV320 presets." >&2
    return
  fi

  if [[ -x "$CONFIGURE_SCRIPT" ]]; then
    echo "Running TLV320 configuration script for card ${card_index}..."
    "$CONFIGURE_SCRIPT" "$card_index"
  else
    echo "Warning: $CONFIGURE_SCRIPT is not executable; skipping TLV320 configuration." >&2
    echo "Run 'chmod +x scripts/configure_tlv320.sh' and re-run the installer if needed." >&2
  fi
}

case "${ALSA_AVAILABLE,,}" in
  y|yes)
    echo "ALSAmixer availability confirmed."
    read -r -p "Enter TLV320 ALSA card index [2]: " TLV320_CARD_INDEX
    TLV320_CARD_INDEX=${TLV320_CARD_INDEX:-2}
    run_tlv320_configuration "$TLV320_CARD_INDEX"
    ;;
  n|no)
    echo "Warning: ALSA mixer is not available. Some audio routing features may require manual configuration." >&2
    echo "You can run '$ROOT_DIR/scripts/configure_tlv320.sh' manually once ALSA is installed." >&2
    ;;
  *)
    echo "ALSAmixer availability confirmed (default)."
    run_tlv320_configuration 2
    ;;
esac

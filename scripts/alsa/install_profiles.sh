#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STORAGE_DIR="${ROOT_DIR}/storage/app/alsa-profiles"
ALSCTL_BIN="${AUDIO_ALSACTL_BINARY:-${ALSCTL_BIN:-${ALSCTL_BINARY:-$(command -v alsactl || true)}}}"
CARD="${AUDIO_MIXER_CARD:-0}"

if [[ -z "${ALSCTL_BIN}" ]]; then
  echo "Chyba: nebyl nalezen příkaz alsactl. Nastavte proměnnou AUDIO_ALSACTL_BINARY." >&2
  exit 1
fi

if [[ ! -x "${ALSCTL_BIN}" ]]; then
  echo "Chyba: '${ALSCTL_BIN}' není spustitelný soubor." >&2
  exit 1
fi

mkdir -p "${STORAGE_DIR}"

save_profile() {
  local name="$1"
  local path="${STORAGE_DIR}/${name}.profile"

  echo "Ukládám ALSA profil '${name}' do '${path}' (karta ${CARD})"
  if ! "${ALSCTL_BIN}" --file "${path}" store "${CARD}"; then
    echo "Uložení profilu '${name}' selhalo." >&2
    exit 1
  fi
}

save_profile "mic"
save_profile "file"
save_profile "fm"

echo "Hotovo. Profily jsou v '${STORAGE_DIR}'."

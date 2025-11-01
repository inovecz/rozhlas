#!/usr/bin/env bash
set -euo pipefail

DEVICE="${JSVV_SERIAL_DEVICE:-/dev/ttyAMA3}"
STTY_OPTS="${JSVV_SERIAL_STTY:-9600 cs8 -cstopb -parenb -ixon -crtscts}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
CONTROL_SCRIPT="${JSVV_CONTROL_SCRIPT:-$(dirname "${BASH_SOURCE[0]}")/../python-client/modbus_control.py}"
MODBUS_PORT="${JSVV_MODBUS_PORT:-/dev/ttyUSB0}"
MODBUS_UNIT_ID="${JSVV_MODBUS_UNIT_ID:-55}"
DEFAULT_PRIORITY="${JSVV_DEFAULT_PRIORITY:-P2}"
DEFAULT_NESTS="${JSVV_DEFAULT_NESTS:-101,102}"
CONTROL_ARGS_STRING="${JSVV_CONTROL_ARGS:-}"

if ! command -v jq >/dev/null 2>&1; then
  echo "Skript vyžaduje nástroj 'jq' pro dekódování JSON." >&2
  exit 1
fi

if ! command -v "${PYTHON_BIN}" >/dev/null 2>&1; then
  echo "Nenalezen interpretr '${PYTHON_BIN}'. Nastavte proměnnou PYTHON_BIN." >&2
  exit 1
fi

if [[ ! -f "${CONTROL_SCRIPT}" ]]; then
  echo "Spouštěcí skript '${CONTROL_SCRIPT}' neexistuje." >&2
  exit 1
fi

if [[ ! -e "${DEVICE}" ]]; then
  echo "Sériové zařízení ${DEVICE} neexistuje." >&2
  exit 1
fi

echo "Inicializace sériového rozhraní ${DEVICE} (${STTY_OPTS})"
stty -F "${DEVICE}" ${STTY_OPTS}

IFS=' ' read -r -a CONTROL_EXTRA_ARGS <<<"${CONTROL_ARGS_STRING}"

slot_to_symbol() {
  case "$1" in
    1) echo "A" ;;
    2) echo "B" ;;
    3) echo "C" ;;
    4) echo "D" ;;
    5) echo "E" ;;
    6) echo "F" ;;
    7) echo "G" ;;
    8) echo "P" ;;
    9) echo "Q" ;;
    10) echo "R" ;;
    11) echo "S" ;;
    12) echo "T" ;;
    13) echo "U" ;;
    14) echo "V" ;;
    15) echo "X" ;;
    16) echo "Y" ;;
    *) return 1 ;;
  esac
}

cleanup() {
  exec 3<&-
  echo "Poslech byl ukončen."
}

trap cleanup EXIT INT TERM

exec 3<"${DEVICE}"
echo "Naslouchám na ${DEVICE} pro JSVV rámce..."

while IFS= read -r raw_line <&3; do
  line="${raw_line//$'\r'/}"
  line="${line//$'\0'/}"
  [[ -z "${line}" ]] && continue

  timestamp="$(date '+%Y-%m-%d %H:%M:%S')"
  echo "[${timestamp}] Přijatá zpráva: ${line}"

  if ! jq empty <<<"${line}" >/dev/null 2>&1; then
    echo "  -> Neplatný JSON, zprávu přeskočena." >&2
    continue
  fi

  command=$(jq -r '.command // empty' <<<"${line}")
  priority=$(jq -r '.priority // empty' <<<"${line}")
  [[ -z "${priority}" || "${priority}" == "null" ]] && priority="${DEFAULT_PRIORITY}"

  nests=$(jq -r '(.params.nests // .params.zones // empty) | (if type=="array" then map(tostring)|join(",") else tostring end)' <<<"${line}")
  [[ -z "${nests}" || "${nests}" == "null" ]] && nests="${DEFAULT_NESTS}"

  sequence=""

  case "${command}" in
    SIREN_SIGNAL)
      signal=$(jq -r '.params.signalType // 1' <<<"${line}")
      case "${signal}" in
        1) sequence="1,8,9" ;;
        2) sequence="2,8,9" ;;
        3) sequence="4,8,9" ;;
        *) sequence="2,8,9" ;;
      esac
      ;;
    VERBAL_INFO|VERBAL)
      slot=$(jq -r '.params.slot // (.params.tokens[0] // empty)' <<<"${line}")
      if symbol=$(slot_to_symbol "${slot}" 2>/dev/null); then
        sequence="${symbol}"
      else
        echo "  -> Neznámý slot verbální informace (${slot}), zpráva ignorována." >&2
        continue
      fi
      ;;
    PLAY_SEQUENCE|SEQUENCE)
      raw_sequence=$(jq -r '.params.sequence // (.params.symbols // empty)' <<<"${line}")
      if [[ -z "${raw_sequence}" || "${raw_sequence}" == "null" ]]; then
        echo "  -> Chybí parametr 'sequence', zpráva ignorována." >&2
        continue
      fi
      raw_sequence=${raw_sequence^^}
      if [[ "${raw_sequence}" == *","* ]]; then
        sequence="${raw_sequence// /}"
      else
        sequence=$(echo "${raw_sequence}" | sed 's/./&,/g' | sed 's/,$//')
      fi
      ;;
    STOP)
      echo "  -> Přijat příkaz STOP – manuální zásah zatím není implementován v tomto skriptu."
      continue
      ;;
    *)
      echo "  -> Neznámý příkaz '${command}', zpráva ignorována." >&2
      continue
      ;;
  esac

  if [[ -z "${sequence}" ]]; then
    echo "  -> Sekvenci se nepodařilo odvodit, zpráva ignorována." >&2
    continue
  fi

  echo "  -> Spouštím modbus_control (sekvence=${sequence}, priorita=${priority}, hnízda=${nests})"

  if ! MODBUS_JSVV_PRIORITY="${priority}" MODBUS_JSVV_NESTS="${nests}" \
      "${PYTHON_BIN}" "${CONTROL_SCRIPT}" \
      --port "${MODBUS_PORT}" \
      --unit-id "${MODBUS_UNIT_ID}" \
      "${CONTROL_EXTRA_ARGS[@]}" \
      play-sequence "${sequence}"; then
    echo "  -> Odeslání selhalo." >&2
  fi
done
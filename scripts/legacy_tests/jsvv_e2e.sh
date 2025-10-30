#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

SOCAT_BIN="$(command -v socat || true)"
PHP_BIN="${PHP_BIN:-php}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
SIM_DURATION="${JSVV_E2E_DURATION:-180}"

if [[ -z "$SOCAT_BIN" ]]; then
  echo "[ERROR] socat is required but not found in PATH." >&2
  exit 1
fi

ENV_FILE="${ROOT_DIR}/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "[ERROR] .env file not found at $ENV_FILE" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
LISTENER_LINK="${TMP_DIR}/jsvv-listener"
SIMULATOR_LINK="${TMP_DIR}/jsvv-simulator"
SOCAT_LOG="${TMP_DIR}/socat.log"
ENV_BACKUP="${TMP_DIR}/env.backup"
LISTENER_LOG="${ROOT_DIR}/storage/logs/daemons/jsvv_listener_test.log"

cleanup() {
  local status=$?
  if [[ -n "${LISTENER_PID:-}" ]] && kill -0 "$LISTENER_PID" >/dev/null 2>&1; then
    kill "$LISTENER_PID" 2>/dev/null || true
    wait "$LISTENER_PID" 2>/dev/null || true
  fi
  if [[ -n "${SOCAT_PID:-}" ]] && kill -0 "$SOCAT_PID" >/dev/null 2>&1; then
    kill "$SOCAT_PID" 2>/dev/null || true
    wait "$SOCAT_PID" 2>/dev/null || true
  fi
  if [[ -f "$ENV_BACKUP" ]]; then
    mv "$ENV_BACKUP" "$ENV_FILE"
    (cd "$ROOT_DIR" && $PHP_BIN artisan config:clear >/dev/null 2>&1 || true)
  fi
  rm -rf "$TMP_DIR"
  exit $status
}
trap cleanup EXIT INT TERM

cp "$ENV_FILE" "$ENV_BACKUP"

echo "[1/6] Creating paired PTY devices via socat…"
"$SOCAT_BIN" -d -d "PTY,raw,echo=0,link=${LISTENER_LINK}" "PTY,raw,echo=0,link=${SIMULATOR_LINK}" >"$SOCAT_LOG" 2>&1 &
SOCAT_PID=$!

for _ in {1..10}; do
  if [[ -e "$LISTENER_LINK" && -e "$SIMULATOR_LINK" ]]; then
    break
  fi
  sleep 0.5
done

if [[ ! -e "$LISTENER_LINK" || ! -e "$SIMULATOR_LINK" ]]; then
  echo "[ERROR] Failed to create PTY pair. See $SOCAT_LOG" >&2
  exit 1
fi

echo "  • Listener port:  $LISTENER_LINK"
echo "  • Simulator port: $SIMULATOR_LINK"

echo "[2/6] Updating .env with temporary JSVV port…"
export JSVV_E2E_ENV_FILE="$ENV_FILE"
export JSVV_E2E_LISTENER_LINK="$LISTENER_LINK"
"$PYTHON_BIN" - <<'PY'
import os
import pathlib
import re

env_path = pathlib.Path(os.environ["JSVV_E2E_ENV_FILE"])
text = env_path.read_text()
listener_link = os.environ["JSVV_E2E_LISTENER_LINK"]

settings = {
    "JSVV_PORT": listener_link,
    "JSVV_BAUDRATE": "9600",
    "JSVV_PARITY": "N",
    "JSVV_STOPBITS": "1",
    "JSVV_BYTESIZE": "8",
    "JSVV_TIMEOUT": "1.0",
    "JSVV_SEQUENCE_MODE": "remote_trigger",
}

for key, value in settings.items():
    pattern = re.compile(rf"^{key}=.*$", re.MULTILINE)
    replacement = f"{key}={value}"
    if pattern.search(text):
        text = pattern.sub(replacement, text)
    else:
        if not text.endswith("\n"):
            text += "\n"
        text += replacement + "\n"

env_path.write_text(text)
PY

echo "[3/6] Reloading Laravel configuration…"
(cd "$ROOT_DIR" && $PHP_BIN artisan config:clear >/dev/null)

echo "[4/6] Starting temporary JSVV listener…"
mkdir -p "$(dirname "$LISTENER_LOG")"
PYTHONPATH="$ROOT_DIR/python-client/src:${PYTHONPATH:-}" "$PYTHON_BIN" "$ROOT_DIR/python-client/daemons/jsvv_listener.py" \
  --port "$LISTENER_LINK" \
  --baudrate 9600 \
  --parity N \
  --stopbits 1 \
  --bytesize 8 \
   \
  --retry-backoff 250 \
  >>"$LISTENER_LOG" 2>&1 &
LISTENER_PID=$!
sleep 2

if ! kill -0 "$LISTENER_PID" >/dev/null 2>&1; then
  echo "[ERROR] JSVV listener did not start. Check $LISTENER_LOG." >&2
  exit 1
fi

echo "[5/6] Running serial simulation (approximately ${SIM_DURATION}s)…"
export JSVV_SIM_SCENARIO='[
  ["REMOTE", [], "P2", 30, "Remote voice"],
  ["LOCAL",  [], "P2", 20, "Local microphone"],
  ["EXT1",   [], "P3", 20, "External primary"],
  ["STOP",   [], "P1", 5,  "Priority stop"],
  ["EXT2",   [], "P3", 15, "External secondary"],
  ["STOP",   [], "P1", 5,  "Final stop"]
]'

export JSVV_SIM_DURATION="$SIM_DURATION"
PYTHONPATH="$ROOT_DIR/python-client/src:${PYTHONPATH:-}" "$ROOT_DIR/scripts/tests/jsvv_serial_sim.sh" --port "$SIMULATOR_LINK" --duration "$SIM_DURATION"

echo "[6/6] Simulation complete. Cleaning up…"
exit 0

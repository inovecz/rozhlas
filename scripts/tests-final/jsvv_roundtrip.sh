#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

source "${SCRIPT_DIR}/_pty.sh"

PYTHON_BIN="${PYTHON_BIN:-python3}"
PHP_BIN="${PHP_BIN:-php}"
ARTISAN_BIN="${ARTISAN_BIN:-$PHP_BIN}"
ARTISAN_PATH="${ARTISAN_PATH:-artisan}"
SOCAT_BIN="${SOCAT_BIN:-$(command -v socat || true)}"

pty::require_command "$PYTHON_BIN" "python3"
pty::require_command "$ARTISAN_BIN" "php"
pty::require_command "$SOCAT_BIN" "socat"

LOG_DIR="${ROOT_DIR}/storage/logs/tests"
mkdir -p "$LOG_DIR"
LISTENER_LOG="${LOG_DIR}/jsvv_roundtrip.log"

FRAME_BUILDER_SCRIPT=$(cat <<'PY'
from jsvv.client import JSVVClient
print(JSVVClient.build_frame("SIREN", [1]).strip())
PY
)

build_frame() {
    PYTHONPATH="${ROOT_DIR}/python-client/src:${PYTHONPATH:-}" \
        "$PYTHON_BIN" - <<PY
$FRAME_BUILDER_SCRIPT
PY
}

run_roundtrip() {
    : >"$LISTENER_LOG"

    local frame
    frame="$(build_frame)"

    PYTHONPATH="${ROOT_DIR}/python-client/src:${PYTHONPATH:-}" \
        "$PYTHON_BIN" "${ROOT_DIR}/python-client/daemons/jsvv_listener.py" \
        --port "$APP_TTY" \
        --baudrate 9600 \
        --parity N \
        --stopbits 1 \
        --bytesize 8 \
        --timeout-ms 500 \
        --network 42 \
        --vyc 7 \
        --kpps 0x1A2B \
        --operator 3 \
        --max-retries 1 \
        --retry-backoff 0.2 \
        --artisan-bin "$ARTISAN_BIN" \
        --artisan-path "$ARTISAN_PATH" \
        --log-file "$LISTENER_LOG" \
        --log-level INFO \
        --once \
        &
    local listener_pid=$!

    sleep 1

    APP_ENV="${APP_ENV:-testing}" \
    DB_CONNECTION="${DB_CONNECTION:-sqlite}" \
    DB_DATABASE="${DB_DATABASE:-${ROOT_DIR}/database/database.sqlite}" \
    "$ARTISAN_BIN" "$ARTISAN_PATH" port:send "$FEED_TTY" "$frame" --newline >/dev/null

    wait "$listener_pid"

    if ! grep -q "\[DONE\] SIREN_SIGNAL" "$LISTENER_LOG"; then
        echo "[ERROR] Listener log missing success marker. Inspect $LISTENER_LOG" >&2
        exit 1
    fi

    echo "[OK] JSVV listener processed SIREN frame (log: $LISTENER_LOG)."
}

pty::with_pair run_roundtrip

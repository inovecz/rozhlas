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
LISTENER_LOG="${LOG_DIR}/control_tab_listener.log"

calc_crc() {
    local payload="$1"
    "$PYTHON_BIN" - <<PY
payload = "$payload"
crc = 0
for byte in payload.encode("utf-8"):
    crc ^= byte
print(f"{crc:02X}")
PY
}

run_control_tab() {
    : >"$LISTENER_LOG"

    local frame_body="1:1:2=5"
    local frame_crc
    frame_crc="$(calc_crc "$frame_body")"
    local frame="<<<:${frame_body}>>${frame_crc}<<<"

    CONTROL_TAB_DEBUG=1 \
    PYTHONPATH="${ROOT_DIR}/python-client/src:${PYTHONPATH:-}" \
        "$PYTHON_BIN" "${ROOT_DIR}/python-client/daemons/control_tab_listener.py" \
        --port "$APP_TTY" \
        --baudrate 115200 \
        --parity N \
        --stopbits 1 \
        --bytesize 8 \
        --timeout-ms 250 \
        --poll 0.05 \
        --graceful 1.0 \
        --retry-backoff 200 \
        --artisan-bin "$ARTISAN_BIN" \
        --artisan-path "$ARTISAN_PATH" \
        --once \
        >"$LISTENER_LOG" 2>&1 &
    local listener_pid=$!

    sleep 1

    APP_ENV="${APP_ENV:-testing}" \
    DB_CONNECTION="${DB_CONNECTION:-sqlite}" \
    DB_DATABASE="${DB_DATABASE:-${ROOT_DIR}/database/database.sqlite}" \
    "$ARTISAN_BIN" "$ARTISAN_PATH" port:send "$FEED_TTY" "$frame" --newline >/dev/null

    local ack_crc
    ack_crc="$(calc_crc "1:1:2=1")"
    local expect_pattern=">>>:1:1:2=1>>${ack_crc}<<<"

    APP_ENV="${APP_ENV:-testing}" \
    DB_CONNECTION="${DB_CONNECTION:-sqlite}" \
    DB_DATABASE="${DB_DATABASE:-${ROOT_DIR}/database/database.sqlite}" \
    "$ARTISAN_BIN" "$ARTISAN_PATH" port:expect "$FEED_TTY" "$expect_pattern" --timeout=5 >/dev/null

    wait "$listener_pid"

    if ! grep -q '"crc":{"provided":"' "$LISTENER_LOG"; then
        echo "[ERROR] Listener log missing CRC diagnostics. See $LISTENER_LOG" >&2
        exit 1
    fi

    echo "[OK] Control Tab listener processed button frame (log: $LISTENER_LOG)."
}

pty::with_pair run_control_tab

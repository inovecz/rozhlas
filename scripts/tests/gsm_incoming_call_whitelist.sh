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
SQLITE_BIN="${SQLITE_BIN:-$(command -v sqlite3 || true)}"

pty::require_command "$PYTHON_BIN" "python3"
pty::require_command "$ARTISAN_BIN" "php"
pty::require_command "$SOCAT_BIN" "socat"
pty::require_command "$SQLITE_BIN" "sqlite3"

LOG_DIR="${ROOT_DIR}/storage/logs/tests"
mkdir -p "$LOG_DIR"
LISTENER_LOG="${LOG_DIR}/gsm_listener.log"
MODEM_LOG="${LOG_DIR}/gsm_modem.log"

TEST_NUMBER="${GSM_TEST_NUMBER:-+420777888999}"

prepare_database() {
    local tmp_dir
    tmp_dir="$(mktemp -d)"
    local db_path="${tmp_dir}/database.sqlite"

    APP_ENV=testing \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$db_path" \
    "$ARTISAN_BIN" "$ARTISAN_PATH" migrate --force >/dev/null

    sqlite3 "$db_path" <<SQL
INSERT INTO gsm_whitelist_entries (id, number, label, priority, created_at, updated_at)
VALUES ('test-whitelist', '$TEST_NUMBER', 'Whitelist Test', 'high', datetime('now'), datetime('now'));
SQL

    echo "$tmp_dir"
}

start_modem_emulator() {
    local feed_port="$1"
    local log_path="$2"

    MODEM_LOG="$log_path" FEED_TTY="$feed_port" TEST_NUMBER="$TEST_NUMBER" \
        "$PYTHON_BIN" - <<'PY' &
import os
import sys
import time

port_path = os.environ["FEED_TTY"]
log_path = os.environ["MODEM_LOG"]
caller = os.environ.get("TEST_NUMBER", "+420777888999")

def log(message: str) -> None:
    with open(log_path, "a", encoding="utf-8") as handle:
        handle.write(message + "\n")

with open(port_path, "r+b", buffering=0) as port:
    log("MODEM ready")
    call_sent = False
    while True:
        data = port.readline()
        if not data:
            time.sleep(0.05)
            continue
        text = data.decode("utf-8", errors="ignore").strip()
        if not text:
            continue
        log(f"RX {text}")
        upper = text.upper()
        if upper.startswith("AT"):
            if "CPIN" in upper:
                reply = "+CPIN: READY\r\nOK\r\n"
            else:
                reply = "OK\r\n"
            port.write(reply.encode("utf-8"))
            port.flush()
            log(f"TX {reply.strip()}")
            if upper == "AT+CLCC=1" and not call_sent:
                time.sleep(0.2)
                port.write(b"RING\r\n")
                port.flush()
                log("TX RING")
                time.sleep(0.1)
                clip = f'+CLIP: "{caller}",145,"","",0\r\n'
                port.write(clip.encode("utf-8"))
                port.flush()
                log(f"TX {clip.strip()}")
                call_sent = True
            continue

        if upper == "ATA":
            port.write(b"OK\r\n")
            port.flush()
            log("TX OK (ATA)")
            time.sleep(0.2)
            port.write(b'+CIEV: "CALL",1\r\n')
            port.flush()
            log('TX +CIEV: "CALL",1')
            time.sleep(0.5)
            port.write(b'+CIEV: "CALL",0\r\n')
            port.flush()
            log('TX +CIEV: "CALL",0')
            time.sleep(0.1)
            port.write(b"NO CARRIER\r\n")
            port.flush()
            log("TX NO CARRIER")
            break

        if upper == "ATH":
            port.write(b"OK\r\n")
            port.flush()
            log("TX OK (ATH)")
            break

    time.sleep(0.2)
PY
}

run_gsm_test() {
    : >"$LISTENER_LOG"
    : >"$MODEM_LOG"

    local db_tmp_dir
    db_tmp_dir="$(prepare_database)"
    local db_path="${db_tmp_dir}/database.sqlite"

    start_modem_emulator "$FEED_TTY" "$MODEM_LOG"
    local emulator_pid=$!

    APP_ENV=testing \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$db_path" \
    PYTHONPATH="${ROOT_DIR}/python-client/src:${PYTHONPATH:-}" \
        "$PYTHON_BIN" "${ROOT_DIR}/python-client/daemons/gsm_listener.py" \
        --port "$APP_TTY" \
        --baudrate 115200 \
        --parity N \
        --stopbits 1 \
        --bytesize 8 \
        --timeout-ms 500 \
        --poll 0.1 \
        --signal-interval 5 \
        --answer-delay 200 \
        --max-ring 3 \
        --artisan-bin "$ARTISAN_BIN" \
        --artisan-path "$ARTISAN_PATH" \
        --once \
        >"$LISTENER_LOG" 2>&1 &
    local listener_pid=$!

    wait "$listener_pid"
    wait "$emulator_pid" || true

    if ! grep -q 'RX ATA' "$MODEM_LOG"; then
        echo "[ERROR] GSM listener did not attempt to answer the call. See $MODEM_LOG" >&2
        exit 1
    fi

    local session_info
    session_info="$(sqlite3 "$db_path" "SELECT status, authorised FROM gsm_call_sessions ORDER BY created_at DESC LIMIT 1;")"
    if [[ -z "$session_info" ]]; then
        echo "[ERROR] GSM session not persisted to database $db_path." >&2
        exit 1
    fi

    if ! echo "$session_info" | grep -Eq '\|1$'; then
        echo "[ERROR] GSM session not marked as authorised. Row: $session_info" >&2
        exit 1
    fi

    rm -rf "$db_tmp_dir"

    echo "[OK] GSM listener authorised whitelisted call (logs: $LISTENER_LOG, $MODEM_LOG)."
}

pty::with_pair run_gsm_test

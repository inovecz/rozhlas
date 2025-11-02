#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

PYTHON_BIN="${PYTHON_BIN:-python3}"
PHP_BIN="${PHP_BIN:-php}"
ARTISAN_BIN="${ARTISAN_BIN:-$PHP_BIN}"
ARTISAN_PATH="${ARTISAN_PATH:-artisan}"

FAKE_ENV_DIR="$(mktemp -d)"
FAKE_LOG="${FAKE_ENV_DIR}/modbus_commands.log"
FAKE_PYTHON="${FAKE_ENV_DIR}/fake_python.sh"

cat <<'BASH' >"$FAKE_PYTHON"
#!/usr/bin/env bash
set -euo pipefail

log="${FAKE_PYTHON_LOG:?missing FAKE_PYTHON_LOG}"
script="$1"
command="$2"
shift 2 || true

echo "${command} $*" >>"$log"

case "$command" in
  start-stream|stop-stream)
    cat <<'JSON'
{"status":"ok","data":{"status":"ok"}}
JSON
    ;;
  status)
    cat <<'JSON'
{"status":"ok","data":{"tx_control":1,"status":0,"error":0}}
JSON
    ;;
  *)
    cat <<'JSON'
{"status":"ok","data":{}}
JSON
    ;;
esac
BASH
chmod +x "$FAKE_PYTHON"
: >"$FAKE_LOG"

run_command() {
    local cmd="$1"
    APP_ENV="${APP_ENV:-testing}" \
    PYTHON_BINARY="$FAKE_PYTHON" \
    FAKE_PYTHON_LOG="$FAKE_LOG" \
    "$ARTISAN_BIN" "$ARTISAN_PATH" $cmd >/dev/null
}

run_command "rf:tx-start --priority=plan"
run_command "rf:tx-stop --priority=stop"

if ! grep -q '^start-stream ' "$FAKE_LOG"; then
    echo "[ERROR] Expected start-stream invocation not recorded. Log: $FAKE_LOG" >&2
    rm -rf "$FAKE_ENV_DIR"
    exit 1
fi

if ! grep -q '^stop-stream ' "$FAKE_LOG"; then
    echo "[ERROR] Expected stop-stream invocation not recorded. Log: $FAKE_LOG" >&2
    rm -rf "$FAKE_ENV_DIR"
    exit 1
fi

start_index="$(grep -n '^start-stream ' "$FAKE_LOG" | head -n1 | cut -d: -f1)"
stop_index="$(grep -n '^stop-stream ' "$FAKE_LOG" | head -n1 | cut -d: -f1)"

if [[ -z "$start_index" || -z "$stop_index" || "$start_index" -ge "$stop_index" ]]; then
    echo "[ERROR] stop-stream logged before start-stream. Check $FAKE_LOG" >&2
    rm -rf "$FAKE_ENV_DIR"
    exit 1
fi

echo "[OK] RF tx-start/stop commands dispatched via PythonClient (log: $FAKE_LOG)."

rm -rf "$FAKE_ENV_DIR"

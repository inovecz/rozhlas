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
  read-alarms)
    cat <<'JSON'
{"status":"ok","data":{"source_address":4660,"repeat":2,"frames":[4660,4661,4662],"raw":[4660,4661,4662,0,0,0,0,0,0,0]}}
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

OUTPUT="$(
    APP_ENV="${APP_ENV:-testing}" \
    PYTHON_BINARY="$FAKE_PYTHON" \
    FAKE_PYTHON_LOG="$FAKE_LOG" \
    "$ARTISAN_BIN" "$ARTISAN_PATH" alarm:poll --limit=3 --json
)" || {
    status=$?
    echo "[ERROR] alarm:poll command failed (status $status). See $FAKE_LOG" >&2
    rm -rf "$FAKE_ENV_DIR"
    exit $status
}

$PYTHON_BIN - <<'PY' "$OUTPUT"
import json
import sys

payload = json.loads(sys.argv[1])
frames = payload.get("frames") or payload.get("data")
if not frames or frames[:3] != [4660, 4661, 4662]:
    raise SystemExit("Unexpected frames payload")
PY

echo "[OK] Alarm buffer poll returned deterministic frames (log: $FAKE_LOG)."

rm -rf "$FAKE_ENV_DIR"

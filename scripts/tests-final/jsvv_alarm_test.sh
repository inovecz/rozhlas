#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

usage() {
  cat <<'EOF'
Usage: jsvv_alarm_test.sh [--port PATH] [--baud RATE] [--parity {N,E,O}] [--stopbits N] [--bytesize N] [--timeout SEC]

Injects a short series of FAULT frames (battery low, siren fault, cabinet open) over the KPPS serial link.
Use this to verify that alarm SMS notifications are emitted and that the /log endpoint records the events.

Options:
  --port PATH      Serial device for KPPS listener (default: \$JSVV_PORT or /dev/ttyS0)
  --baud RATE      Baudrate (default: \$JSVV_BAUDRATE or 9600)
  --parity MODE    Parity (default: \$JSVV_PARITY or N)
  --stopbits N     Stop bits (default: \$JSVV_STOPBITS or 1)
  --bytesize N     Data bits (default: \$JSVV_BYTESIZE or 8)
  --timeout SEC    Serial timeout (default: \$JSVV_TIMEOUT or 1.0)
  -h, --help       Show this help and exit

Environment overrides:
  JSVV_PORT, JSVV_BAUDRATE, JSVV_PARITY, JSVV_STOPBITS, JSVV_BYTESIZE, JSVV_TIMEOUT

Before running:
  1. Ensure the jsvv_listener daemon (or run_daemons.sh) is active.
  2. Run a queue worker so alarm SMS jobs can dispatch (e.g. php artisan queue:work).
  3. Configure JSVV settings (allowAlarmSms + contacts) via the admin UI.

After running:
  - Check storage/logs/daemons/jsvv_listener.log for FAULT entries.
  - Visit http://127.0.0.1:8001/log to confirm the alarm log entries.
EOF
}

PORT="${JSVV_PORT:-/dev/ttyS0}"
BAUD="${JSVV_BAUDRATE:-9600}"
PARITY="${JSVV_PARITY:-N}"
STOPBITS="${JSVV_STOPBITS:-1}"
BYTESIZE="${JSVV_BYTESIZE:-8}"
TIMEOUT="${JSVV_TIMEOUT:-1.0}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port)
      PORT="${2:-}"
      shift 2
      ;;
    --baud)
      BAUD="${2:-}"
      shift 2
      ;;
    --parity)
      PARITY="${2:-}"
      shift 2
      ;;
    --stopbits)
      STOPBITS="${2:-}"
      shift 2
      ;;
    --bytesize)
      BYTESIZE="${2:-}"
      shift 2
      ;;
    --timeout)
      TIMEOUT="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[ERROR] Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$PORT" ]]; then
  echo "[ERROR] Serial port not specified. Use --port or set JSVV_PORT." >&2
  exit 1
fi

PYTHON_BIN="${JSVV_ALARM_TEST_PYTHON:-python3}"

export JSVV_ALARM_TEST_PORT="$PORT"
export JSVV_ALARM_TEST_BAUD="$BAUD"
export JSVV_ALARM_TEST_PARITY="$PARITY"
export JSVV_ALARM_TEST_STOPBITS="$STOPBITS"
export JSVV_ALARM_TEST_BYTESIZE="$BYTESIZE"
export JSVV_ALARM_TEST_TIMEOUT="$TIMEOUT"
export JSVV_ALARM_TEST_ROOT="$ROOT_DIR"

echo "[INFO] Sending FAULT frames to $PORT (baud=$BAUD parity=$PARITY stopbits=$STOPBITS bytesize=$BYTESIZE timeout=$TIMEOUT)"

"$PYTHON_BIN" - <<'PY'
import os
import sys
import time

ROOT_DIR = os.environ["JSVV_ALARM_TEST_ROOT"]
PY_SRC = os.path.join(ROOT_DIR, "python-client", "src")
if PY_SRC not in sys.path:
    sys.path.insert(0, PY_SRC)

from jsvv.client import JSVVClient, SerialSettings, JSVVError  # type: ignore

settings = SerialSettings(
    port=os.environ["JSVV_ALARM_TEST_PORT"],
    baudrate=int(os.environ["JSVV_ALARM_TEST_BAUD"]),
    parity=os.environ["JSVV_ALARM_TEST_PARITY"].upper(),
    stopbits=int(os.environ["JSVV_ALARM_TEST_STOPBITS"]),
    bytesize=int(os.environ["JSVV_ALARM_TEST_BYTESIZE"]),
    timeout=float(os.environ["JSVV_ALARM_TEST_TIMEOUT"]),
)

scenarios = [
    ("BATTERY", 0x04, "Low battery voltage detected"),
    ("EKPV", 0x21, "Siren driver fault reported"),
    ("KPPS", 0x11, "Cabinet door open sensor active"),
]

try:
    with JSVVClient(settings) as client:
        for device, code, detail in scenarios:
            frame = client.send_frame("FAULT", [device, f"{code}", detail])
            timestamp = time.strftime("%H:%M:%S")
            print(f"[{timestamp}] Sent FAULT:{device}:{code} -> {frame.strip()}")
            time.sleep(1.5)
except JSVVError as exc:
    sys.stderr.write(f"[ERROR] Failed to send FAULT frame: {exc}\n")
    raise SystemExit(1) from exc
except Exception as exc:  # pragma: no cover - unexpected runtime error
    sys.stderr.write(f"[ERROR] Unexpected error: {exc}\n")
    raise SystemExit(1) from exc

print("[INFO] Alarm test frames dispatched. Allow the backend queue to process SMS notifications.")
PY

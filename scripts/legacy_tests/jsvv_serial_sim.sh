#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

usage() {
  cat <<'EOF'
Usage: jsvv_serial_sim.sh --port /dev/ttyUSB0 [options]

Simulates JSVV activation frames over the specified serial device. The script
cycles through REMOTE → STOP → LOCAL → STOP → EXT1 → EXT2 and repeats for the
given duration (default 5 minutes). Use this to drive the listener in local
testing or when looping two PTY endpoints together with socat.

Required:
  --port PATH           Serial device that the listener reads (e.g. /dev/ttyUSB0)

Options:
  --duration SECONDS    Total runtime (default: 300)
  --baud RATE           Baudrate (default: 9600)
  --parity {N,E,O}      Parity (default: N)
  --stopbits N          Stop bits (default: 1)
  --bytesize N          Data bits (default: 8)
  --timeout SEC         Read timeout for the writer (default: 1.0)
  --python PATH         Python interpreter (default: repo .venv or system python3)
  --show-controls       Periodically print ALSA mixer state (card 2, relevant controls)
  -h, --help            Show this help

Environment overrides:
  JSVV_SIM_PORT, JSVV_SIM_DURATION, JSVV_SIM_BAUD, JSVV_SIM_PARITY,
  JSVV_SIM_STOPBITS, JSVV_SIM_BYTESIZE, JSVV_SIM_TIMEOUT, JSVV_SIM_PYTHON,
  JSVV_SIM_SCENARIO (JSON array of [command, params[], priority, hold_seconds, note])

Examples:
  # Loopback using socat (two PTYs):
  #   socat -d -d PTY,raw,echo=0 PTY,raw,echo=0
  # Run listener on first PTY, then simulate on the second:
  scripts/tests/jsvv_serial_sim.sh --port /dev/pts/4 --show-controls

  # Against real KPPS test controller:
  scripts/tests/jsvv_serial_sim.sh --port /dev/ttyUSB0
EOF
}

PORT="${JSVV_SIM_PORT:-}"
DURATION="${JSVV_SIM_DURATION:-300}"
BAUD="${JSVV_SIM_BAUD:-9600}"
PARITY="${JSVV_SIM_PARITY:-N}"
STOPBITS="${JSVV_SIM_STOPBITS:-1}"
BYTESIZE="${JSVV_SIM_BYTESIZE:-8}"
TIMEOUT="${JSVV_SIM_TIMEOUT:-1.0}"
PYTHON_BIN="${JSVV_SIM_PYTHON:-}"
SHOW_CONTROLS=false

DEFAULT_PYTHON="$ROOT_DIR/.venv/bin/python3"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port)
      PORT="${2:-}"
      shift 2
      ;;
    --duration)
      DURATION="${2:-}"
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
    --python)
      PYTHON_BIN="${2:-}"
      shift 2
      ;;
    --show-controls)
      SHOW_CONTROLS=true
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$PORT" ]]; then
  echo "Missing required --port argument." >&2
  usage
  exit 1
fi

if [[ -z "$PYTHON_BIN" ]]; then
  if [[ -x "$DEFAULT_PYTHON" ]]; then
    PYTHON_BIN="$DEFAULT_PYTHON"
  else
    PYTHON_BIN="python3"
  fi
fi

if [[ ! -c "$PORT" ]]; then
  echo "Warning: $PORT is not a character device yet (listener may not be running)." >&2
fi

echo "Simulating JSVV frames on $PORT for $DURATION seconds (baud=$BAUD parity=$PARITY stopbits=$STOPBITS bytesize=$BYTESIZE timeout=$TIMEOUT)"

CONTROL_MONITOR_PID=""
if $SHOW_CONTROLS; then
  (
    while true; do
      echo "---- $(date '+%H:%M:%S') ALSA status ----"
      amixer -c 2 sget 'Left PGA Mixer Mic3L' 2>/dev/null | grep 'Mono:' || true
      amixer -c 2 sget 'Left PGA Mixer Line2L' 2>/dev/null | grep 'Mono:' || true
      amixer -c 2 sget 'Right PGA Mixer Mic3R' 2>/dev/null | grep 'Mono:' || true
      amixer -c 2 sget 'Right PGA Mixer Line2R' 2>/dev/null | grep 'Mono:' || true
      amixer -c 2 sget 'Line' 2>/dev/null | grep -E 'Front Left|Front Right' || true
      sleep 5
    done
  ) &
  CONTROL_MONITOR_PID=$!
fi

cleanup() {
  if [[ -n "$CONTROL_MONITOR_PID" ]]; then
    kill "$CONTROL_MONITOR_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

export JSVV_SIM_PORT="$PORT"
export JSVV_SIM_DURATION="$DURATION"
export JSVV_SIM_BAUD="$BAUD"
export JSVV_SIM_PARITY="$PARITY"
export JSVV_SIM_STOPBITS="$STOPBITS"
export JSVV_SIM_BYTESIZE="$BYTESIZE"
export JSVV_SIM_TIMEOUT="$TIMEOUT"

"$PYTHON_BIN" - <<'PY'
import os
import sys
import time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
PYTHON_SRC = os.path.join(ROOT, "python-client", "src")
if PYTHON_SRC not in sys.path:
    sys.path.insert(0, PYTHON_SRC)

from jsvv.client import JSVVClient, SerialSettings  # type: ignore

settings = SerialSettings(
    port=os.environ["JSVV_SIM_PORT"],
    baudrate=int(os.environ["JSVV_SIM_BAUD"]),
    parity=os.environ["JSVV_SIM_PARITY"].upper(),
    stopbits=int(os.environ["JSVV_SIM_STOPBITS"]),
    bytesize=int(os.environ["JSVV_SIM_BYTESIZE"]),
    timeout=float(os.environ["JSVV_SIM_TIMEOUT"]),
)

DEFAULT_SCENARIO = [
    ("REMOTE", (), "P2", 90, "Enable remote live voice"),
    ("STOP", (), "P1", 5, "Stop remote voice"),
    ("LOCAL", (), "P2", 60, "Switch to local microphone"),
    ("STOP", (), "P1", 5, "Stop local microphone"),
    ("EXT1", (), "P3", 60, "Primary external audio"),
    ("EXT2", (), "P3", 60, "Secondary external audio"),
]

scenario_json = os.environ.get("JSVV_SIM_SCENARIO")
if scenario_json:
    import json

    def sanitize(entry):
        if not isinstance(entry, (list, tuple)):
            raise ValueError("Scenario entries must be list/tuple")
        if len(entry) < 4:
            raise ValueError("Scenario entry must include command, params, priority, hold")
        command = str(entry[0])
        raw_params = entry[1]
        if raw_params is None:
            params = ()
        elif isinstance(raw_params, (list, tuple)):
            params = tuple(raw_params)
        else:
            raise ValueError("Scenario params must be list/tuple/None")
        priority = str(entry[2])
        hold = float(entry[3])
        note = str(entry[4]) if len(entry) > 4 else command
        return command, params, priority, hold, note

    raw = json.loads(scenario_json)
    scenario = [sanitize(item) for item in raw]
else:
    scenario = DEFAULT_SCENARIO

end_time = time.time() + float(os.environ["JSVV_SIM_DURATION"])
iteration = 0

with JSVVClient(settings) as client:
    while time.time() < end_time:
        iteration += 1
        for mid, params, priority, hold, note in scenario:
            if time.time() >= end_time:
                break
            payload_desc = f"#{iteration} {mid} ({priority}) - {note}"
            frame = client.send_frame(mid, params)
            print(f"[{time.strftime('%H:%M:%S')}] Sent {payload_desc}: {frame.strip()}")
            sleep_until = time.time() + hold
            while time.time() < sleep_until and time.time() < end_time:
                time.sleep(0.5)
PY

echo "Simulation finished."

#!/usr/bin/env bash

# Unified helper for JSVV serial simulation.
# - Creates an optional virtual RS-232 pair (socat) so the backend and simulator can talk
# - Guides the operator through wiring/startup steps
# - Runs a five-minute workload that exercises P2/P1 priorities and STOP commands
#
# Usage examples:
#   scripts/jsvv_serial_simulate.sh simulate                 # default 5 min run with virtual ports
#   scripts/jsvv_serial_simulate.sh simulate --duration 180  # shorter run
#   scripts/jsvv_serial_simulate.sh simulate --keep          # keep socat pair alive afterwards
#   scripts/jsvv_serial_simulate.sh device --port /dev/ttyUSB0 --duration 120
#
# In "device" mode you provide an existing serial port (e.g. hardware KPPS loopback)
# and only the workload loop is executed (no socat pair is created).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_VENV_PYTHON="$ROOT_DIR/.venv/bin/python3"
if [[ -x "$DEFAULT_VENV_PYTHON" ]]; then
  PYTHON_BIN="${PYTHON_BIN:-$DEFAULT_VENV_PYTHON}"
else
  PYTHON_BIN="${PYTHON_BIN:-python3}"
fi

DEFAULT_DURATION=300

usage() {
  cat <<'EOF'
Usage:
  scripts/jsvv_serial_simulate.sh simulate [options]
  scripts/jsvv_serial_simulate.sh device --port <serial_device> [options]

Options:
  --duration <seconds>   Total simulation runtime (default: 300)
  --keep                 Keep socat pair alive after the run (simulate mode only)
  --app-port <path>      Override application-side pseudo-terminal path
  --sim-port <path>      Override simulator-side pseudo-terminal path
  -h, --help             Show this help message

Examples:
  scripts/jsvv_serial_simulate.sh simulate
  scripts/jsvv_serial_simulate.sh simulate --duration 600 --keep
  scripts/jsvv_serial_simulate.sh device --port /dev/ttyUSB0 --duration 180
EOF
}

require_tool() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Error: required tool '$1' is not available on PATH." >&2
    exit 1
  fi
}

send_frame() {
  local port="$1"
  shift
  local mid="$1"
  shift
  echo "[SIM] -> MID=${mid} ARGS=($*)"
  "$PYTHON_BIN" "$ROOT_DIR/python-client/jsvv_control.py" \
    --port "$port" \
    trigger \
    --mid "$mid" \
    --params "$@" \
    --send
}

run_workload() {
  local port="$1"
  local duration="$2"
  local deadline=$((SECONDS + duration))

  echo "[SIM] Starting workload for $duration seconds (deadline at $deadline)."
  while (( SECONDS < deadline )); do
    # Standard P2 verbal sequence
    send_frame "$port" VERBAL 4 male
    sleep 20

    # Graceful stop of current broadcast
    send_frame "$port" STOP
    sleep 5

    # High-priority fault to pre-empt anything pending
    send_frame "$port" FAULT KPPS 99 "BatteryLow"
    sleep 10
  done
  echo "[SIM] Workload finished."
}

simulate_mode() {
  require_tool socat
  require_tool "$PYTHON_BIN"

  local duration="$DEFAULT_DURATION"
  local keep_ports=false
  local app_port=""
  local sim_port=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --duration)
        duration="$2"
        shift 2
        ;;
      --keep)
        keep_ports=true
        shift
        ;;
      --app-port)
        app_port="$2"
        shift 2
        ;;
      --sim-port)
        sim_port="$2"
        shift 2
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

  if [[ -z "$app_port" ]]; then
    app_port="$(mktemp -u /tmp/kpps-app-XXXXXX)"
  fi
  if [[ -z "$sim_port" ]]; then
    sim_port="$(mktemp -u /tmp/kpps-sim-XXXXXX)"
  fi

  echo "[SIM] Creating virtual RS-232 pair:"
  echo "      app port : $app_port"
  echo "      sim port : $sim_port"
  socat -d -d pty,raw,echo=0,link="$app_port" pty,raw,echo=0,link="$sim_port" &
  local socat_pid=$!

  cleanup() {
    if [[ "$keep_ports" == false ]]; then
      if kill -0 "$socat_pid" 2>/dev/null; then
        kill "$socat_pid" 2>/dev/null || true
      fi
      rm -f "$app_port" "$sim_port"
    else
      echo "[SIM] Virtual ports kept alive (PID $socat_pid)." >&2
    fi
  }
  trap cleanup EXIT INT TERM

  sleep 1
  if ! kill -0 "$socat_pid" 2>/dev/null; then
    echo "Error: socat terminated unexpectedly." >&2
    exit 1
  fi

  cat <<EOF

[SIM] Virtual loop ready.

1) In another terminal start your backend with:

     JSVV_PORT=$app_port \\
     JSVV_BAUDRATE=9600 \\
     JSVV_PARITY=N \\
     JSVV_STOPBITS=1 \\
     JSVV_BYTESIZE=8 \\
     ./run.sh

2) Wait until the backend reports "JSVV listening..." in logs.

Press ENTER here once the backend is ready to receive frames.
EOF

  read -r _

  run_workload "$sim_port" "$duration"

  if [[ "$keep_ports" == false ]]; then
    echo "[SIM] Cleaning up virtual ports."
  else
    echo "[SIM] Leaving socat running; stop it manually when done (PID $socat_pid)."
  fi
}

device_mode() {
  require_tool "$PYTHON_BIN"

  local duration="$DEFAULT_DURATION"
  local port=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --port)
        port="$2"
        shift 2
        ;;
      --duration)
        duration="$2"
        shift 2
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

  if [[ -z "$port" ]]; then
    echo "Error: --port is required in device mode." >&2
    usage
    exit 1
  fi

  cat <<EOF
[SIM] Using existing serial port: $port

Ensure the backend is already listening on this port (or that you are looping
through a second device). The script will now run the workload for $duration seconds.
Press ENTER to begin.
EOF

  read -r _
  run_workload "$port" "$duration"
}

main() {
  if [[ $# -eq 0 ]]; then
    usage
    exit 1
  fi

  local mode="$1"
  shift

  case "$mode" in
    simulate)
      simulate_mode "$@"
      ;;
    device)
      device_mode "$@"
      ;;
    -h|--help)
      usage
      ;;
    *)
      echo "Unknown mode: $mode" >&2
      usage
      exit 1
      ;;
  esac
}

main "$@"

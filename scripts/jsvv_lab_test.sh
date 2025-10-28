#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_VENV_PYTHON="$ROOT_DIR/.venv/bin/python3"
if [[ -x "$DEFAULT_VENV_PYTHON" ]]; then
  PYTHON_BIN="${PYTHON_BIN:-$DEFAULT_VENV_PYTHON}"
else
  PYTHON_BIN="${PYTHON_BIN:-python3}"
fi

usage() {
  cat <<'EOF'
Usage:
  scripts/jsvv_lab_test.sh simulate [--keep] [--app-port <path>] [--sim-port <path>]
  scripts/jsvv_lab_test.sh device <serial_device>
  scripts/jsvv_lab_test.sh send-sample <serial_device> [command]

Modes:
  simulate      create a virtual RS-232 loop (requires socat) and print next steps
  device        print checklist for real KPPS hardware connected to <serial_device>
  send-sample   send a sample JSVV frame (default STATUS_KPPS) to <serial_device>

Options (simulate mode):
  --keep        keep the virtual ports after exit (socat continues to run)
  --app-port    explicit path for the application side of the pair (default /tmp/kpps-app-XXXX)
  --sim-port    explicit path for the simulator side of the pair (default /tmp/kpps-sim-XXXX)

EOF
}

require_tool() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Error: required tool '$1' is not available on PATH." >&2
    exit 1
  fi
}

send_sample() {
  local port="$1"
  local command="${2:-STATUS_KPPS}"
  require_tool "$PYTHON_BIN"
  echo "Sending JSVV command '$command' to $port"
  "$PYTHON_BIN" "$ROOT_DIR/python-client/jsvv_control.py" \
    --port "$port" \
    trigger "$command"
  echo "Done."
}

simulate_mode() {
  require_tool socat

  local keep_ports=false
  local app_port=""
  local sim_port=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
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

  echo "Creating virtual KPPS loop:"
  echo "  application side: $app_port"
  echo "  simulator side : $sim_port"

  socat -d -d pty,raw,echo=0,link="$app_port" pty,raw,echo=0,link="$sim_port" &
  local socat_pid=$!

  cleanup() {
    if [[ "$keep_ports" == false ]]; then
      if kill -0 "$socat_pid" 2>/dev/null; then
        kill "$socat_pid" 2>/dev/null || true
      fi
      rm -f "$app_port" "$sim_port"
    else
      echo "Virtual ports kept alive (PID $socat_pid). Stop manually when finished." >&2
    fi
  }
  trap cleanup EXIT INT TERM

  sleep 1
  if ! kill -0 "$socat_pid" 2>/dev/null; then
    echo "Error: socat terminated unexpectedly. Check output above." >&2
    exit 1
  fi

  cat <<EOF

Virtual RS-232 pair is ready.

1) Run the application with the following environment override:

     JSVV_PORT=$app_port \\
     JSVV_BAUDRATE=9600 \\
     JSVV_PARITY=N \\
     JSVV_STOPBITS=1 \\
     JSVV_BYTESIZE=8 \\
     ./run.sh

2) In another terminal you can send a sample KPPS command (STATUS_KPPS) like this:

     scripts/jsvv_lab_test.sh send-sample $sim_port STATUS_KPPS

3) To watch frames manually without the PHP backend, run:

     $PYTHON_BIN $ROOT_DIR/python-client/jsvv_control.py \\
       --port $app_port listen --until-timeout

Press Ctrl+C to stop the virtual link (unless --keep was used).

EOF

  # Wait indefinitely so traps stay active (Ctrl+C to exit).
  while sleep 3600; do :; done
}

device_mode() {
  local device="${1:-}"
  if [[ -z "$device" ]]; then
    echo "Error: missing serial device path." >&2
    usage
    exit 1
  fi

  cat <<EOF

Checklist for real KPPS device on $device
-----------------------------------------

1) Ensure RS-232 parameters (9600 8N1, null-modem cable) and connect the KPPS.

2) Start the stack with overrides (or update .env accordingly):

     JSVV_PORT=$device \\
     JSVV_BAUDRATE=9600 \\
     JSVV_PARITY=N \\
     JSVV_STOPBITS=1 \\
     JSVV_BYTESIZE=8 \\
     ./run.sh

3) To verify the port is live before launching the full stack you can run:

     $PYTHON_BIN $ROOT_DIR/python-client/jsvv_control.py \\
       --port $device listen --until-timeout

   (Stop the listener before starting run.sh to avoid port conflicts.)

4) Optional: trigger a frame from KPPS or inject a test frame from a laptop:

     scripts/jsvv_lab_test.sh send-sample $device STATUS_KPPS

   (Only run this while the control channel is NOT actively using the port.)

Audio requirements reminder:
  • Linková úroveň 316 mV RMS (±10 %), impedance 600 Ω (±10 %)
  • Rozsah 120 Hz – 8 kHz, galvanicky oddělený, konektor 3.5 mm jack

EOF
}

main() {
  local mode="${1:-}"
  if [[ -z "$mode" ]]; then
    usage
    exit 1
  fi
  shift

  case "$mode" in
    simulate)
      simulate_mode "$@"
      ;;
    device)
      device_mode "$@"
      ;;
    send-sample)
      local port="${1:-}"
      if [[ -z "$port" ]]; then
        echo "Error: missing serial device for send-sample." >&2
        usage
        exit 1
      fi
      shift
      send_sample "$port" "${1:-STATUS_KPPS}"
      ;;
    *)
      echo "Unknown mode: $mode" >&2
      usage
      exit 1
      ;;
  esac
}

main "$@"

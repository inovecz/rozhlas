#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

DEFAULT_API_BASE="http://127.0.0.1:8001/api"
BASE_URL="${JSVV_TEST_BASE_URL:-$DEFAULT_API_BASE}"
TOKEN="${JSVV_TEST_TOKEN:-}"

SERIAL_PORT="${JSVV_TEST_SERIAL_PORT:-}"
SERIAL_BAUD="${JSVV_TEST_SERIAL_BAUD:-9600}"
SERIAL_PARITY="${JSVV_TEST_SERIAL_PARITY:-N}"
SERIAL_STOPBITS="${JSVV_TEST_SERIAL_STOPBITS:-1}"
SERIAL_BYTESIZE="${JSVV_TEST_SERIAL_BYTESIZE:-8}"
SERIAL_TIMEOUT="${JSVV_TEST_SERIAL_TIMEOUT:-0.5}"

DEFAULT_PYTHON="$ROOT_DIR/.venv/bin/python3"
PYTHON_BIN="${JSVV_TEST_PYTHON:-}"
if [[ -z "$PYTHON_BIN" ]]; then
  if [[ -x "$DEFAULT_PYTHON" ]]; then
    PYTHON_BIN="$DEFAULT_PYTHON"
  else
    PYTHON_BIN="python3"
  fi
fi

AVAILABLE_CASES=(
  activation_http
  stop_http
  serial_activation
  priority_sequence
  preempt_p3_stream
  p1_interrupt
)
CASES_TO_RUN=()

HTTP_CODE=""
HTTP_BODY=""

usage() {
  cat <<'EOF'
Usage: jsvv_tests.sh [options]

Options:
  --base-url URL          API base (default: http://127.0.0.1:8001/api)
  --token TOKEN           Optional bearer token for API calls
  --case NAME             Run a single use case (can be repeated)
  --all                   Run all available use cases
  --serial-port PORT      Serial device for hardware activation test
  --serial-baud VALUE     Serial baudrate (default: 9600)
  --serial-parity VALUE   Serial parity (default: N)
  --serial-stopbits N     Serial stop bits (default: 1)
  --serial-bytesize N     Serial data bits (default: 8)
  --serial-timeout SEC    Serial timeout (default: 0.5)
  --python PATH           Python interpreter for serial helper
  --list                  List available use cases
  -h, --help              Show this message

Environment overrides mirror the flags above (prefix with JSVV_TEST_).

Use cases:
  activation_http     Single SIREN signal via API (baseline)
  stop_http           STOP command via API
  serial_activation   Send SIREN over RS-232 (requires --serial-port)
  priority_sequence   Rapid succession of mixed-priority frames (per KPPS pokyny)
  preempt_p3_stream   Demonstrate JSVV (P2) preempting active P3 audio
  p1_interrupt        Verify STOP (P1) clears running alarm immediately
EOF
}

list_cases() {
  printf '%s\n' "${AVAILABLE_CASES[@]}"
}

curl_json_post() {
  local url="$1"
  local payload="$2"
  local tmp
  tmp=$(mktemp)

  local -a headers=("-H" "Content-Type: application/json" "-H" "Accept: application/json")
  if [[ -n "$TOKEN" ]]; then
    headers+=("-H" "Authorization: Bearer $TOKEN")
  fi

  set +e
  local code
  code=$(printf '%s' "$payload" | curl -sS -o "$tmp" -w '%{http_code}' -X POST "$url" "${headers[@]}" --data @-)
  local status=$?
  set -e

  HTTP_BODY=$(cat "$tmp")
  rm -f "$tmp"

  if [[ $status -ne 0 ]]; then
    HTTP_CODE="0"
    return $status
  fi

  HTTP_CODE="$code"
  return 0
}

curl_json_get() {
  local url="$1"
  local tmp
  tmp=$(mktemp)

  local -a headers=("-H" "Accept: application/json")
  if [[ -n "$TOKEN" ]]; then
    headers+=("-H" "Authorization: Bearer $TOKEN")
  fi

  set +e
  local code
  code=$(curl -sS -o "$tmp" -w '%{http_code}' -X GET "$url" "${headers[@]}")
  local status=$?
  set -e

  HTTP_BODY=$(cat "$tmp")
  rm -f "$tmp"

  if [[ $status -ne 0 ]]; then
    HTTP_CODE="0"
    return $status
  fi

  HTTP_CODE="$code"
  return 0
}

json_get_field() {
  local json="$1"
  local path="$2"
  printf '%s' "$json" | python3 - "$path" <<'PY'
import json, sys
data = json.load(sys.stdin)
path = sys.argv[1].split('.')
value = data
for key in path:
    if isinstance(value, dict):
        value = value.get(key)
    else:
        value = None
        break
if value is None:
    print("")
elif isinstance(value, (dict, list)):
    import json as _json
    print(_json.dumps(value))
else:
    print(value)
PY
}

print_http_result() {
  echo "HTTP status: $HTTP_CODE"
  if [[ -n "$HTTP_BODY" ]]; then
    echo "Response: $HTTP_BODY"
  fi
}

post_jsvv_event() {
  local description="$1"
  local payload="$2"
  local endpoint="${BASE_URL%/}/jsvv/events"

  echo
  echo "==> $description"
  echo "Payload: $payload"

  if ! curl_json_post "$endpoint" "$payload"; then
    echo "Request failed (curl exit)." >&2
    print_http_result
    return 1
  fi

  print_http_result
  return 0
}

send_jsvv_command() {
  local description="$1"
  local command="$2"
  local priority="$3"
  local params_json="${4:-{}}"
  local type="${5:-ACTIVATION}"
  local raw_override="${6:-}"

  local timestamp raw
  timestamp=$(date +%s)
  raw="${raw_override:-$command $timestamp}"

  local payload
  payload=$(cat <<EOF
{"raw":"$raw","payload":{"networkId":1,"vycId":1,"kppsAddress":"0x0001","operatorId":42,"type":"$type","command":"$command","params":$params_json,"priority":"$priority","timestamp":$timestamp,"rawMessage":"$raw"}}
EOF
)

  post_jsvv_event "$description" "$payload"
}

start_manual_stream() {
  local note="$1"
  local payload
  payload=$(cat <<EOF
{"source":"fm_radio","route":[],"locations":[],"options":{"origin":"jsvv_test","note":"$note"}}
EOF
)

  echo
  echo "==> Starting placeholder stream ($note)"
  if ! curl_json_post "${BASE_URL%/}/live-broadcast/start" "$payload"; then
    echo "Failed to start stream (curl exit)." >&2
    print_http_result
    return 1
  fi

  print_http_result
  local session_id
  session_id=$(json_get_field "$HTTP_BODY" "session.id")
  if [[ -n "$session_id" ]]; then
    echo "Session ID: $session_id"
  fi
  return 0
}

attempt_start_stream_blocked() {
  echo
  echo "==> Attempting low-priority stream while JSVV active"
  if start_manual_stream "conflict_probe"; then
    if [[ "$HTTP_CODE" == "409" ]]; then
      echo "✓ Backend blocked new stream during JSVV (HTTP 409)."
    else
      echo "✗ Expected HTTP 409 during JSVV activity." >&2
    fi
  else
    echo "Stream attempt failed (curl error)." >&2
  fi
}

stop_manual_stream() {
  echo
  echo "==> Requesting manual stream stop"
  if ! curl_json_post "${BASE_URL%/}/live-broadcast/stop" '{"reason":"jsvv_test_cleanup"}'; then
    echo "Failed to stop stream (curl exit)." >&2
    print_http_result
    return 1
  fi
  print_http_result
}

query_status_snapshot() {
  local label="$1"
  echo
  echo "==> Snapshot after $label"
  if curl_json_get "${BASE_URL%/}/live-broadcast/status"; then
    print_http_result
  else
    echo "Unable to fetch status (curl exit)." >&2
    print_http_result
  fi
}

case_activation_http() {
  send_jsvv_command "JSVV activation (SIREN_SIGNAL)" "SIREN_SIGNAL" "P2" '{"signalType":1}'
}

case_stop_http() {
  send_jsvv_command "JSVV STOP command" "STOP" "P1" '{"reason":"api_stop_test"}' "CONTROL" "STOP"
}

case_serial_activation() {
  if [[ -z "$SERIAL_PORT" ]]; then
    echo "Skipping serial activation (use --serial-port)." >&2
    return 0
  fi

  echo
  echo "==> Serial activation via $SERIAL_PORT"
  PYTHONPATH="$ROOT_DIR/python-client/src${PYTHONPATH:+:$PYTHONPATH}" \
    "$PYTHON_BIN" - "$SERIAL_PORT" "$SERIAL_BAUD" "$SERIAL_PARITY" "$SERIAL_STOPBITS" "$SERIAL_BYTESIZE" "$SERIAL_TIMEOUT" <<'PY'
import sys
import time
from pathlib import Path

project_root = Path(__file__).resolve().parents[2]
client_path = project_root / "python-client" / "src"
if str(client_path) not in sys.path:
    sys.path.insert(0, str(client_path))

from jsvv import JSVVClient, SerialSettings, JSVVError  # type: ignore

if len(sys.argv) != 7:
    print("Invalid serial arguments", file=sys.stderr)
    raise SystemExit(1)

port, baud, parity, stopbits, bytesize, timeout = sys.argv[1:]
timestamp = int(time.time())
settings = SerialSettings(
    port=port,
    baudrate=int(baud),
    parity=parity.upper(),
    stopbits=int(stopbits),
    bytesize=int(bytesize),
    timeout=float(timeout),
)

try:
    with JSVVClient(settings=settings) as client:
        frame = client.send_frame("SIREN", [1, timestamp])
        print(f"Frame sent: {frame.strip()}")
except JSVVError as exc:
    print(f"JSVV client error: {exc}", file=sys.stderr)
    raise SystemExit(1)
except Exception as exc:  # pragma: no cover
    print(f"Serial write failed: {exc}", file=sys.stderr)
    raise SystemExit(1)
PY
}

case_priority_sequence() {
  echo
  echo "==> Priority sequencing (Pokyny KPPS)"
  send_jsvv_command "1) P2 SIREN signal" "SIREN_SIGNAL" "P2" '{"signalType":1}'
  sleep 2
  send_jsvv_command "2) P2 VERBAL_INFO (slot 3)" "VERBAL_INFO" "P2" '{"slot":3,"voice":"male"}'
  sleep 2
  send_jsvv_command "3) P3 TEST command" "TEST" "P3" '{"context":"diagnostics"}' "MAINTENANCE" "TEST"
  sleep 1
  send_jsvv_command "4) Cleanup STOP (P1)" "STOP" "P1" '{"reason":"priority_sequence_cleanup"}' "CONTROL" "STOP"
  query_status_snapshot "priority sequence"
}

case_preempt_p3_stream() {
  echo
  echo "==> Preemption test (JSVV overrides active stream)"
  if ! start_manual_stream "preemption_setup"; then
    echo "Unable to start baseline stream; skipping preemption flow." >&2
    return 1
  fi
  sleep 2

  send_jsvv_command "JSVV SIREN (P2) to preempt stream" "SIREN_SIGNAL" "P2" '{"signalType":1}'
  sleep 1
  attempt_start_stream_blocked
  send_jsvv_command "STOP to release control channel" "STOP" "P1" '{"reason":"preempt_cleanup"}' "CONTROL" "STOP"
  sleep 1
  query_status_snapshot "preemption scenario"
}

case_p1_interrupt() {
  echo
  echo "==> P1 STOP interrupts active JSVV"
  send_jsvv_command "Start SIREN (P2)" "SIREN_SIGNAL" "P2" '{"signalType":2}'
  sleep 2
  send_jsvv_command "STOP (P1) should terminate immediately" "STOP" "P1" '{"reason":"p1_interrupt"}' "CONTROL" "STOP"
  sleep 1
  query_status_snapshot "P1 interrupt"
}

run_case() {
  local name="$1"
  case "$name" in
    activation_http) case_activation_http ;;
    stop_http) case_stop_http ;;
    serial_activation) case_serial_activation ;;
    priority_sequence) case_priority_sequence ;;
    preempt_p3_stream) case_preempt_p3_stream ;;
    p1_interrupt) case_p1_interrupt ;;
    *)
      echo "Unknown use case: $name" >&2
      return 1
      ;;
  esac
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url)
      [[ $# -lt 2 ]] && { echo "Missing value for --base-url" >&2; exit 1; }
      BASE_URL="$2"
      shift 2
      ;;
    --token)
      [[ $# -lt 2 ]] && { echo "Missing value for --token" >&2; exit 1; }
      TOKEN="$2"
      shift 2
      ;;
    --case)
      [[ $# -lt 2 ]] && { echo "Missing value for --case" >&2; exit 1; }
      CASES_TO_RUN+=("$2")
      shift 2
      ;;
    --all)
      CASES_TO_RUN=("${AVAILABLE_CASES[@]}")
      shift
      ;;
    --serial-port)
      [[ $# -lt 2 ]] && { echo "Missing value for --serial-port" >&2; exit 1; }
      SERIAL_PORT="$2"
      shift 2
      ;;
    --serial-baud)
      [[ $# -lt 2 ]] && { echo "Missing value for --serial-baud" >&2; exit 1; }
      SERIAL_BAUD="$2"
      shift 2
      ;;
    --serial-parity)
      [[ $# -lt 2 ]] && { echo "Missing value for --serial-parity" >&2; exit 1; }
      SERIAL_PARITY="$2"
      shift 2
      ;;
    --serial-stopbits)
      [[ $# -lt 2 ]] && { echo "Missing value for --serial-stopbits" >&2; exit 1; }
      SERIAL_STOPBITS="$2"
      shift 2
      ;;
    --serial-bytesize)
      [[ $# -lt 2 ]] && { echo "Missing value for --serial-bytesize" >&2; exit 1; }
      SERIAL_BYTESIZE="$2"
      shift 2
      ;;
    --serial-timeout)
      [[ $# -lt 2 ]] && { echo "Missing value for --serial-timeout" >&2; exit 1; }
      SERIAL_TIMEOUT="$2"
      shift 2
      ;;
    --python)
      [[ $# -lt 2 ]] && { echo "Missing value for --python" >&2; exit 1; }
      PYTHON_BIN="$2"
      shift 2
      ;;
    --list)
      list_cases
      exit 0
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

if [[ ${#CASES_TO_RUN[@]} -eq 0 ]]; then
  CASES_TO_RUN=("${AVAILABLE_CASES[@]}")
fi

overall_status=0
for case_name in "${CASES_TO_RUN[@]}"; do
  if ! run_case "$case_name"; then
    overall_status=1
  fi
done

exit "$overall_status"

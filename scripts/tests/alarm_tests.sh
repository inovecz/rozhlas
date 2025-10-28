#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

DEFAULT_API_BASE="http://127.0.0.1:8001/api"
BASE_URL="${ALARM_TEST_BASE_URL:-$DEFAULT_API_BASE}"
TOKEN="${ALARM_TEST_TOKEN:-}"

MODBUS_PORT="${ALARM_TEST_MODBUS_PORT:-}"
MODBUS_BAUD="${ALARM_TEST_MODBUS_BAUD:-57600}"
MODBUS_PARITY="${ALARM_TEST_MODBUS_PARITY:-N}"
MODBUS_STOPBITS="${ALARM_TEST_MODBUS_STOPBITS:-1}"
MODBUS_BYTESIZE="${ALARM_TEST_MODBUS_BYTESIZE:-8}"
MODBUS_TIMEOUT="${ALARM_TEST_MODBUS_TIMEOUT:-1.0}"
MODBUS_UNIT_ID="${ALARM_TEST_MODBUS_UNIT_ID:-1}"
BUFFER_LOOPS="${ALARM_TEST_BUFFER_LOOPS:-2}"
BUFFER_DELAY="${ALARM_TEST_BUFFER_DELAY:-1.5}"

DEFAULT_PYTHON="$ROOT_DIR/.venv/bin/python3"
PYTHON_BIN="${ALARM_TEST_PYTHON:-}"
if [[ -z "$PYTHON_BIN" ]]; then
  if [[ -x "$DEFAULT_PYTHON" ]]; then
    PYTHON_BIN="$DEFAULT_PYTHON"
  else
    PYTHON_BIN="python3"
  fi
fi

AVAILABLE_CASES=("process_sample" "poll_buffer" "list_definitions" "list_audio" "status_check")
CASES_TO_RUN=()

usage() {
  cat <<'EOF'
Usage: alarm_tests.sh [options]

Options:
  --base-url URL           API base (default: http://127.0.0.1:8001/api)
  --token TOKEN            Optional bearer token for API calls
  --case NAME              Run a single use case (can be repeated)
  --all                    Run all available use cases
  --modbus-port PORT       Serial port for alarm buffer polling
  --modbus-baud VALUE      Modbus baudrate (default: 57600)
  --modbus-parity VALUE    Modbus parity (default: N)
  --modbus-stopbits N      Modbus stop bits (default: 1)
  --modbus-bytesize N      Modbus data bits (default: 8)
  --modbus-timeout SEC     Modbus timeout (default: 1.0)
  --modbus-unit-id ID      Modbus unit id (default: 1)
  --buffer-loops N         Polling iterations (default: 2)
  --buffer-delay SEC       Delay between polls (default: 1.5)
  --python PATH            Python interpreter for poller
  --list                   List available use cases
  -h, --help               Show this message

Environment overrides:
  ALARM_TEST_BASE_URL, ALARM_TEST_TOKEN,
  ALARM_TEST_MODBUS_PORT, ALARM_TEST_MODBUS_BAUD, ALARM_TEST_MODBUS_PARITY,
  ALARM_TEST_MODBUS_STOPBITS, ALARM_TEST_MODBUS_BYTESIZE, ALARM_TEST_MODBUS_TIMEOUT,
  ALARM_TEST_MODBUS_UNIT_ID, ALARM_TEST_BUFFER_LOOPS, ALARM_TEST_BUFFER_DELAY,
  ALARM_TEST_PYTHON
EOF
}

list_cases() {
  printf '%s\n' "${AVAILABLE_CASES[@]}"
}

call_api_get() {
  local description="$1"
  local path="$2"
  local endpoint="${BASE_URL%/}/${path#/}"
  local response

  echo
  echo "==> $description"
  local -a curl_args=(
    "-sS"
    "-X" "GET"
    "$endpoint"
    "-H" "Accept: application/json"
  )
  if [[ -n "$TOKEN" ]]; then
    curl_args+=("-H" "Authorization: Bearer $TOKEN")
  fi

  if ! response=$(curl "${curl_args[@]}"); then
    echo "Request failed." >&2
    return 1
  fi

  echo "Response: $response"
  return 0
}

case_process_sample() {
  local timestamp
  timestamp=$(date +%s)
  local payload
  payload=$(cat <<EOF
{"networkId":5,"vycId":12,"kppsAddress":"0x1A2B","type":"ACTIVATION","command":"SIREN_SIGNAL","params":{"signalType":1},"priority":"P2","timestamp":$timestamp,"rawMessage":"SIREN 1"}
EOF
)

  echo
  echo "==> Process sample alarm via artisan"
  if php artisan jsvv:process-message "$payload"; then
    echo "Artisan command executed successfully."
  else
    echo "Artisan command failed." >&2
    return 1
  fi
}

case_poll_buffer() {
  if [[ -z "$MODBUS_PORT" ]]; then
    echo "Skipping alarm buffer polling (serial port not provided)." >&2
    return 0
  fi

  local script_path="$ROOT_DIR/sims/alarm_buffer_check.py"
  if [[ ! -f "$script_path" ]]; then
    echo "Alarm buffer helper not found at $script_path." >&2
    return 1
  fi

  echo
  echo "==> Poll Modbus alarm buffer on $MODBUS_PORT"

  PYTHONPATH="$ROOT_DIR/python-client/src${PYTHONPATH:+:$PYTHONPATH}" \
    "$PYTHON_BIN" "$script_path" \
      --port "$MODBUS_PORT" \
      --baudrate "$MODBUS_BAUD" \
      --parity "$MODBUS_PARITY" \
      --stopbits "$MODBUS_STOPBITS" \
      --bytesize "$MODBUS_BYTESIZE" \
      --timeout "$MODBUS_TIMEOUT" \
      --unit-id "$MODBUS_UNIT_ID" \
      --loops "$BUFFER_LOOPS" \
      --delay "$BUFFER_DELAY"
}

case_list_definitions() {
  call_api_get "List JSVV alarm definitions" "jsvv-alarms/all"
}

case_list_audio() {
  call_api_get "List JSVV alarm audio mappings" "jsvv-alarms/audios"
}

case_status_check() {
  call_api_get "Live broadcast status snapshot" "live-broadcast/status"
}

run_case() {
  local name="$1"
  case "$name" in
    process_sample) case_process_sample ;;
    poll_buffer) case_poll_buffer ;;
    list_definitions) case_list_definitions ;;
    list_audio) case_list_audio ;;
    status_check) case_status_check ;;
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
    --modbus-port)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-port" >&2; exit 1; }
      MODBUS_PORT="$2"
      shift 2
      ;;
    --modbus-baud)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-baud" >&2; exit 1; }
      MODBUS_BAUD="$2"
      shift 2
      ;;
    --modbus-parity)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-parity" >&2; exit 1; }
      MODBUS_PARITY="$2"
      shift 2
      ;;
    --modbus-stopbits)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-stopbits" >&2; exit 1; }
      MODBUS_STOPBITS="$2"
      shift 2
      ;;
    --modbus-bytesize)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-bytesize" >&2; exit 1; }
      MODBUS_BYTESIZE="$2"
      shift 2
      ;;
    --modbus-timeout)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-timeout" >&2; exit 1; }
      MODBUS_TIMEOUT="$2"
      shift 2
      ;;
    --modbus-unit-id)
      [[ $# -lt 2 ]] && { echo "Missing value for --modbus-unit-id" >&2; exit 1; }
      MODBUS_UNIT_ID="$2"
      shift 2
      ;;
    --buffer-loops)
      [[ $# -lt 2 ]] && { echo "Missing value for --buffer-loops" >&2; exit 1; }
      BUFFER_LOOPS="$2"
      shift 2
      ;;
    --buffer-delay)
      [[ $# -lt 2 ]] && { echo "Missing value for --buffer-delay" >&2; exit 1; }
      BUFFER_DELAY="$2"
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

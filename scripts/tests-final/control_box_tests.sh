#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

DEFAULT_BASE_URL="http://127.0.0.1:8001/api/control-tab/events"
BASE_URL="${CONTROL_TAB_TEST_BASE_URL:-$DEFAULT_BASE_URL}"
TOKEN="${CONTROL_TAB_TEST_TOKEN:-}"
AVAILABLE_CASES=("start_stop" "acknowledge_prompt" "lock_panel" "text_field_request")
CASES_TO_RUN=()

EVENT_TYPE_BUTTON=2
EVENT_TYPE_TEXT=3
DEFAULT_SCREEN=1
DEFAULT_PANEL=1

usage() {
  cat <<'EOF'
Usage: control_box_tests.sh [options]

Options:
  --base-url URL        Override target webhook (default: http://127.0.0.1:8001/api/control-tab/events)
  --token TOKEN         Optional bearer token for Authorization header
  --case NAME           Run a single use case (can be repeated)
  --all                 Run all available use cases
  --list                List available use cases
  -h, --help            Show this message

Environment overrides:
  CONTROL_TAB_TEST_BASE_URL, CONTROL_TAB_TEST_TOKEN
EOF
}

list_cases() {
  printf '%s\n' "${AVAILABLE_CASES[@]}"
}

random_uuid() {
  if command -v uuidgen >/dev/null 2>&1; then
    uuidgen
  else
    python3 - <<'PY'
import uuid
print(uuid.uuid4())
PY
  fi
}

calc_crc() {
  local data="$1"
  local crc=0
  local idx char code
  for ((idx=0; idx<${#data}; idx++)); do
    char=${data:idx:1}
    printf -v code '%d' "'$char"
    crc=$((crc ^ code))
  done
  printf '%02X' "$crc"
}

post_payload() {
  local description="$1"
  local payload="$2"
  local response

  echo
  echo "==> $description"
  echo "Payload: $payload"

  local -a curl_args=(
    "-sS"
    "-X" "POST"
    "$BASE_URL"
    "-H" "Content-Type: application/json"
    "-H" "Accept: application/json"
  )
  if [[ -n "$TOKEN" ]]; then
    curl_args+=("-H" "Authorization: Bearer $TOKEN")
  fi

  if ! response=$(printf '%s' "$payload" | curl "${curl_args[@]}" --data @-); then
    echo "Request failed." >&2
    return 1
  fi

  echo "Response: $response"
  return 0
}

send_button_event() {
  local button="$1"
  local description="$2"
  local session="${3:-$(random_uuid)}"
  local screen="${4:-$DEFAULT_SCREEN}"
  local panel="${5:-$DEFAULT_PANEL}"
  local body="${screen}:${panel}:${EVENT_TYPE_BUTTON}=${button}"
  local raw="<<<:${body}>>$(calc_crc "$body")<<<"
  local payload

  payload=$(cat <<EOF
{"type":"button_pressed","screen":$screen,"panel":$panel,"button_id":$button,"sessionId":"$session","raw":"$raw"}
EOF
)
  post_payload "$description" "$payload"
}

send_text_request() {
  local field_id="$1"
  local description="$2"
  local session="${3:-$(random_uuid)}"
  local screen="${4:-$DEFAULT_SCREEN}"
  local panel="${5:-$DEFAULT_PANEL}"
  local payload_token="?${field_id}?"
  local body="${screen}:${panel}:${EVENT_TYPE_TEXT}=${payload_token}"
  local raw="<<<:${body}>>$(calc_crc "$body")<<<"
  local payload

  payload=$(cat <<EOF
{"type":"text_field_request","screen":$screen,"panel":$panel,"field_id":$field_id,"payload":"$payload_token","sessionId":"$session","raw":"$raw"}
EOF
)
  post_payload "$description" "$payload"
}

case_start_stop() {
  local session
  session=$(random_uuid)
  send_button_event 9 "Control box START (button 9)" "$session"
  sleep 1
  send_button_event 10 "Control box STOP (button 10)" "$session"
}

case_acknowledge_prompt() {
  send_button_event 7 "Acknowledge prompt (button 7)"
}

case_lock_panel() {
  send_button_event 16 "Lock control panel (button 16)"
}

case_text_field_request() {
  send_text_request 1 "Request text field 1"
}

run_case() {
  local name="$1"
  case "$name" in
    start_stop) case_start_stop ;;
    acknowledge_prompt) case_acknowledge_prompt ;;
    lock_panel) case_lock_panel ;;
    text_field_request) case_text_field_request ;;
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

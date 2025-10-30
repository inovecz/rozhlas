#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

DEFAULT_URL="${CONTROL_TAB_WEBHOOK:-http://127.0.0.1:8001/api/control-tab/events}"
BASE_URL="${CONTROL_TAB_TEST_BASE_URL:-$DEFAULT_URL}"
TOKEN="${CONTROL_TAB_TEST_TOKEN:-${CONTROL_TAB_TOKEN:-}}"

SCREEN="${CONTROL_TAB_TEST_SCREEN:-1}"
PANEL="${CONTROL_TAB_TEST_PANEL:-1}"
BUTTON_ID=9
FIELD_ID=1
SEND_PANEL=true
SEND_BUTTON=true
SEND_TEXT=false

EVENT_TYPE_PANEL=1
EVENT_TYPE_BUTTON=2
EVENT_TYPE_TEXT=3

usage() {
  cat <<'EOF'
Usage: control_tab_ping.sh [options]

Options:
  --url URL             Override target webhook (default: CONTROL_TAB_WEBHOOK or http://127.0.0.1:8001/api/control-tab/events)
  --token TOKEN         Bearer token for Authorization header (default: CONTROL_TAB_TOKEN)
  --screen N            Screen identifier used in frames (default: 1)
  --panel N             Panel identifier used in frames (default: 1)
  --button N            Send button_pressed event with the given button id (default: 9)
  --text-field N        Send text_field_request for the given field id (disables button event)
  --panel-only          Send just a panel_loaded event
  --skip-panel          Do not send the panel_loaded probe
  -h, --help            Show this help

Environment overrides:
  CONTROL_TAB_WEBHOOK, CONTROL_TAB_TOKEN,
  CONTROL_TAB_TEST_BASE_URL, CONTROL_TAB_TEST_TOKEN,
  CONTROL_TAB_TEST_SCREEN, CONTROL_TAB_TEST_PANEL
EOF
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

  echo
  echo "==> $description"
  echo "POST $BASE_URL"
  echo "Payload: $payload"

  if ! response=$(printf '%s' "$payload" | curl "${curl_args[@]}" --data @-); then
    echo "Request failed." >&2
    return 1
  fi

  echo "Response: $response"
}

build_session_id() {
  local provided="$1"
  if [[ -n "$provided" ]]; then
    printf '%s' "$provided"
  else
    random_uuid
  fi
}

send_panel_loaded() {
  local session
  session=$(build_session_id "$1")
  local body="${SCREEN}:${PANEL}:${EVENT_TYPE_PANEL}=0"
  local raw="<<<:${body}>>$(calc_crc "$body")<<<"
  local payload

  payload=$(cat <<EOF
{"type":"panel_loaded","screen":$SCREEN,"panel":$PANEL,"sessionId":"$session","raw":"$raw"}
EOF
)
  post_payload "panel_loaded" "$payload"
}

send_button_pressed() {
  local session
  session=$(build_session_id "$1")
  local body="${SCREEN}:${PANEL}:${EVENT_TYPE_BUTTON}=${BUTTON_ID}"
  local raw="<<<:${body}>>$(calc_crc "$body")<<<"
  local payload

  payload=$(cat <<EOF
{"type":"button_pressed","screen":$SCREEN,"panel":$PANEL,"button_id":$BUTTON_ID,"sessionId":"$session","raw":"$raw"}
EOF
)
  post_payload "button_pressed (button ${BUTTON_ID})" "$payload"
}

send_text_request() {
  local session
  session=$(build_session_id "$1")
  local token="?${FIELD_ID}?"
  local body="${SCREEN}:${PANEL}:${EVENT_TYPE_TEXT}=${token}"
  local raw="<<<:${body}>>$(calc_crc "$body")<<<"
  local payload

  payload=$(cat <<EOF
{"type":"text_field_request","screen":$SCREEN,"panel":$PANEL,"field_id":$FIELD_ID,"payload":"$token","sessionId":"$session","raw":"$raw"}
EOF
)
  post_payload "text_field_request (field ${FIELD_ID})" "$payload"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --url)
      [[ $# -lt 2 ]] && { echo "Missing value for --url" >&2; exit 1; }
      BASE_URL="$2"
      shift 2
      ;;
    --token)
      [[ $# -lt 2 ]] && { echo "Missing value for --token" >&2; exit 1; }
      TOKEN="$2"
      shift 2
      ;;
    --screen)
      [[ $# -lt 2 ]] && { echo "Missing value for --screen" >&2; exit 1; }
      SCREEN="$2"
      shift 2
      ;;
    --panel)
      [[ $# -lt 2 ]] && { echo "Missing value for --panel" >&2; exit 1; }
      PANEL="$2"
      shift 2
      ;;
    --button)
      [[ $# -lt 2 ]] && { echo "Missing value for --button" >&2; exit 1; }
      BUTTON_ID="$2"
      shift 2
      ;;
    --text-field)
      [[ $# -lt 2 ]] && { echo "Missing value for --text-field" >&2; exit 1; }
      FIELD_ID="$2"
      SEND_TEXT=true
      SEND_BUTTON=false
      shift 2
      ;;
    --panel-only)
      SEND_PANEL=true
      SEND_BUTTON=false
      SEND_TEXT=false
      shift
      ;;
    --skip-panel)
      SEND_PANEL=false
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

if [[ -z "$BASE_URL" ]]; then
  echo "Control Tab webhook URL is not set (use --url or CONTROL_TAB_WEBHOOK)." >&2
  exit 1
fi

overall_status=0
SESSION_ID="$(random_uuid)"

if [[ "$SEND_PANEL" == true ]]; then
  if ! send_panel_loaded "$SESSION_ID"; then
    overall_status=1
  fi
fi

if [[ "$SEND_BUTTON" == true ]]; then
  if ! send_button_pressed "$SESSION_ID"; then
    overall_status=1
  fi
fi

if [[ "$SEND_TEXT" == true ]]; then
  if ! send_text_request "$SESSION_ID"; then
    overall_status=1
  fi
fi

exit "$overall_status"

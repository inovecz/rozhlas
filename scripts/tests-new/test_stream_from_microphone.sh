#!/usr/bin/env bash
set -euo pipefail

SCRIPT_NAME=$(basename "$0")
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "${SCRIPT_DIR}/../.." && pwd)
cd "$REPO_ROOT"

API_BASE_URL=${API_BASE_URL:-"http://127.0.0.1:8001/api"}
CURL_TIMEOUT=${CURL_TIMEOUT:-5}
ZONES_ARG="101,102"
TIMEOUT_SECONDS=20
DURATION_SECONDS=5
ACTIVE_SESSION=0
cleanup_in_progress=0

print_step() {
  printf '\n[%s] %s\n' "$SCRIPT_NAME" "$*"
}

print_error() {
  printf '[%s][ERROR] %s\n' "$SCRIPT_NAME" "$*" >&2
}

abort() {
  print_error "$*"
  echo "FAIL"
  exit 1
}

require_command() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    abort "Command '$cmd' is required but not available."
  fi
}

normalize_zones() {
  local raw="$1"
  raw=$(echo "$raw" | tr '[:space:]' ',' | tr -cd '0-9,')
  raw=$(echo "$raw" | sed 's/,,*/,/g; s/^,//; s/,$//')
  echo "$raw"
}

api_get() {
  local endpoint="$1"
  local url="$API_BASE_URL/$endpoint"
  if ! response=$(curl --silent --show-error --fail --max-time "$CURL_TIMEOUT" "$url"); then
    abort "HTTP GET $endpoint failed"
  fi
  echo "$response"
}

api_post() {
  local endpoint="$1"
  local payload="$2"
  local url="$API_BASE_URL/$endpoint"
  if ! response=$(curl --silent --show-error --fail --max-time "$CURL_TIMEOUT" -H 'Content-Type: application/json' -X POST "$url" -d "$payload"); then
    abort "HTTP POST $endpoint failed"
  fi
  echo "$response"
}

stop_broadcast() {
  local reason=${1:-"test_stop"}
  set +e
  curl --silent --show-error --max-time "$CURL_TIMEOUT" -H 'Content-Type: application/json' -X POST \
    "$API_BASE_URL/live-broadcast/stop" -d "{\"reason\":\"${reason}\"}" >/dev/null 2>&1
  set -e
}

cleanup() {
  if [[ $cleanup_in_progress -eq 1 ]]; then
    return
  fi
  cleanup_in_progress=1
  if [[ $ACTIVE_SESSION -eq 1 ]]; then
    stop_broadcast "cleanup"
  fi
}
trap cleanup EXIT

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --zones)
        ZONES_ARG="$2"
        shift 2
        ;;
      --timeout)
        TIMEOUT_SECONDS="$2"
        shift 2
        ;;
      --duration)
        DURATION_SECONDS="$2"
        shift 2
        ;;
      --help)
        cat <<USAGE
Usage: $SCRIPT_NAME [--zones "101,102"] [--timeout SECONDS] [--duration SECONDS]
USAGE
        exit 0
        ;;
      *)
        abort "Unknown argument: $1"
        ;;
    esac
  done
}

parse_args "$@"

for cmd in curl jq; do
  require_command "$cmd"
done

ZONES_CSV=$(normalize_zones "$ZONES_ARG")
if [[ -z "$ZONES_CSV" ]]; then
  abort "No valid zones specified."
fi
IFS=',' read -ra ZONES <<< "$ZONES_CSV"
if [[ ${#ZONES[@]} -eq 0 ]]; then
  abort "Invalid zone list provided."
fi
ZONES_JSON=$(printf '%s\n' "${ZONES[@]}" | jq -s 'map(tonumber)' )

print_step "Checking HTTP API availability..."
api_get "live-broadcast/status" >/dev/null

print_step "Stopping any existing broadcast..."
stop_broadcast "baseline"

print_step "Switching audio input to microphone..."
api_post "audio/input" '{"identifier":"mic"}' >/dev/null
AUDIO_STATUS=$(api_get "audio")
CURRENT_INPUT_ID=$(echo "$AUDIO_STATUS" | jq -r '.status.current.input.id // empty')
if [[ -z "$CURRENT_INPUT_ID" || "$CURRENT_INPUT_ID" == "null" ]]; then
  CURRENT_INPUT_LABEL=$(echo "$AUDIO_STATUS" | jq -r '.status.current.input.label // empty')
  CURRENT_INPUT_ID=$(echo "$CURRENT_INPUT_LABEL" | tr '[:upper:]' '[:lower:]')
else
  CURRENT_INPUT_ID=$(echo "$CURRENT_INPUT_ID" | tr '[:upper:]' '[:lower:]')
fi
if [[ "$CURRENT_INPUT_ID" != "mic" && "$CURRENT_INPUT_ID" != "microphone" && "$CURRENT_INPUT_ID" != "mikrofon" ]]; then
  abort "Audio input state could not be verified as microphone"
fi

print_step "Starting microphone broadcast..."
START_PAYLOAD=$(jq -n --argjson zones "$ZONES_JSON" '{source:"microphone", locations:$zones}')
api_post "live-broadcast/start" "$START_PAYLOAD" >/dev/null
ACTIVE_SESSION=1

print_step "Waiting for broadcast to start (timeout ${TIMEOUT_SECONDS}s)..."
start_time=$(date +%s)
STATUS_JSON=""
while true; do
  STATUS_JSON=$(api_get "live-broadcast/status")
  STATE=$(echo "$STATUS_JSON" | jq -r '.session.status // empty')
  if [[ "$STATE" == "running" ]]; then
    break
  fi
  now=$(date +%s)
  if (( now - start_time >= TIMEOUT_SECONDS )); then
    abort "Timed out waiting for broadcast to start"
  fi
  sleep 1
done

SOURCE=$(echo "$STATUS_JSON" | jq -r '.session.source // empty')
if [[ "$SOURCE" != "microphone" ]]; then
  abort "Unexpected broadcast source: $SOURCE"
fi

SESSION_ZONES=$(echo "$STATUS_JSON" | jq '.session.applied.zones // .session.zones // []')
SESSION_ZONE_COUNT=$(echo "$SESSION_ZONES" | jq 'length')
if [[ "$SESSION_ZONE_COUNT" -eq 0 ]]; then
  abort "No destination zones reported in session status"
fi
for zone in $(echo "$SESSION_ZONES" | jq -r '.[]'); do
  match=0
  for expected in "${ZONES[@]}"; do
    if [[ "$zone" -eq "$expected" ]]; then
      match=1
      break
    fi
  done
  if [[ $match -eq 0 ]]; then
    abort "Session zone $zone not present in requested zone list"
  fi
done

print_step "Broadcast running. Waiting ${DURATION_SECONDS}s..."
sleep "$DURATION_SECONDS"

print_step "Stopping broadcast..."
stop_broadcast "microphone_test"
ACTIVE_SESSION=0

print_step "Verifying system returned to idle state..."
FINAL_STATUS=$(api_get "live-broadcast/status")
FINAL_STATE=$(echo "$FINAL_STATUS" | jq -r '.session.status // "idle"')
if [[ "$FINAL_STATE" == "running" ]]; then
  abort "Broadcast still running after stop command"
fi

echo "PASS"

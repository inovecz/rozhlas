#!/usr/bin/env bash
set -euo pipefail

BASE_URL=${1:-http://127.0.0.1/api/control-tab/events}

require_cmd() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Command '$cmd' is required to run this script." >&2
    exit 1
  fi
}

require_cmd curl
require_cmd jq

SESSION_ID=$(uuidgen 2>/dev/null || cat /proc/sys/kernel/random/uuid || date +%s)

build_payload() {
  local button=$1
  jq -nc \
    --arg type "button_pressed" \
    --arg sessionId "$SESSION_ID" \
    --argjson screen 3 \
    --argjson panel 1 \
    --argjson button_id "$button" \
    '{type: $type, sessionId: $sessionId, screen: $screen, panel: $panel, button_id: $button_id}'
}

post_event() {
  local description=$1
  local payload=$2
  echo ">>> ${description}"
  printf '%s\n' "$payload" | curl -sS -X POST "${BASE_URL}" \
    -H "Content-Type: application/json" \
    -d @- || true
  echo -e "\n"
}

start_payload=$(build_payload 9)
stop_payload=$(build_payload 10)

post_event "Simulating Control Box START (button 9)" "$start_payload"
sleep 2
post_event "Simulating Control Box STOP (button 10)" "$stop_payload"

echo "Test completed."
  --arg type "button_pressed" \
  --arg sessionId "$SESSION_ID" \
  --argjson screen 3 \
  --argjson panel 1 \
  --argjson button_id 9 \
  '{type: $type, sessionId: $sessionId, screen: $screen, panel: $panel, button_id: $button_id}')

stop_payload=$(jq -n \
  --arg type "button_pressed" \
  --arg sessionId "$SESSION_ID" \
  --argjson screen 3 \
  --argjson panel 1 \
  --argjson button_id 10 \
  '{type: $type, sessionId: $sessionId, screen: $screen, panel: $panel, button_id: $button_id}')

post_event "Simulating Control Box START (button 9)" "$start_payload"
sleep 2
post_event "Simulating Control Box STOP (button 10)" "$stop_payload"

echo "Test completed."

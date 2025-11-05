#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT_DIR/.env"

trim_whitespace() {
  local value="$1"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "$value"
}

find_card_in_output() {
  local output="$1"
  shift
  local -a keywords=("$@")
  local regex='^card[[:space:]]+([0-9]+):[[:space:]]*([^[]+)'

  while IFS= read -r line; do
    if [[ $line =~ $regex ]]; then
      local card="${BASH_REMATCH[1]}"
      local raw_name="${BASH_REMATCH[2]}"
      local name
      name="$(trim_whitespace "$raw_name")"
      local name_lower="${name,,}"

      for keyword in "${keywords[@]}"; do
        local trimmed_keyword
        trimmed_keyword="$(trim_whitespace "$keyword")"
        if [[ -z "$trimmed_keyword" ]]; then
          continue
        fi
        local keyword_lower="${trimmed_keyword,,}"
        if [[ "$name_lower" == *"$keyword_lower"* ]]; then
          echo "$card"
          return 0
        fi
      done
    fi
  done <<<"$output"

  return 1
}

detect_audio_card() {
  local -a keywords=("$@")
  if [[ ${#keywords[@]} -eq 0 ]]; then
    keywords=("aic3x" "aic3107" "tlv320" "soundcard")
  fi

  local output=""
  local card_id=""
  if command -v aplay >/dev/null 2>&1; then
    if output=$(aplay -l 2>/dev/null); then
      if card_id=$(find_card_in_output "$output" "${keywords[@]}"); then
        echo "$card_id"
        return 0
      fi
    fi
  fi

  if command -v arecord >/dev/null 2>&1; then
    if output=$(arecord -l 2>/dev/null); then
      if card_id=$(find_card_in_output "$output" "${keywords[@]}"); then
        echo "$card_id"
        return 0
      fi
    fi
  fi

  return 1
}

update_env_var() {
  local key="$1"
  local value="$2"
  local file="$ENV_FILE"

  if [[ ! -f "$file" ]]; then
    printf '%s=%s\n' "$key" "$value" >>"$file"
    return
  fi

  local current=""
  current=$(awk -F '=' -v k="$key" '$1 == k {print substr($0, index($0, "=") + 1); exit}' "$file")

  if [[ "$current" == "$value" ]]; then
    return
  fi

  local tmp
  tmp=$(mktemp)
  awk -v k="$key" -v v="$value" '
    BEGIN { updated = 0 }
    $0 ~ "^"k"=" {
      if (updated == 0) {
        print k "=" v
        updated = 1
      }
      next
    }
    { print }
    END {
      if (updated == 0) {
        print k "=" v
      }
    }
  ' "$file" >"$tmp"
  chmod --reference="$file" "$tmp" 2>/dev/null || true
  mv "$tmp" "$file"
}

serial_port_matches_keywords() {
  local port="$1"
  shift
  local -a keywords=("$@")

  if [[ ! -e "$port" ]]; then
    return 1
  fi

  if [[ ${#keywords[@]} -eq 0 ]]; then
    return 0
  fi

  if ! command -v udevadm >/dev/null 2>&1; then
    return 0
  fi

  local info
  if ! info=$(udevadm info -q property -n "$port" 2>/dev/null); then
    return 1
  fi
  local info_lower="${info,,}"

  for keyword in "${keywords[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$keyword")"
    if [[ -z "$trimmed" ]]; then
      continue
    fi
    local keyword_lower="${trimmed,,}"
    if [[ "$info_lower" != *"$keyword_lower"* ]]; then
      return 1
    fi
  done

  return 0
}

detect_serial_port_by_id() {
  local -a keywords_input=("$@")
  local -a keywords=()
  for keyword in "${keywords_input[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$keyword")"
    if [[ -n "$trimmed" ]]; then
      keywords+=("$trimmed")
    fi
  done
  if [[ ${#keywords[@]} -eq 0 ]]; then
    keywords=("1a86_USB_Serial")
  fi

  local dir="/dev/serial/by-id"

  if [[ ! -d "$dir" ]]; then
    return 1
  fi

  local fallback=""
  for entry in "$dir"/*; do
    [[ -e "$entry" ]] || continue
    local resolved
    resolved=$(readlink -f "$entry")
    if [[ -z "$resolved" || ! -e "$resolved" ]]; then
      continue
    fi

    if serial_port_matches_keywords "$resolved" "${keywords[@]}"; then
      echo "$resolved"
      return 0
    fi

    local name
    name="$(basename "$entry")"
    local name_lower="${name,,}"
    for keyword in "${keywords[@]}"; do
      local keyword_lower="${keyword,,}"
      if [[ "$name_lower" == *"$keyword_lower"* ]]; then
        echo "$resolved"
        return 0
      fi
    done

    if [[ -z "$fallback" ]]; then
      fallback="$resolved"
    fi
  done

  if [[ -n "$fallback" ]]; then
    echo "$fallback"
    return 0
  fi

  return 1
}

auto_configure_mixer_card() {
  local auto_detect="${AUTO_DETECT_MIXER_CARD:-true}"
  local normalized="${auto_detect,,}"
  case "$normalized" in
    ''|'1'|'true'|'yes'|'on')
      ;;
    *)
      return
      ;;
  esac

  local keywords_raw="${AUDIO_MIXER_CARD_KEYWORDS:-${MIXER_CARD_KEYWORDS:-}}"
  local -a keywords=()
  if [[ -n "$keywords_raw" ]]; then
    local IFS=','
    read -r -a keywords <<<"$keywords_raw"
  fi

  local -a filtered_keywords=()
  for keyword in "${keywords[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$keyword")"
    if [[ -n "$trimmed" ]]; then
      filtered_keywords+=("$trimmed")
    fi
  done
  if [[ ${#filtered_keywords[@]} -eq 0 ]]; then
    filtered_keywords=("aic3x" "aic3107" "tlv320" "soundcard")
  fi

  local detected_card=""
  if ! detected_card=$(detect_audio_card "${filtered_keywords[@]}"); then
    echo "Warning: Unable to determine mixer card index automatically; leaving AUDIO_MIXER_CARD=${AUDIO_MIXER_CARD:-unset}." >&2
    return
  fi

  if [[ -z "$detected_card" ]]; then
    echo "Warning: Automatic mixer card detection returned an empty result; leaving existing configuration." >&2
    return
  fi

  local -a updated_keys=()
  if [[ "${AUDIO_MIXER_CARD:-}" != "$detected_card" ]]; then
    update_env_var "AUDIO_MIXER_CARD" "$detected_card"
    export AUDIO_MIXER_CARD="$detected_card"
    updated_keys+=("AUDIO_MIXER_CARD")
  fi

  if [[ "${BROADCAST_MIXER_CARD:-}" != "$detected_card" ]]; then
    update_env_var "BROADCAST_MIXER_CARD" "$detected_card"
    export BROADCAST_MIXER_CARD="$detected_card"
    updated_keys+=("BROADCAST_MIXER_CARD")
  fi

  if [[ ${#updated_keys[@]} -gt 0 ]]; then
    echo "Detected mixer card index ${detected_card}; updated ${updated_keys[*]}."
  else
    echo "Detected mixer card index ${detected_card}; existing configuration already matches."
  fi
}

auto_configure_control_tab_serial() {
  local auto_detect="${AUTO_DETECT_CONTROL_TAB_SERIAL:-true}"
  local normalized="${auto_detect,,}"
  case "$normalized" in
    ''|'1'|'true'|'yes'|'on')
      ;;
    *)
      echo "Skipping Control Tab serial detection (AUTO_DETECT_CONTROL_TAB_SERIAL=${auto_detect})."
      return
      ;;
  esac

  local current_port="${CONTROL_TAB_SERIAL_PORT:-}"
  if [[ -n "$current_port" && ! -e "$current_port" ]]; then
    echo "Warning: CONTROL_TAB_SERIAL_PORT='${current_port}' not found; attempting auto-detection." >&2
  fi
  echo "Detecting Control Tab serial port..."

  local hints_raw="${CONTROL_TAB_SERIAL_HINTS:-}"
  local -a hints=()
  if [[ -n "$hints_raw" ]]; then
    local IFS=','
    read -r -a hints <<<"$hints_raw"
  fi

  local -a trimmed_hints=()
  for hint in "${hints[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$hint")"
    if [[ -n "$trimmed" ]]; then
      trimmed_hints+=("$trimmed")
    fi
  done

  local id_keywords_raw="${CONTROL_TAB_SERIAL_ID_KEYWORDS:-1a86_USB_Serial}"
  local -a id_keywords=()
  if [[ -n "$id_keywords_raw" ]]; then
    local IFS=','
    read -r -a id_keywords <<<"$id_keywords_raw"
  fi
  local -a trimmed_id_keywords=()
  for keyword in "${id_keywords[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$keyword")"
    if [[ -n "$trimmed" ]]; then
      trimmed_id_keywords+=("$trimmed")
    fi
  done
  if [[ ${#trimmed_id_keywords[@]} -eq 0 ]]; then
    trimmed_id_keywords=("1a86_USB_Serial")
  fi

  local -a default_hints=("/dev/ttyUSB0" "/dev/ttyUSB1" "/dev/ttyACM0")
  local -a candidates=()
  if [[ -n "$current_port" ]]; then
    candidates+=("$current_port")
  fi
  candidates+=("${trimmed_hints[@]}")
  candidates+=("${default_hints[@]}")

  local detected_port=""
  local fallback=""
  for candidate in "${candidates[@]}"; do
    local resolved="$candidate"
    if [[ -z "$resolved" ]]; then
      continue
    fi
    if [[ "$resolved" == /dev/serial/by-id/* || -L "$resolved" ]]; then
      local canonical
      canonical=$(readlink -f "$resolved" 2>/dev/null)
      if [[ -n "$canonical" ]]; then
        resolved="$canonical"
      fi
    fi
    if [[ -z "$resolved" || ! -e "$resolved" ]]; then
      continue
    fi

    if serial_port_matches_keywords "$resolved" "${trimmed_id_keywords[@]}"; then
      detected_port="$resolved"
      break
    fi

    if [[ -z "$fallback" ]]; then
      fallback="$resolved"
    fi
  done

  if [[ -z "$detected_port" ]]; then
    if [[ -n "$fallback" ]]; then
      detected_port="$fallback"
    fi
  fi

  if [[ -z "$detected_port" ]]; then
    local id_keywords_raw="${CONTROL_TAB_SERIAL_ID_KEYWORDS:-}"
    local -a extra_id_keywords=()
    if [[ -n "$id_keywords_raw" ]]; then
      local IFS=','
      read -r -a extra_id_keywords <<<"$id_keywords_raw"
    fi
    local -a trimmed_extra_keywords=()
    for keyword in "${extra_id_keywords[@]}"; do
      local trimmed
      trimmed="$(trim_whitespace "$keyword")"
      if [[ -n "$trimmed" ]]; then
        trimmed_extra_keywords+=("$trimmed")
      fi
    done
    if [[ ${#trimmed_extra_keywords[@]} -eq 0 ]]; then
      trimmed_extra_keywords=("${trimmed_id_keywords[@]}")
    fi
    if ! detected_port=$(detect_serial_port_by_id "${trimmed_extra_keywords[@]}"); then
      echo "Warning: Unable to determine Control Tab serial port automatically; leaving CONTROL_TAB_SERIAL_PORT=${CONTROL_TAB_SERIAL_PORT:-unset}." >&2
      return
    fi
  fi

  if [[ -z "$detected_port" ]]; then
    echo "Warning: Control Tab serial port detection returned an empty result; leaving existing configuration." >&2
    return
  fi

  if [[ "${CONTROL_TAB_SERIAL_PORT:-}" != "$detected_port" ]]; then
    update_env_var "CONTROL_TAB_SERIAL_PORT" "$detected_port"
    export CONTROL_TAB_SERIAL_PORT="$detected_port"
    echo "Detected Control Tab serial port ${detected_port}; updated CONTROL_TAB_SERIAL_PORT."
  else
    echo "Detected Control Tab serial port ${detected_port}; existing configuration already matches."
  fi
}

auto_configure_gsm_serial() {
  local auto_detect="${AUTO_DETECT_GSM_SERIAL:-true}"
  local normalized="${auto_detect,,}"
  case "$normalized" in
    ''|'1'|'true'|'yes'|'on')
      ;;
    *)
      echo "Skipping GSM serial detection (AUTO_DETECT_GSM_SERIAL=${auto_detect})."
      return
      ;;
  esac

  local current_port="${GSM_SERIAL_PORT:-}"
  if [[ -n "$current_port" && ! -e "$current_port" ]]; then
    echo "Warning: GSM_SERIAL_PORT='${current_port}' not found; attempting auto-detection." >&2
  fi
  echo "Detecting GSM serial port..."

  local hints_raw="${GSM_SERIAL_HINTS:-}"
  local -a hints=()
  if [[ -n "$hints_raw" ]]; then
    local IFS=','
    read -r -a hints <<<"$hints_raw"
  fi

  local -a trimmed_hints=()
  for hint in "${hints[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$hint")"
    if [[ -n "$trimmed" ]]; then
      trimmed_hints+=("$trimmed")
    fi
  done

  local id_keywords_raw="${GSM_SERIAL_ID_KEYWORDS:-id_vendor_id=1e0e,id_model_id=9001}"
  local -a id_keywords=()
  if [[ -n "$id_keywords_raw" ]]; then
    local IFS=','
    read -r -a id_keywords <<<"$id_keywords_raw"
  fi
  local -a trimmed_id_keywords=()
  for keyword in "${id_keywords[@]}"; do
    local trimmed
    trimmed="$(trim_whitespace "$keyword")"
    if [[ -n "$trimmed" ]]; then
      trimmed_id_keywords+=("$trimmed")
    fi
  done
  if [[ ${#trimmed_id_keywords[@]} -eq 0 ]]; then
    trimmed_id_keywords=("id_vendor_id=1e0e" "id_model_id=9001")
  fi

  local -a default_hints=("/dev/ttyUSB3" "/dev/ttyUSB2" "/dev/ttyUSB4" "/dev/ttyUSB1" "/dev/ttyUSB0" "/dev/ttyACM1" "/dev/ttyACM0")
  local -a candidates=()
  if [[ -n "$current_port" ]]; then
    candidates+=("$current_port")
  fi
  candidates+=("${trimmed_hints[@]}")
  candidates+=("${default_hints[@]}")

  local detected_port=""
  local fallback=""
  for candidate in "${candidates[@]}"; do
    local resolved="$candidate"
    if [[ -z "$resolved" ]]; then
      continue
    fi
    if [[ "$resolved" == /dev/serial/by-id/* || -L "$resolved" ]]; then
      local canonical
      canonical=$(readlink -f "$resolved" 2>/dev/null)
      if [[ -n "$canonical" ]]; then
        resolved="$canonical"
      fi
    fi
    if [[ -z "$resolved" || ! -e "$resolved" ]]; then
      continue
    fi

    if serial_port_matches_keywords "$resolved" "${trimmed_id_keywords[@]}"; then
      detected_port="$resolved"
      break
    fi

    if [[ -z "$fallback" ]]; then
      fallback="$resolved"
    fi
  done

  if [[ -z "$detected_port" ]]; then
    if [[ -n "$fallback" ]]; then
      detected_port="$fallback"
    fi
  fi

  if [[ -z "$detected_port" ]]; then
    local id_keywords_raw="${GSM_SERIAL_ID_KEYWORDS:-}"
    local -a extra_id_keywords=()
    if [[ -n "$id_keywords_raw" ]]; then
      local IFS=','
      read -r -a extra_id_keywords <<<"$id_keywords_raw"
    fi
    local -a trimmed_extra_keywords=()
    for keyword in "${extra_id_keywords[@]}"; do
      local trimmed
      trimmed="$(trim_whitespace "$keyword")"
      if [[ -n "$trimmed" ]]; then
        trimmed_extra_keywords+=("$trimmed")
      fi
    done
    if [[ ${#trimmed_extra_keywords[@]} -eq 0 ]]; then
      trimmed_extra_keywords=("${trimmed_id_keywords[@]}")
    fi
    if ! detected_port=$(detect_serial_port_by_id "${trimmed_extra_keywords[@]}"); then
      echo "Warning: Unable to determine GSM serial port automatically; leaving GSM_SERIAL_PORT=${GSM_SERIAL_PORT:-unset}." >&2
      return
    fi
  fi

  if [[ -z "$detected_port" ]]; then
    echo "Warning: GSM serial port detection returned an empty result; leaving existing configuration." >&2
    return
  fi

  if [[ "${GSM_SERIAL_PORT:-}" != "$detected_port" ]]; then
    update_env_var "GSM_SERIAL_PORT" "$detected_port"
    export GSM_SERIAL_PORT="$detected_port"
    echo "Detected GSM serial port ${detected_port}; updated GSM_SERIAL_PORT."
  else
    echo "Detected GSM serial port ${detected_port}; existing configuration already matches."
  fi

  if [[ -n "${GSM_SIM_PIN:-}" ]]; then
    echo "Submitting SIM PIN to GSM modem..."
    local unlock_output=""
    local unlock_status=0
    unlock_output=$("$ROOT_DIR/python-client/tools/gsm_unlock.py" --port "$detected_port" --pin "$GSM_SIM_PIN" 2>&1) || unlock_status=$?
    if [[ $unlock_status -eq 0 ]]; then
      [[ -n "$unlock_output" ]] && echo "$unlock_output"
    elif [[ $unlock_status -eq 2 ]]; then
      [[ -n "$unlock_output" ]] && echo "$unlock_output"
      echo "Note: Skipping SIM unlock because the port is currently in use."
    else
      [[ -n "$unlock_output" ]] && echo "$unlock_output" >&2
      echo "Warning: Failed to unlock SIM card on ${detected_port}. GSM listener may fail until unlocked." >&2
    fi
  fi
}

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

auto_configure_mixer_card
auto_configure_control_tab_serial
auto_configure_gsm_serial

echo "Caching Laravel configuration (php artisan config:cache)..."
(
  cd "$ROOT_DIR"
  php artisan config:cache >/dev/null
)

LOG_DIR="${LOG_DIR:-$ROOT_DIR/storage/logs/run}"
mkdir -p "$LOG_DIR"

LARAVEL_HOST=${LARAVEL_HOST:-127.0.0.1}
LARAVEL_PORT=${LARAVEL_PORT:-8001}
VITE_HOST=${VITE_HOST:-127.0.0.1}
VITE_PORT=${VITE_PORT:-5173}

APP_ENV_VALUE=${APP_ENV:-local}
APP_ENV_LOWER=$(echo "$APP_ENV_VALUE" | tr '[:upper:]' '[:lower:]')
RUN_VITE=true
if [[ "$APP_ENV_LOWER" == "production" ]]; then
  RUN_VITE=false
fi

RUN_REDIS=${RUN_REDIS:-true}

BACKEND_LOG="$LOG_DIR/backend.log"
FRONTEND_LOG="$LOG_DIR/frontend.log"
ALARM_LOG="$LOG_DIR/alarm-monitor.log"
TWO_WAY_LOG="$LOG_DIR/two-way-monitor.log"
QUEUE_LOG="$LOG_DIR/queue-worker.log"
REDIS_LOG="$LOG_DIR/redis.log"

BACKEND_PID=""
FRONTEND_PID=""
ALARM_PID=""
TWO_WAY_PID=""
QUEUE_PID=""
REDIS_PID=""
REDIS_MANAGED=false

ensure_node_for_frontend() {
  if ! command -v node >/dev/null 2>&1; then
    echo "Error: node is required to run Vite. Install Node.js 18 or newer." >&2
    exit 1
  fi
  local version
  version=$(node -v | sed 's/^v//')
  local major=${version%%.*}
  if [[ -z "$major" || "$major" -lt 18 ]]; then
    echo "Error: Node.js $version detected. Vite requires Node.js 18 or newer." >&2
    exit 1
  fi
  if ! command -v npm >/dev/null 2>&1; then
    echo "Error: npm not found. Install Node.js/npm to build frontend assets." >&2
    exit 1
  fi
}

ensure_production_assets() {
  local manifest="$ROOT_DIR/public/build/manifest.json"
  if [[ -f "$manifest" ]]; then
    return
  fi
  echo "Frontend build assets not found. Running npm run build..."
  ensure_node_for_frontend
  (
    cd "$ROOT_DIR"
    npm run build >>"$FRONTEND_LOG" 2>&1
  )
  if [[ ! -f "$manifest" ]]; then
    echo "Error: npm run build did not produce $manifest. Check $FRONTEND_LOG for details." >&2
    exit 1
  fi
}

function cleanup() {
  echo "\nStopping services..."
  if [[ "$REDIS_MANAGED" == true ]] && [[ -n "$REDIS_PID" ]] && kill -0 "$REDIS_PID" 2>/dev/null; then
    kill "$REDIS_PID" 2>/dev/null || true
    wait "$REDIS_PID" 2>/dev/null || true
  fi
  rm -f "$LOG_DIR/redis.pid"

  if [[ -n "$BACKEND_PID" ]] && kill -0 "$BACKEND_PID" 2>/dev/null; then
    kill "$BACKEND_PID" 2>/dev/null || true
    wait "$BACKEND_PID" 2>/dev/null || true
  fi
  rm -f "$LOG_DIR/backend.pid"

  if [[ -n "$FRONTEND_PID" ]] && kill -0 "$FRONTEND_PID" 2>/dev/null; then
    kill "$FRONTEND_PID" 2>/dev/null || true
    wait "$FRONTEND_PID" 2>/dev/null || true
  fi
  rm -f "$LOG_DIR/vite-dev.pid"

  if [[ -n "$ALARM_PID" ]] && kill -0 "$ALARM_PID" 2>/dev/null; then
    kill "$ALARM_PID" 2>/dev/null || true
    wait "$ALARM_PID" 2>/dev/null || true
  fi
  rm -f "$LOG_DIR/alarm-monitor.pid"

  if [[ -n "$QUEUE_PID" ]] && kill -0 "$QUEUE_PID" 2>/dev/null; then
    kill "$QUEUE_PID" 2>/dev/null || true
    wait "$QUEUE_PID" 2>/dev/null || true
  fi
  rm -f "$LOG_DIR/queue-worker.pid"

  if [[ -n "$TWO_WAY_PID" ]] && kill -0 "$TWO_WAY_PID" 2>/dev/null; then
    kill "$TWO_WAY_PID" 2>/dev/null || true
    wait "$TWO_WAY_PID" 2>/dev/null || true
  fi
  rm -f "$LOG_DIR/two-way-monitor.pid"

  "$ROOT_DIR/run_daemons.sh" stop >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

start_redis() {
  local redis_host redis_port
  redis_host=${REDIS_HOST:-127.0.0.1}
  redis_port=${REDIS_PORT:-6379}

  if command -v redis-cli >/dev/null 2>&1; then
    if redis-cli -h "$redis_host" -p "$redis_port" ping >/dev/null 2>&1; then
      echo "Redis already running on ${redis_host}:${redis_port}; reusing existing instance."
      return 0
    fi
  fi

  if ! command -v redis-server >/dev/null 2>&1; then
    echo "Error: redis-server not found. Install Redis or set RUN_REDIS=false to skip starting it." >&2
    exit 1
  fi

  echo "Starting Redis server (redis-server --port ${redis_port})..."
  redis-server --port "$redis_port" --bind "$redis_host" --save "" --appendonly no >>"$REDIS_LOG" 2>&1 &
  REDIS_PID=$!
  REDIS_MANAGED=true
  echo "$REDIS_PID" > "$LOG_DIR/redis.pid"
  sleep 1
  if ! kill -0 "$REDIS_PID" 2>/dev/null; then
    echo "Error: Redis server failed to start. Check $REDIS_LOG for details." >&2
    exit 1
  fi
}

if [[ "$RUN_VITE" == true ]]; then
  ensure_node_for_frontend
  echo "Starting Vite dev server (npm run dev)..."
  (
    cd "$ROOT_DIR"
    npm run dev -- --host "$VITE_HOST" --port "$VITE_PORT" >>"$FRONTEND_LOG" 2>&1
  ) &
  FRONTEND_PID=$!
  echo "$FRONTEND_PID" > "$LOG_DIR/vite-dev.pid"
  sleep 2
  if ! kill -0 "$FRONTEND_PID" 2>/dev/null; then
    echo "Error: Vite dev server failed to start. Check $FRONTEND_LOG for details." >&2
    exit 1
  fi
else
  echo "Skipping Vite dev server start (APP_ENV=$APP_ENV_VALUE)"
  ensure_production_assets
  rm -f "$LOG_DIR/vite-dev.pid"
fi

echo "Starting Laravel backend (php artisan serve)..."
(
  cd "$ROOT_DIR"
  php artisan serve --host="$LARAVEL_HOST" --port="$LARAVEL_PORT" >>"$BACKEND_LOG" 2>&1
) &
BACKEND_PID=$!
echo "$BACKEND_PID" > "$LOG_DIR/backend.pid"

echo "Starting Python daemons..."
GSM_PORT_ENV="${GSM_SERIAL_PORT:-}"
CONTROL_TAB_PORT_ENV="${CONTROL_TAB_SERIAL_PORT:-}"
JSVV_PORT_ENV="${JSVV_PORT:-}"

if [[ -z "$GSM_PORT_ENV" ]]; then
  if command -v socat >/dev/null 2>&1; then
    echo "Creating virtual GSM serial port at /tmp/ttyGSM0"
    socat -d -d pty,raw,echo=0,link=/tmp/ttyGSM0 pty,raw,echo=0 >>"$LOG_DIR/socat-gsm.log" 2>&1 &
    GSM_SOCAT_PID=$!
    echo $GSM_SOCAT_PID >"$LOG_DIR/socat-gsm.pid"
    export GSM_SERIAL_PORT=/tmp/ttyGSM0
  else
    echo "Warning: GSM listener disabled (GSM_SERIAL_PORT unset and socat not available)."
  fi
fi

if [[ -z "$CONTROL_TAB_PORT_ENV" ]]; then
  if command -v socat >/dev/null 2>&1; then
    echo "Creating virtual Control Tab serial port at /tmp/ttyCTRL0"
    socat -d -d pty,raw,echo=0,link=/tmp/ttyCTRL0 pty,raw,echo=0 >>"$LOG_DIR/socat-control-tab.log" 2>&1 &
    CONTROL_TAB_SOCAT_PID=$!
    echo $CONTROL_TAB_SOCAT_PID >"$LOG_DIR/socat-control-tab.pid"
    export CONTROL_TAB_SERIAL_PORT=/tmp/ttyCTRL0
  else
    echo "Warning: Control tab listener disabled (CONTROL_TAB_SERIAL_PORT unset and socat not available)."
  fi
fi

if [[ -z "$JSVV_PORT_ENV" ]]; then
  export JSVV_PORT=${JSVV_PORT_ENV:-"serial:/tmp/jsvv.sock"}
fi

"$ROOT_DIR/run_daemons.sh" start

ALARM_MONITOR_INTERVAL=${ALARM_MONITOR_INTERVAL:-5}
echo "Starting alarm monitor (php artisan alarms:monitor --interval=$ALARM_MONITOR_INTERVAL)..."
(
  cd "$ROOT_DIR"
  php artisan alarms:monitor --interval="$ALARM_MONITOR_INTERVAL" >>"$ALARM_LOG" 2>&1
) &
ALARM_PID=$!
echo "$ALARM_PID" > "$LOG_DIR/alarm-monitor.pid"

if [[ "$RUN_REDIS" == true ]]; then
  start_redis
else
  echo "Skipping Redis start (RUN_REDIS=${RUN_REDIS})."
fi

echo "Starting queue worker (php artisan queue:work)..."
(
  cd "$ROOT_DIR"
  php artisan queue:work --queue="${QUEUE_NAMES:-activations-high,default}" --sleep="${QUEUE_SLEEP:-1}" --tries="${QUEUE_TRIES:-1}" >>"$QUEUE_LOG" 2>&1
) &
QUEUE_PID=$!
echo "$QUEUE_PID" > "$LOG_DIR/queue-worker.pid"

echo "Starting two-way nest monitor (php artisan two-way:nest-status-monitor)..."
(
  cd "$ROOT_DIR"
  php artisan two-way:nest-status-monitor >>"$TWO_WAY_LOG" 2>&1
) &
TWO_WAY_PID=$!
echo "$TWO_WAY_PID" > "$LOG_DIR/two-way-monitor.pid"
echo "\nServers running:"
echo "  Backend : http://$LARAVEL_HOST:$LARAVEL_PORT"
if [[ "$RUN_VITE" == true ]]; then
  echo "  Frontend: http://$VITE_HOST:$VITE_PORT"
else
  echo "  Frontend: (not running in $APP_ENV_VALUE environment)"
fi
echo "  Queue worker: logs -> $QUEUE_LOG"
echo "Logs stored in $LOG_DIR"
echo "Press Ctrl+C to stop all services."

wait "$BACKEND_PID"
if [[ -n "$FRONTEND_PID" ]]; then
  wait "$FRONTEND_PID"
fi

#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

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

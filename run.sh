#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$ROOT_DIR/storage/logs/run"
mkdir -p "$LOG_DIR"

LARAVEL_HOST=${LARAVEL_HOST:-127.0.0.1}
LARAVEL_PORT=${LARAVEL_PORT:-8001}
VITE_HOST=${VITE_HOST:-127.0.0.1}
VITE_PORT=${VITE_PORT:-5173}

APP_ENV_VALUE=${APP_ENV:-}
if [[ -z "$APP_ENV_VALUE" && -f "$ROOT_DIR/.env" ]]; then
  APP_ENV_VALUE=$(grep '^APP_ENV=' "$ROOT_DIR/.env" | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'" )
fi
APP_ENV_VALUE=${APP_ENV_VALUE:-local}
APP_ENV_LOWER=$(echo "$APP_ENV_VALUE" | tr '[:upper:]' '[:lower:]')
RUN_VITE=true
if [[ "$APP_ENV_LOWER" == "production" ]]; then
  RUN_VITE=false
fi

BACKEND_LOG="$LOG_DIR/backend.log"
FRONTEND_LOG="$LOG_DIR/frontend.log"
ALARM_LOG="$LOG_DIR/alarm-monitor.log"

BACKEND_PID=""
FRONTEND_PID=""
ALARM_PID=""

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
  if [[ -n "$BACKEND_PID" ]] && kill -0 "$BACKEND_PID" 2>/dev/null; then
    kill "$BACKEND_PID" 2>/dev/null || true
    wait "$BACKEND_PID" 2>/dev/null || true
  fi

  if [[ -n "$FRONTEND_PID" ]] && kill -0 "$FRONTEND_PID" 2>/dev/null; then
    kill "$FRONTEND_PID" 2>/dev/null || true
    wait "$FRONTEND_PID" 2>/dev/null || true
  fi

  if [[ -n "$ALARM_PID" ]] && kill -0 "$ALARM_PID" 2>/dev/null; then
    kill "$ALARM_PID" 2>/dev/null || true
    wait "$ALARM_PID" 2>/dev/null || true
  fi

  "$ROOT_DIR/run_daemons.sh" stop >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

if [[ "$RUN_VITE" == true ]]; then
  ensure_node_for_frontend
  echo "Starting Vite dev server (npm run dev)..."
  (
    cd "$ROOT_DIR"
    npm run dev -- --host "$VITE_HOST" --port "$VITE_PORT" >>"$FRONTEND_LOG" 2>&1
  ) &
  FRONTEND_PID=$!
  sleep 2
  if ! kill -0 "$FRONTEND_PID" 2>/dev/null; then
    echo "Error: Vite dev server failed to start. Check $FRONTEND_LOG for details." >&2
    exit 1
  fi
else
  echo "Skipping Vite dev server start (APP_ENV=$APP_ENV_VALUE)"
  ensure_production_assets
fi

echo "Starting Laravel backend (php artisan serve)..."
(
  cd "$ROOT_DIR"
  php artisan serve --host="$LARAVEL_HOST" --port="$LARAVEL_PORT" >>"$BACKEND_LOG" 2>&1
) &
BACKEND_PID=$!

echo "Starting Python daemons..."
"$ROOT_DIR/run_daemons.sh" start

ALARM_MONITOR_INTERVAL=${ALARM_MONITOR_INTERVAL:-5}
echo "Starting alarm monitor (php artisan alarms:monitor --interval=$ALARM_MONITOR_INTERVAL)..."
(
  cd "$ROOT_DIR"
  php artisan alarms:monitor --interval="$ALARM_MONITOR_INTERVAL" >>"$ALARM_LOG" 2>&1
) &
ALARM_PID=$!

echo "\nServers running:"
echo "  Backend : http://$LARAVEL_HOST:$LARAVEL_PORT"
if [[ "$RUN_VITE" == true ]]; then
  echo "  Frontend: http://$VITE_HOST:$VITE_PORT"
else
  echo "  Frontend: (not running in $APP_ENV_VALUE environment)"
fi
echo "Logs stored in $LOG_DIR"
echo "Press Ctrl+C to stop all services."

wait "$BACKEND_PID"
if [[ -n "$FRONTEND_PID" ]]; then
  wait "$FRONTEND_PID"
fi

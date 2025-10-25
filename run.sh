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

echo "Starting Laravel backend (php artisan serve)..."
(
  cd "$ROOT_DIR"
  php artisan serve --host="$LARAVEL_HOST" --port="$LARAVEL_PORT" >>"$BACKEND_LOG" 2>&1
) &
BACKEND_PID=$!

if [[ "$RUN_VITE" == true ]]; then
  echo "Starting Vite dev server (npm run dev)..."
  (
    cd "$ROOT_DIR"
    npm run dev -- --host "$VITE_HOST" --port "$VITE_PORT" >>"$FRONTEND_LOG" 2>&1
  ) &
  FRONTEND_PID=$!
else
  echo "Skipping Vite dev server start (APP_ENV=$APP_ENV_VALUE)"
fi

sleep 2

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

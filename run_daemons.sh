#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_BIN=${PYTHON_BINARY:-python3}
LOG_DIR="${ROOT_DIR}/storage/logs/daemons"

mkdir -p "$LOG_DIR"

start_daemon() {
  local name="$1"
  shift
  local log_file="$LOG_DIR/${name}.log"
  echo "Starting $name..."
  nohup "$PYTHON_BIN" "$@" >>"$log_file" 2>&1 &
  echo $! > "$LOG_DIR/${name}.pid"
}

stop_daemon() {
  local name="$1"
  local pid_file="$LOG_DIR/${name}.pid"
  if [[ -f "$pid_file" ]];
  then
    local pid
    pid=$(cat "$pid_file")
    if kill -0 "$pid" 2>/dev/null; then
      echo "Stopping $name (PID $pid)..."
      kill "$pid"
      wait "$pid" 2>/dev/null || true
    fi
    rm -f "$pid_file"
  fi
}

case "${1:-}" in
  start)
    start_daemon "gsm_listener" "$ROOT_DIR/python-client/daemons/gsm_listener.py" --webhook "${GSM_WEBHOOK:-http://127.0.0.1/api/gsm/events}"
    start_daemon "jsvv_listener" "$ROOT_DIR/python-client/daemons/jsvv_listener.py" --webhook "${JSVV_WEBHOOK:-http://127.0.0.1/api/jsvv/events}"
    ;;
  stop)
    stop_daemon "gsm_listener"
    stop_daemon "jsvv_listener"
    ;;
  restart)
    "$0" stop
    sleep 1
    "$0" start
    ;;
  status)
    for name in gsm_listener jsvv_listener; do
      pid_file="$LOG_DIR/${name}.pid"
      if [[ -f "$pid_file" ]]; then
        pid=$(cat "$pid_file")
        if kill -0 "$pid" 2>/dev/null; then
          echo "$name running (PID $pid)"
        else
          echo "$name pid file present but process not running"
        fi
      else
        echo "$name not running"
      fi
    done
    ;;
  *)
    echo "Usage: $0 {start|stop|restart|status}"
    exit 1
    ;;
 esac

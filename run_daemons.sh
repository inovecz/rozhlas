#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_VENV_PYTHON="$ROOT_DIR/.venv/bin/python3"
if [[ -x "$DEFAULT_VENV_PYTHON" ]]; then
  PYTHON_BIN=${PYTHON_BINARY:-$DEFAULT_VENV_PYTHON}
else
  PYTHON_BIN=${PYTHON_BINARY:-python3}
fi
LOG_DIR="${ROOT_DIR}/storage/logs/daemons"

mkdir -p "$LOG_DIR"
mkdir -p "${ROOT_DIR}/storage/run"

ENV_FILE="$ROOT_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

export PYTHONPATH="${ROOT_DIR}/python-client/src:${PYTHONPATH:-}"

start_daemon() {
  local name="$1"
  shift
  local log_file="$LOG_DIR/${name}.log"
  echo "Starting $name..."
  echo "  using PYTHON_BIN=$PYTHON_BIN" >>"$log_file"
  echo "  using PYTHONPATH=$PYTHONPATH" >>"$log_file"
  nohup env PYTHONPATH="$PYTHONPATH" "$PYTHON_BIN" "$@" >>"$log_file" 2>&1 &
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
    control_endpoint="${CONTROL_CHANNEL_ENDPOINT:-unix://storage/run/jsvv-control.sock}"
    if [[ "${control_endpoint}" == unix://* ]]; then
      socket_path="${control_endpoint#unix://}"
    else
      socket_path="${control_endpoint}"
    fi
    if [[ "${socket_path}" != /* ]]; then
      socket_path="${ROOT_DIR}/${socket_path}"
    fi
    mkdir -p "$(dirname "${socket_path}")"
    start_daemon "control_channel_worker" "$ROOT_DIR/python-client/daemons/control_channel_worker.py" --endpoint "${control_endpoint}"

    if [[ -n "${GSM_SERIAL_PORT:-}" ]]; then
      args=("$ROOT_DIR/daemons/gsm_listener.py"
        --port "${GSM_SERIAL_PORT}"
        --baudrate "${GSM_SERIAL_BAUDRATE:-115200}"
        --bytesize "${GSM_SERIAL_BYTESIZE:-8}"
        --parity "${GSM_SERIAL_PARITY:-N}"
        --stopbits "${GSM_SERIAL_STOPBITS:-1}"
        --timeout-serial "${GSM_SERIAL_TIMEOUT:-0.5}"
        --write-timeout "${GSM_SERIAL_WRITE_TIMEOUT:-1}"
        --poll "${GSM_POLL_INTERVAL:-0.2}"
        --graceful "${GSM_GRACEFUL_TIMEOUT:-5}"
        --signal-interval "${GSM_SIGNAL_INTERVAL:-30}"
        --answer-delay "${GSM_AUTO_ANSWER_DELAY_MS:-1000}"
        --max-ring "${GSM_MAX_RING_ATTEMPTS:-6}"
        --project-root "$ROOT_DIR"
        --artisan-bin "${ARTISAN_BIN:-php}"
        --artisan-path "${ARTISAN_PATH:-artisan}"
        --artisan-command "${GSM_ARTISAN_COMMAND:-gsm:test-send}"
        --token "${GSM_TOKEN:-}"
      )
      if [[ -n "${GSM_WEBHOOK:-}" ]]; then
        args+=(--webhook "${GSM_WEBHOOK}")
      fi
      if [[ -n "${GSM_WEBHOOK_TIMEOUT:-}" ]]; then
        args+=(--timeout "${GSM_WEBHOOK_TIMEOUT}")
      fi
      start_daemon "gsm_listener" "${args[@]}"
    else
      echo "Skipping gsm_listener (set GSM_SERIAL_PORT to enable)." | tee -a "$LOG_DIR/gsm_listener.log"
      rm -f "$LOG_DIR/gsm_listener.pid"
    fi

    if [[ -n "${CONTROL_TAB_SERIAL_PORT:-}" ]]; then
      args=("$ROOT_DIR/daemons/control_tab_listener.py"
        --port "${CONTROL_TAB_SERIAL_PORT}"
        --baudrate "${CONTROL_TAB_SERIAL_BAUDRATE:-115200}"
        --bytesize "${CONTROL_TAB_SERIAL_BYTESIZE:-8}"
        --parity "${CONTROL_TAB_SERIAL_PARITY:-N}"
        --stopbits "${CONTROL_TAB_SERIAL_STOPBITS:-1}"
        --timeout-serial "${CONTROL_TAB_SERIAL_TIMEOUT:-0.2}"
        --write-timeout "${CONTROL_TAB_SERIAL_WRITE_TIMEOUT:-1}"
        --poll "${CONTROL_TAB_POLL_INTERVAL:-0.05}"
        --graceful "${CONTROL_TAB_GRACEFUL_TIMEOUT:-5}"
        --retry-backoff "${CONTROL_TAB_RETRY_BACKOFF_MS:-250}"
        --project-root "$ROOT_DIR"
        --artisan-bin "${ARTISAN_BIN:-php}"
        --artisan-path "${ARTISAN_PATH:-artisan}"
        --artisan-command "${CTAB_ARTISAN_COMMAND:-ctab:test-send}"
        --token "${CONTROL_TAB_TOKEN:-}"
      )
      if [[ -n "${CONTROL_TAB_WEBHOOK:-}" ]]; then
        args+=(--webhook "${CONTROL_TAB_WEBHOOK}")
      fi
      if [[ -n "${CONTROL_TAB_TIMEOUT:-}" ]]; then
        args+=(--timeout "${CONTROL_TAB_TIMEOUT}")
      fi
      start_daemon "control_tab_listener" "${args[@]}"
    else
      echo "Skipping control_tab_listener (set CONTROL_TAB_SERIAL_PORT to enable)." | tee -a "$LOG_DIR/control_tab_listener.log"
      rm -f "$LOG_DIR/control_tab_listener.pid"
    fi

    if [[ -n "${JSVV_PORT:-}" ]]; then
      args=("$ROOT_DIR/daemons/jsvv_listener.py" --port "${JSVV_PORT}")
      if [[ -n "${JSVV_BAUDRATE:-}" ]]; then
        args+=(--baudrate "${JSVV_BAUDRATE}")
      fi
      if [[ -n "${JSVV_TIMEOUT_MS:-}" ]]; then
        args+=(--timeout-ms "${JSVV_TIMEOUT_MS}")
      fi
      start_daemon "jsvv_listener" "${args[@]}"
    else
      echo "Skipping jsvv_listener (set JSVV_PORT to enable)." | tee -a "$LOG_DIR/jsvv_listener.log"
      rm -f "$LOG_DIR/jsvv_listener.pid"
    fi

    if [[ "${ALARM_POLLER_ENABLED:-1}" == "1" ]]; then
      args=("$ROOT_DIR/daemons/alarm_poller.py"
        --port "${MODBUS_PORT:-/dev/ttyUSB0}"
        --baudrate "${MODBUS_BAUDRATE:-57600}"
        --parity "${MODBUS_PARITY:-N}"
        --stopbits "${MODBUS_STOPBITS:-1}"
        --bytesize "${MODBUS_BYTESIZE:-8}"
        --timeout-ms "${MODBUS_TIMEOUT_MS:-500}"
        --unit "${MODBUS_UNIT_ID:-1}"
        --interval "${ALARM_POLL_INTERVAL:-2}"
        --artisan-bin "${ARTISAN_BIN:-php}"
        --artisan-path "${ARTISAN_PATH:-artisan}"
        --artisan-command "${ALARM_ARTISAN_COMMAND:-alarm:poll}"
        --project-root "$ROOT_DIR"
      )
      start_daemon "alarm_poller" "${args[@]}"
    else
      echo "Skipping alarm_poller (set ALARM_POLLER_ENABLED=1 to enable)." | tee -a "$LOG_DIR/alarm_poller.log"
      rm -f "$LOG_DIR/alarm_poller.pid"
    fi

    gpio_button_enabled="${GPIO_BUTTON_ENABLED:-}"
    gpio_button_enabled="${gpio_button_enabled,,}"
    if [[ "$gpio_button_enabled" == "true" || "$gpio_button_enabled" == "1" ]]; then
      args=("$ROOT_DIR/python-client/daemons/gpio_button_listener.py")
      args+=(--chip "${GPIO_BUTTON_CHIP:-gpiochip2}")
      args+=(--line "${GPIO_BUTTON_LINE:-0}")
      args+=(--active-level "${GPIO_BUTTON_ACTIVE_LEVEL:-1}")
      args+=(--debounce-ms "${GPIO_BUTTON_DEBOUNCE_MS:-50}")
      if [[ -n "${GPIO_BUTTON_RELEASE_DEBOUNCE_MS:-}" ]]; then
        args+=(--release-debounce-ms "${GPIO_BUTTON_RELEASE_DEBOUNCE_MS}")
      fi
      args+=(--poll-interval "${GPIO_BUTTON_POLL_INTERVAL:-0.05}")
      if [[ -n "${GPIO_BUTTON_COOLDOWN_MS:-}" ]]; then
        args+=(--cooldown-ms "${GPIO_BUTTON_COOLDOWN_MS}")
      fi
      args+=(--button-id "${GPIO_BUTTON_ID:-1}")
      args+=(--screen "${GPIO_BUTTON_SCREEN:-0}")
      args+=(--panel "${GPIO_BUTTON_PANEL:-0}")
      args+=(--webhook "${GPIO_BUTTON_WEBHOOK:-${CONTROL_TAB_WEBHOOK:-http://127.0.0.1:8001/api/control-tab/events}}")
      if [[ -n "${GPIO_BUTTON_TOKEN:-}" ]]; then
        args+=(--token "${GPIO_BUTTON_TOKEN}")
      fi
      if [[ -n "${GPIO_BUTTON_TIMEOUT:-}" ]]; then
        args+=(--timeout "${GPIO_BUTTON_TIMEOUT}")
      fi
      if [[ -n "${GPIO_BUTTON_SESSION_PREFIX:-}" ]]; then
        args+=(--session-prefix "${GPIO_BUTTON_SESSION_PREFIX}")
      fi
      if [[ -n "${GPIO_BUTTON_BACKEND:-}" ]]; then
        args+=(--backend "${GPIO_BUTTON_BACKEND}")
      fi
      if [[ -n "${GPIO_BUTTON_CONSUMER:-}" ]]; then
        args+=(--consumer "${GPIO_BUTTON_CONSUMER}")
      fi
      if [[ -n "${GPIO_BUTTON_REQUIRE_RELEASE:-}" ]]; then
        case "${GPIO_BUTTON_REQUIRE_RELEASE,,}" in
          "0"|"false"|"no"|"off")
            args+=(--no-require-release)
            ;;
          "1"|"true"|"yes"|"on")
            args+=(--require-release)
            ;;
        esac
      fi
      if [[ -n "${GPIO_BUTTON_LOG_LEVEL:-}" ]]; then
        args+=(--log-level "${GPIO_BUTTON_LOG_LEVEL}")
      fi
      start_daemon "gpio_button_listener" "${args[@]}"
    else
      echo "Skipping gpio_button_listener (set GPIO_BUTTON_ENABLED=true to enable)." | tee -a "$LOG_DIR/gpio_button_listener.log"
      rm -f "$LOG_DIR/gpio_button_listener.pid"
    fi
    ;;
  stop)
    stop_daemon "control_channel_worker"
    stop_daemon "gsm_listener"
    stop_daemon "control_tab_listener"
    stop_daemon "jsvv_listener"
    stop_daemon "gpio_button_listener"
    stop_daemon "alarm_poller"
    ;;
  restart)
    "$0" stop
    sleep 1
    "$0" start
    ;;
  status)
    for name in control_channel_worker gsm_listener control_tab_listener jsvv_listener gpio_button_listener alarm_poller; do
      pid_file="$LOG_DIR/${name}.pid"
      if [[ ! -f "$pid_file" ]]; then
        case "$name" in
          gsm_listener)
            if [[ -z "${GSM_SERIAL_PORT:-}" ]]; then
              echo "$name skipped (GSM_SERIAL_PORT not set)"
              continue
            fi
            ;;
          control_tab_listener)
            if [[ -z "${CONTROL_TAB_SERIAL_PORT:-}" ]]; then
              echo "$name skipped (CONTROL_TAB_SERIAL_PORT not set)"
              continue
            fi
            ;;
          jsvv_listener)
            if [[ -z "${JSVV_PORT:-}" ]]; then
              echo "$name skipped (JSVV_PORT not set)"
              continue
            fi
            ;;
          gpio_button_listener)
            if [[ "${GPIO_BUTTON_ENABLED,,}" != "true" && "${GPIO_BUTTON_ENABLED}" != "1" ]]; then
              echo "$name skipped (GPIO_BUTTON_ENABLED not set to true)"
              continue
            fi
            ;;
          alarm_poller)
            if [[ "${ALARM_POLLER_ENABLED:-1}" != "1" ]]; then
              echo "$name skipped (ALARM_POLLER_ENABLED not enabled)"
              continue
            fi
            ;;
        esac
      fi
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

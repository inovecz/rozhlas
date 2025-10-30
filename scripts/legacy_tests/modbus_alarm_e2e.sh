#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

SOCAT_BIN="$(command -v socat || true)"
PHP_BIN="${PHP_BIN:-php}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
MONITOR_INTERVAL="${MODBUS_ALARM_MONITOR_INTERVAL:-2}"
MONITOR_TIMEOUT="${MODBUS_ALARM_MONITOR_TIMEOUT:-15}"

if [[ -z "$SOCAT_BIN" ]]; then
  echo "[ERROR] socat is required but not found in PATH." >&2
  exit 1
fi

ENV_FILE="${ROOT_DIR}/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "[ERROR] .env file not found at $ENV_FILE" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
LISTENER_LINK="${TMP_DIR}/modbus-listener"
SIMULATOR_LINK="${TMP_DIR}/modbus-simulator"
SOCAT_LOG="${TMP_DIR}/socat.log"
ENV_BACKUP="${TMP_DIR}/env.backup"
SERVER_LOG="${TMP_DIR}/modbus-server.log"
MONITOR_LOG="${TMP_DIR}/alarms-monitor.log"
touch "$MONITOR_LOG"
TIMEOUT_BIN="$(command -v timeout || true)"
TIMEOUT_ARGS=()
if [[ -n "$TIMEOUT_BIN" ]]; then
  TIMEOUT_ARGS=("$TIMEOUT_BIN" "--foreground" "${MONITOR_TIMEOUT}s")
fi

cleanup() {
  local status=$?
  if [[ -n "${MONITOR_PID:-}" ]] && kill -0 "$MONITOR_PID" >/dev/null 2>&1; then
    kill "$MONITOR_PID" 2>/dev/null || true
    wait "$MONITOR_PID" 2>/dev/null || true
  fi
  if [[ -n "${SERVER_PID:-}" ]] && kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  if [[ -n "${SOCAT_PID:-}" ]] && kill -0 "$SOCAT_PID" >/dev/null 2>&1; then
    kill "$SOCAT_PID" 2>/dev/null || true
    wait "$SOCAT_PID" 2>/dev/null || true
  fi
  if [[ -f "$ENV_BACKUP" ]]; then
    mv "$ENV_BACKUP" "$ENV_FILE"
    (cd "$ROOT_DIR" && $PHP_BIN artisan config:clear >/dev/null 2>&1 || true)
  fi
  rm -rf "$TMP_DIR"
  exit $status
}
trap cleanup EXIT INT TERM

cp "$ENV_FILE" "$ENV_BACKUP"

echo "[1/7] Creating paired PTY devices via socat…"
"$SOCAT_BIN" -d -d "PTY,raw,echo=0,link=${LISTENER_LINK}" "PTY,raw,echo=0,link=${SIMULATOR_LINK}" >"$SOCAT_LOG" 2>&1 &
SOCAT_PID=$!

for _ in {1..10}; do
  if [[ -e "$LISTENER_LINK" && -e "$SIMULATOR_LINK" ]]; then
    break
  fi
  sleep 0.5
done

if [[ ! -e "$LISTENER_LINK" || ! -e "$SIMULATOR_LINK" ]]; then
  echo "[ERROR] Failed to create PTY pair. See $SOCAT_LOG" >&2
  exit 1
fi

echo "  • Listener port:  $LISTENER_LINK"
echo "  • Simulator port: $SIMULATOR_LINK"
REAL_PORT="$(readlink -f "$LISTENER_LINK" 2>/dev/null || echo unknown)"
echo "    (listener resolves to: $REAL_PORT)"

echo "[2/7] Updating .env with temporary Modbus port…"
export MODBUS_ALARM_ENV_FILE="$ENV_FILE"
export MODBUS_ALARM_LISTENER_LINK="$LISTENER_LINK"
"$PYTHON_BIN" - <<'PY'
import os
import pathlib
import re

env_path = pathlib.Path(os.environ["MODBUS_ALARM_ENV_FILE"])
text = env_path.read_text()
listener_link = os.environ["MODBUS_ALARM_LISTENER_LINK"]

settings = {
    "MODBUS_PORT": listener_link,
    "MODBUS_METHOD": "rtu",
    "MODBUS_BAUDRATE": "9600",
    "MODBUS_PARITY": "N",
    "MODBUS_STOPBITS": "1",
    "MODBUS_BYTESIZE": "8",
    "MODBUS_TIMEOUT": "1.0",
    "MODBUS_UNIT_ID": "1",
}

for key, value in settings.items():
    pattern = re.compile(rf"^{key}=.*$", re.MULTILINE)
    replacement = f"{key}={value}"
    if pattern.search(text):
        text = pattern.sub(replacement, text)
    else:
        if not text.endswith("\n"):
            text += "\n"
        text += replacement + "\n"

env_path.write_text(text)
PY

echo "[3/7] Reloading Laravel configuration…"
(cd "$ROOT_DIR" && $PHP_BIN artisan config:clear >/dev/null)
(cd "$ROOT_DIR" && $PHP_BIN artisan tinker --execute "dump(config('modbus.port'));") >>"$TMP_DIR/config-check.log" 2>&1 || true

echo "[4/7] Starting Modbus alarm simulator…"
export MODBUS_ALARM_SIM_PORT="$SIMULATOR_LINK"
export MODBUS_ALARM_SIM_BAUD="9600"
export MODBUS_ALARM_SIM_PARITY="N"
export MODBUS_ALARM_SIM_STOPBITS="1"
export MODBUS_ALARM_SIM_BYTESIZE="8"
export MODBUS_ALARM_SIM_TIMEOUT="0.1"

"$PYTHON_BIN" - <<'PY' >>"$SERVER_LOG" 2>&1 &
import os
import sys
import time
import threading

try:
    import serial  # type: ignore
except Exception as exc:  # pragma: no cover
    sys.stderr.write(f"[ERROR] pyserial is required: {exc}\n")
    raise SystemExit(1) from exc

PORT = os.environ["MODBUS_ALARM_SIM_PORT"]
BAUD = int(os.environ["MODBUS_ALARM_SIM_BAUD"])
BYTESIZE = int(os.environ["MODBUS_ALARM_SIM_BYTESIZE"])
PARITY = os.environ["MODBUS_ALARM_SIM_PARITY"]
STOPBITS = int(os.environ["MODBUS_ALARM_SIM_STOPBITS"])
TIMEOUT = float(os.environ["MODBUS_ALARM_SIM_TIMEOUT"])

UNIT_ID = 1
ALARM_BASE = 0x3000

registers = {ALARM_BASE + idx: value for idx, value in enumerate([
    0x0123,  # nest address
    0x0002,  # repeat count
    0x0004,  # payload words (battery fault code, example)
    0x0000,
    0x0000,
    0x0000,
    0x0000,
    0x0000,
    0x0000,
    0x0000,
])}

def crc16(data: bytes) -> int:
    crc = 0xFFFF
    for byte in data:
        crc ^= byte
        for _ in range(8):
            if crc & 0x0001:
                crc = (crc >> 1) ^ 0xA001
            else:
                crc >>= 1
    return crc & 0xFFFF

def read_exact(ser: "serial.Serial", size: int, timeout: float = 0.5) -> bytes:
    end_time = time.time() + timeout
    buf = bytearray()
    while len(buf) < size and time.time() < end_time:
        chunk = ser.read(size - len(buf))
        if chunk:
            buf.extend(chunk)
    return bytes(buf)

def handle_request(req: bytes) -> bytes | None:
    if len(req) < 8:
        return None
    slave, function = req[0], req[1]
    if slave != UNIT_ID or function != 0x03:
        return None
    address = (req[2] << 8) | req[3]
    quantity = (req[4] << 8) | req[5]
    payload = bytearray([slave, function, quantity * 2])
    values: list[int] = []
    for offset in range(quantity):
        reg_address = address + offset
        value = registers.get(reg_address, 0)
        values.append(value)
        payload.append((value >> 8) & 0xFF)
        payload.append(value & 0xFF)
    crc = crc16(payload)
    payload.append(crc & 0xFF)
    payload.append((crc >> 8) & 0xFF)
    if values and any(v != 0 for v in values):
        for offset in range(quantity):
            registers[address + offset] = 0
    return bytes(payload)

def serve() -> None:
    with serial.Serial(
        port=PORT,
        baudrate=BAUD,
        bytesize=BYTESIZE,
        parity=PARITY,
        stopbits=STOPBITS,
        timeout=TIMEOUT,
    ) as ser:
        while True:
            request = read_exact(ser, 8, timeout=1.0)
            if not request:
                continue
            response = handle_request(request)
            if response:
                ser.write(response)

thread = threading.Thread(target=serve, daemon=True)
thread.start()

try:
    while thread.is_alive():
        time.sleep(0.5)
except KeyboardInterrupt:
    pass
PY
SERVER_PID=$!
sleep 1

if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
  echo "[ERROR] Modbus simulator failed to start. See $SERVER_LOG" >&2
  exit 1
fi

echo "[5/7] Running alarms:monitor (interval=${MONITOR_INTERVAL}s)…"
(
  cd "$ROOT_DIR" || exit 1
  export MODBUS_PORT="$LISTENER_LINK"
  export MODBUS_BAUDRATE=9600
  export MODBUS_PARITY=N
  export MODBUS_STOPBITS=1
  export MODBUS_BYTESIZE=8
  export MODBUS_TIMEOUT=1.0
  export MODBUS_UNIT_ID=1
  if [[ "${#TIMEOUT_ARGS[@]}" -gt 0 ]]; then
    "${TIMEOUT_ARGS[@]}" "$PHP_BIN" artisan alarms:monitor --interval="$MONITOR_INTERVAL" >>"$MONITOR_LOG" 2>&1
  else
    "$PHP_BIN" artisan alarms:monitor --interval="$MONITOR_INTERVAL" >>"$MONITOR_LOG" 2>&1
  fi
) &
MONITOR_PID=$!

SECONDS_WAITED=0
while [[ $SECONDS_WAITED -lt "$MONITOR_TIMEOUT" ]]; do
  if ! kill -0 "$MONITOR_PID" >/dev/null 2>&1; then
    break
  fi
  if grep -q "Alarm z hnízda" "$MONITOR_LOG" || grep -q "Čtení alarm bufferu" "$MONITOR_LOG"; then
    break
  fi
  sleep 1
  SECONDS_WAITED=$((SECONDS_WAITED + 1))
done

wait "$MONITOR_PID" 2>/dev/null || true

echo "[6/7] Monitor output:"
cat "$MONITOR_LOG"
echo
if [[ -f "$TMP_DIR/config-check.log" ]]; then
  echo "[debug] config('modbus.port') during run:"
  cat "$TMP_DIR/config-check.log"
  echo
fi
echo "[7/7] Simulator log:"
cat "$SERVER_LOG"

echo
echo "Kontrolní body:"
echo "  • Ověřte odeslané SMS (GoSMS log nebo zařízení)."
echo "  • Záznam o alarmu by měl být viditelný na /log (typ 'jsvv')."
echo "  • Alarm buffer lze zkontrolovat příkazem: python-client/modbus_control.py read-alarms --port $LISTENER_LINK"

exit 0

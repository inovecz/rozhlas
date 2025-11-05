#!/usr/bin/env bash

set -euo pipefail

PORT="${PORT:-/dev/ttyAMA3}"
BAUDRATE="${BAUDRATE:-57600}"
UNIT_ID="${UNIT_ID:-55}"
REGISTER="${REGISTER:-0x4035}"
PINCTRL_BIN="${PINCTRL_BIN:-pinctrl}"
RS485_PIN="${RS485_PIN:-16}"
READ_BYTES="${READ_BYTES:-7}"
TIMEOUT_SEC="${TIMEOUT_SEC:-2}"

REQUEST_HEX="37 03 40 35 00 01 84 52"

cleanup() {
  "${PINCTRL_BIN}" "${RS485_PIN}" op dl >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [[ ! -e "${PORT}" ]]; then
  echo "[modbus] Serial port ${PORT} not found" >&2
  exit 1
fi

echo "[modbus] Configuring serial port ${PORT} (${BAUDRATE} baud)"
stty -F "${PORT}" "${BAUDRATE}" cs8 -cstopb -parenb -ixon -ixoff -crtscts raw -echo >/dev/null

echo "[modbus] Clearing RX buffer"
dd if="${PORT}" of=/dev/null bs=256 count=1 status=none

echo "[modbus] Driving RS485 transceiver to receive (low)"
"${PINCTRL_BIN}" "${RS485_PIN}" op dl

echo "[modbus] Driving RS485 transceiver to transmit (high)"
"${PINCTRL_BIN}" "${RS485_PIN}" op dh

printf "[modbus] Sending frame:"
for byte in ${REQUEST_HEX}; do
  printf " %s" "${byte}"
done
printf " -> %s\n" "${PORT}"

{
  IFS=' '
  for byte in ${REQUEST_HEX}; do
    printf "\\x%s" "${byte}"
  done
} >"${PORT}"

printf "[modbus] Frame sent, switching back to receive\n"
"${PINCTRL_BIN}" "${RS485_PIN}" op dl

echo "[modbus] Waiting for response (${READ_BYTES} bytes, timeout ${TIMEOUT_SEC}s)"
RESPONSE=$(timeout "${TIMEOUT_SEC}" dd if="${PORT}" bs=1 count="${READ_BYTES}" status=none | hexdump -v -e '/1 "%02X "')

if [[ -z "${RESPONSE}" ]]; then
  echo "[modbus] No data received within timeout" >&2
  exit 2
fi

echo "[modbus] Received: ${RESPONSE}"

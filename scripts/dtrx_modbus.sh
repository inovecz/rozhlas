#!/usr/bin/env bash

# Self-contained helper for Modbus RTU communication with the DTRX receiver.
#  - Serial port:     /dev/ttyAMA3 (default)
#  - Baud rate:       57600
#  - Slave address:   55
#  - Direction GPIO:  pinctrl 16 (dl = RX, dh = TX)
#
# The register addresses referenced here come from the DTRX register
# documentation (see docs/requirements_docs/DTRX PŘIJÍMAČ Popis registrů v6.pdf),
# and match the definitions used in python-client/src/modbus_audio/constants.py.
#
# Usage examples:
#   ./scripts/dtrx_modbus.sh list
#   ./scripts/dtrx_modbus.sh read status
#   ./scripts/dtrx_modbus.sh read 0x4024          # Frequency
#   ./scripts/dtrx_modbus.sh write rx_control 1   # Start playback
#   ./scripts/dtrx_modbus.sh write rx_control 0   # Stop playback
#   ./scripts/dtrx_modbus.sh start-stream --update-route

set -uo pipefail

PORT="${MODBUS_PORT:-/dev/ttyAMA3}"
BAUD="${MODBUS_BAUD:-57600}"
SLAVE_ID="${MODBUS_SLAVE_ID:-55}"
READ_TIMEOUT="${MODBUS_TIMEOUT:-1}"
PINCTRL_BIN="${PINCTRL_BIN:-pinctrl}"
PINCTRL_PIN="${PINCTRL_PIN:-16}"
PINCTRL_WRITE_DELAY="${PINCTRL_WRITE_DELAY:-0.005}"
PINCTRL_READ_DELAY="${PINCTRL_READ_DELAY:-0.005}"
POST_WRITE_DELAY="${POST_WRITE_DELAY:-0.010}"
PINCTRL_ACTIVE_HIGH="${PINCTRL_ACTIVE_HIGH:-${MODBUS_RS485_GPIO_ACTIVE_HIGH:-false}}"
PARITY="${MODBUS_PARITY:-N}"

SERIAL_FD=-1
declare -A REGISTER_MAP=(
  [num_addr_ram]=0x0000
  [addr0_ram]=0x0001
  [rx_control]=0x4035
  [tx_control]=0x4035
  [status]=0x4036
  [error]=0x4037
  [frequency]=0x4024
  [ogg_bitrate]=0x403F
  [serial_number]=0x4000
  [rf_dest_zone0]=0x4030
  [alarm_address]=0x3000
  [hardware_version]=0xFFF4
  [firmware_version]=0xFFF5
)

DEFAULT_ROUTE=(1 116 225)
DEFAULT_ZONES=(22)
MAX_ROUTE_ENTRIES=5
MAX_ZONE_ENTRIES=5
TX_CONTROL_PRIMARY=0x4035
TX_CONTROL_LEGACY=0x5035

fail() {
  echo "Error: $*" >&2
  exit 1
}

usage() {
  cat <<'EOF'
Usage:
  dtrx_modbus.sh [global options] <command> [args]

Global options:
  --port PATH        Serial device (default /dev/ttyAMA3)
  --baud RATE        Line speed in baud (default 57600)
  --slave ID         Modbus slave/unit id in decimal or 0x.. (default 55)
  --timeout SEC      Read timeout in seconds (default 1)
  --parity MODE      Serial parity: N, E, or O (default N)
  --pinctrl-active-high  Drive TX as high (default derives from MODBUS_RS485_GPIO_ACTIVE_HIGH)
  --pinctrl-active-low   Drive TX as low (override active-high)
  --pin ID           pinctrl GPIO line (default 16)
  --help             Show this help message

Commands:
  list
      Print register name shortcuts sourced from the DTRX documentation.

  start-stream [options]
      Configure destination zones (optionally route) and set TxControl=2.
      Options:
          --route <addr>...   Override RF hop route list (defaults to 1,116,225)
          --zones <zone>...   Override destination zones (defaults to 22)
          --update-route      Write the route registers before starting

  read <register|name> [quantity]
      Read one or more holding registers. Quantity defaults to 1.

  write <register|name> <value>
      Write a single holding register using Modbus function 0x06.

Examples:
  ./scripts/dtrx_modbus.sh read status
  ./scripts/dtrx_modbus.sh read 0x4024 2
  ./scripts/dtrx_modbus.sh write rx_control 1
  ./scripts/dtrx_modbus.sh --port /dev/ttyUSB0 --slave 0x37 read error
EOF
}

cleanup() {
  if [ "$SERIAL_FD" -ge 0 ]; then
    # Return the bus to receive mode before closing.
    pinctrl_apply_level "rx" 1 >/dev/null 2>&1 || true
    exec {SERIAL_FD}>&-
  fi
}
trap cleanup EXIT

require_command() {
  local binary="$1"
  command -v "$binary" >/dev/null 2>&1 || fail "Required command '$binary' is missing."
}

parse_number() {
  local value="$1"
  if [[ -z "$value" ]]; then
    fail "Missing numeric value."
  fi
  if [[ "$value" =~ ^0[xX][0-9a-fA-F]+$ ]]; then
    printf "%d" "$((16#${value:2}))"
  elif [[ "$value" =~ ^[0-9]+$ ]]; then
    printf "%d" "$value"
  else
    fail "Invalid numeric literal: $value"
  fi
}

resolve_register() {
  local ident="$1"
  if [[ -z "$ident" ]]; then
    fail "Register identifier is required."
  fi
  if [[ -n "${REGISTER_MAP[$ident]+_}" ]]; then
    printf "%s" "${REGISTER_MAP[$ident]}"
    return 0
  fi
  printf "%s" "$ident"
}

to_bool() {
  local value="${1:-}"
  if [[ -z "$value" ]]; then
    printf "0"
    return
  fi
  case "${value,,}" in
    1|true|yes|on) printf "1" ;;
    *) printf "0" ;;
  esac
}

PINCTRL_ACTIVE_HIGH_FLAG=$(to_bool "$PINCTRL_ACTIVE_HIGH")

get_register_address() {
  local ident="$1"
  local resolved
  resolved=$(resolve_register "$ident")
  parse_number "$resolved"
}

format_value_list() {
  if [ "$#" -eq 0 ]; then
    printf "<empty>"
    return
  fi

  local idx=0
  local dec
  for value in "$@"; do
    dec=$(parse_number "$value")
    if [ "$idx" -gt 0 ]; then
      printf ", "
    fi
    printf "0x%04X(%d)" "$dec" "$dec"
    idx=$((idx + 1))
  done
}

crc16_modbus() {
  local hex="$1"
  local crc=65535
  local byte
  while [ -n "$hex" ]; do
    byte=$((16#${hex:0:2}))
    crc=$((crc ^ byte))
    for _ in {0..7}; do
      if ((crc & 1)); then
        crc=$(((crc >> 1) ^ 0xA001))
      else
        crc=$((crc >> 1))
      fi
    done
    crc=$((crc & 0xFFFF))
    hex=${hex:2}
  done
  printf "%02X%02X" $((crc & 0xFF)) $(((crc >> 8) & 0xFF))
}

pinctrl_apply_level() {
  local mode="$1"
  local quiet="${2:-0}"
  local desired_high
  case "$mode" in
    tx)
      desired_high=$PINCTRL_ACTIVE_HIGH_FLAG
      ;;
    rx)
      desired_high=$((1 - PINCTRL_ACTIVE_HIGH_FLAG))
      ;;
    *)
      [ "$quiet" -eq 1 ] && return 1
      fail "Unknown pinctrl mode '$mode' (expected tx/rx)."
      ;;
  esac

  local drive
  if [ "$desired_high" -eq 1 ]; then
    drive="dh"
  else
    drive="dl"
  fi

  if ! "$PINCTRL_BIN" "$PINCTRL_PIN" op "$drive" >/dev/null; then
    [ "$quiet" -eq 1 ] && return 1
    fail "Failed to switch RS485 to $mode ($drive)."
  fi
}

set_write_mode() {
  pinctrl_apply_level "tx"
  sleep "$PINCTRL_WRITE_DELAY"
}

set_read_mode() {
  pinctrl_apply_level "rx"
  sleep "$PINCTRL_READ_DELAY"
}

flush_serial_input() {
  if [ "$SERIAL_FD" -lt 0 ]; then
    return
  fi
  set_read_mode
  local fd_path="/proc/$$/fd/$SERIAL_FD"
  timeout 0.05 dd if="$fd_path" bs=256 count=1 of=/dev/null 2>/dev/null || true
}

open_serial() {
  require_command "$PINCTRL_BIN"
  require_command stty
  require_command timeout
  require_command dd
  require_command hexdump

  local baud_dec
  baud_dec=$(parse_number "$BAUD")
  [ "$baud_dec" -gt 0 ] || fail "Invalid baud rate: $BAUD"

  local parity_flags=()
  case "$PARITY" in
    N)
      parity_flags=(-parenb)
      ;;
    E)
      parity_flags=(parenb -parodd)
      ;;
    O)
      parity_flags=(parenb parodd)
      ;;
    *)
      fail "Unsupported parity setting '$PARITY' (expected N, E, or O)."
      ;;
  esac

  stty -F "$PORT" "$baud_dec" cs8 -cstopb "${parity_flags[@]}" -ixon -ixoff -crtscts raw || fail "Failed to configure $PORT"
  exec {SERIAL_FD}<>"$PORT" || fail "Cannot open $PORT"
  set_read_mode
  flush_serial_input
}

hex_to_bytes_printf_arg() {
  local hex="$1"
  local out=""
  while [ -n "$hex" ]; do
    out+="\\x${hex:0:2}"
    hex=${hex:2}
  done
  printf "%s" "$out"
}

send_frame() {
  local frame_hex="$1"
  local printf_arg
  printf_arg=$(hex_to_bytes_printf_arg "$frame_hex")
  printf "%b" "$printf_arg" >&$SERIAL_FD
  sleep "$POST_WRITE_DELAY"
}

read_hex_bytes() {
  local bytes="$1"
  local fd_path="/proc/$$/fd/$SERIAL_FD"
  local output
  if ! output=$(timeout "$READ_TIMEOUT" dd if="$fd_path" bs=1 count="$bytes" 2>/dev/null | hexdump -v -e '/1 "%02X"'); then
    return 1
  fi
  printf "%s" "$output"
}

assert_crc_ok() {
  local frame_hex="$1"
  local received_crc="${frame_hex: -4}"
  local body="${frame_hex:0:${#frame_hex}-4}"
  local computed_crc
  computed_crc=$(crc16_modbus "$body")
  if [[ "${received_crc^^}" != "${computed_crc^^}" ]]; then
    fail "CRC mismatch (expected $computed_crc, received $received_crc)"
  fi
}

list_registers() {
  echo "Documented register shortcuts:"
  printf "%s\n" "${!REGISTER_MAP[@]}" | sort | while read -r name; do
    local reg_value_dec
    reg_value_dec=$(parse_number "${REGISTER_MAP[$name]}")
    printf "  %-18s 0x%04X (%d)\n" "$name" "$reg_value_dec" "$reg_value_dec"
  done
}

modbus_read() {
  local register_ident="$1"
  local quantity_raw="${2:-1}"

  local register_hex
  register_hex=$(resolve_register "$register_ident")

  local start_dec quantity_dec
  start_dec=$(parse_number "$register_hex")
  quantity_dec=$(parse_number "$quantity_raw")

  (( start_dec >= 0 && start_dec <= 0xFFFF )) || fail "Register out of range: $register_ident"
  (( quantity_dec >= 1 && quantity_dec <= 125 )) || fail "Quantity must be between 1 and 125."

  local request_hex
  printf -v request_hex "%02X%02X%04X%04X" "$SLAVE_ID" 3 "$start_dec" "$quantity_dec"
  local crc_hex
  crc_hex=$(crc16_modbus "$request_hex")
  local frame_hex="${request_hex}${crc_hex}"

  flush_serial_input
  set_write_mode
  send_frame "$frame_hex"
  set_read_mode

  local expected_bytes=$((5 + (quantity_dec * 2)))
  local response_hex
  response_hex=$(read_hex_bytes "$expected_bytes") || fail "Timed out waiting for response."

  if [ "${#response_hex}" -ne $((expected_bytes * 2)) ]; then
    fail "Incomplete response (expected $expected_bytes bytes, got $(( ${#response_hex} / 2 )))"
  fi

  assert_crc_ok "$response_hex"

  local slave_rx=$((16#${response_hex:0:2}))
  local func_code=$((16#${response_hex:2:2}))
  if [ "$slave_rx" -ne "$SLAVE_ID" ]; then
    fail "Response from unexpected slave id: $slave_rx"
  fi
  if (( func_code == (0x80 | 0x03) )); then
    local exception_code=$((16#${response_hex:4:2}))
    fail "Device reported Modbus exception 0x$(printf '%02X' "$exception_code")"
  fi
  if [ "$func_code" -ne 3 ]; then
    fail "Unexpected function code in response: 0x$(printf '%02X' "$func_code")"
  fi

  local byte_count=$((16#${response_hex:4:2}))
  if [ "$byte_count" -ne $((quantity_dec * 2)) ]; then
    fail "Unexpected byte count $byte_count (expected $((quantity_dec * 2)))"
  fi

  local payload="${response_hex:6:${byte_count*2}}"
  echo "Read $quantity_dec register(s) starting at 0x$(printf '%04X' "$start_dec"):"

  local idx=0
  while [ "$idx" -lt "$quantity_dec" ]; do
    local word_hex=${payload:$((idx * 4)):4}
    local value_dec=$((16#$word_hex))
    printf "  +0x%04X : 0x%04X (%d)\n" $((start_dec + idx)) $value_dec $value_dec
    idx=$((idx + 1))
  done
}

modbus_write() {
  local register_ident="$1"
  local value_raw="$2"
  local quiet_flag=0
  local extra="${3-}"

  if [ -n "$extra" ]; then
    if [ "$extra" = "--quiet" ]; then
      quiet_flag=1
    else
      fail "modbus_write: unknown option '$extra'"
    fi
  fi

  local register_hex
  register_hex=$(resolve_register "$register_ident")

  local reg_dec value_dec
  reg_dec=$(parse_number "$register_hex")
  value_dec=$(parse_number "$value_raw")

  (( reg_dec >= 0 && reg_dec <= 0xFFFF )) || fail "Register out of range: $register_ident"
  (( value_dec >= 0 && value_dec <= 0xFFFF )) || fail "Value must fit into 16 bits."

  local request_hex
  printf -v request_hex "%02X%02X%04X%04X" "$SLAVE_ID" 6 "$reg_dec" "$value_dec"
  local crc_hex
  crc_hex=$(crc16_modbus "$request_hex")
  local frame_hex="${request_hex}${crc_hex}"

  flush_serial_input
  set_write_mode
  send_frame "$frame_hex"
  set_read_mode

  local expected_bytes=8
  local response_hex
  response_hex=$(read_hex_bytes "$expected_bytes") || fail "Timed out waiting for write confirmation."

  if [ "${#response_hex}" -ne $((expected_bytes * 2)) ]; then
    fail "Incomplete response (expected $expected_bytes bytes, got $(( ${#response_hex} / 2 )))"
  fi

  assert_crc_ok "$response_hex"

  local slave_rx=$((16#${response_hex:0:2}))
  local func_code=$((16#${response_hex:2:2}))
  if [ "$slave_rx" -ne "$SLAVE_ID" ]; then
    fail "Response from unexpected slave id: $slave_rx"
  fi
  if (( func_code == (0x80 | 0x06) )); then
    local exception_code=$((16#${response_hex:4:2}))
    fail "Device reported Modbus exception 0x$(printf '%02X' "$exception_code")"
  fi
  if [ "$func_code" -ne 6 ]; then
    fail "Unexpected function code in response: 0x$(printf '%02X' "$func_code")"
  fi

  local echoed_addr=$((16#${response_hex:4:4}))
  local echoed_value=$((16#${response_hex:8:4}))
  if [ "$echoed_addr" -ne "$reg_dec" ] || [ "$echoed_value" -ne "$value_dec" ]; then
    fail "Write acknowledgement mismatch (addr 0x$(printf '%04X' "$echoed_addr"), value 0x$(printf '%04X' "$echoed_value"))"
  fi

  if [ "$quiet_flag" -eq 0 ]; then
    printf "Wrote 0x%04X (%d) to register 0x%04X.\n" $value_dec $value_dec $reg_dec
  fi
}

modbus_write_multiple() {
  if [ "$#" -lt 2 ]; then
    fail "modbus_write_multiple: requires a register and at least one value."
  fi

  local register_ident="$1"
  shift

  local quiet_flag=0
  if [ "$#" -gt 0 ] && [ "${!#}" = "--quiet" ]; then
    quiet_flag=1
    set -- "${@:1:$(($# - 1))}"
  fi

  local values=("$@")
  if [ "${#values[@]}" -eq 0 ]; then
    fail "modbus_write_multiple: no values provided."
  fi
  if [ "${#values[@]}" -gt 123 ]; then
    fail "modbus_write_multiple: at most 123 registers can be written at once."
  fi

  local register_hex
  register_hex=$(resolve_register "$register_ident")

  local start_dec
  start_dec=$(parse_number "$register_hex")
  (( start_dec >= 0 && start_dec <= 0xFFFF )) || fail "Register out of range: $register_ident"

  local quantity=${#values[@]}
  local data_hex=""
  local value_dec
  for raw in "${values[@]}"; do
    value_dec=$(parse_number "$raw")
    (( value_dec >= 0 && value_dec <= 0xFFFF )) || fail "Value '$raw' does not fit into 16 bits."
    printf -v data_hex "%s%04X" "$data_hex" "$value_dec"
  done

  local byte_count=$((quantity * 2))
  local request_hex
  printf -v request_hex "%02X%02X%04X%04X%02X%s" "$SLAVE_ID" 16 "$start_dec" "$quantity" "$byte_count" "$data_hex"
  local crc_hex
  crc_hex=$(crc16_modbus "$request_hex")
  local frame_hex="${request_hex}${crc_hex}"

  flush_serial_input
  set_write_mode
  send_frame "$frame_hex"
  set_read_mode

  local expected_bytes=8
  local response_hex
  response_hex=$(read_hex_bytes "$expected_bytes") || fail "Timed out waiting for write confirmation."

  if [ "${#response_hex}" -ne $((expected_bytes * 2)) ]; then
    fail "Incomplete response (expected $expected_bytes bytes, got $(( ${#response_hex} / 2 )))"
  fi

  assert_crc_ok "$response_hex"

  local slave_rx=$((16#${response_hex:0:2}))
  local func_code=$((16#${response_hex:2:2}))
  if [ "$slave_rx" -ne "$SLAVE_ID" ]; then
    fail "Response from unexpected slave id: $slave_rx"
  fi
  if (( func_code == (0x80 | 0x10) )); then
    local exception_code=$((16#${response_hex:4:2}))
    fail "Device reported Modbus exception 0x$(printf '%02X' "$exception_code")"
  fi
  if [ "$func_code" -ne 16 ]; then
    fail "Unexpected function code in response: 0x$(printf '%02X' "$func_code")"
  fi

  local echoed_addr=$((16#${response_hex:4:4}))
  local echoed_quantity=$((16#${response_hex:8:4}))
  if [ "$echoed_addr" -ne "$start_dec" ] || [ "$echoed_quantity" -ne "$quantity" ]; then
    fail "Write acknowledgement mismatch (addr 0x$(printf '%04X' "$echoed_addr"), quantity $echoed_quantity)"
  fi

  if [ "$quiet_flag" -eq 0 ]; then
    printf "Wrote %d registers starting at 0x%04X.\n" "$quantity" "$start_dec"
  fi
}

configure_route_registers() {
  local -a route=("$@")
  if [ "${#route[@]}" -gt "$MAX_ROUTE_ENTRIES" ]; then
    fail "Route supports at most $MAX_ROUTE_ENTRIES hops; received ${#route[@]}."
  fi

  local count=${#route[@]}
  local -a padded=("${route[@]}")
  while [ "${#padded[@]}" -lt "$MAX_ROUTE_ENTRIES" ]; do
    padded+=("0")
  done

  modbus_write num_addr_ram "$count" --quiet
  if [ "$MAX_ROUTE_ENTRIES" -gt 0 ]; then
    modbus_write_multiple addr0_ram "${padded[@]}" --quiet
  fi
}

set_destination_zones_registers() {
  local -a zones=("$@")
  if [ "${#zones[@]}" -gt "$MAX_ZONE_ENTRIES" ]; then
    fail "At most $MAX_ZONE_ENTRIES destination zones are supported; received ${#zones[@]}."
  fi

  local -a padded=("${zones[@]}")
  while [ "${#padded[@]}" -lt "$MAX_ZONE_ENTRIES" ]; do
    padded+=("0")
  done

  modbus_write_multiple rf_dest_zone0 "${padded[@]}" --quiet
}

write_tx_control_value() {
  local value="$1"
  modbus_write tx_control "$value" --quiet
}

start_stream_command() {
  local -a args=("$@")
  local update_route=0
  local route_specified=0
  local zones_specified=0
  local -a route_values=()
  local -a zone_values=()

  while [ "${#args[@]}" -gt 0 ]; do
    local token="${args[0]}"
    case "$token" in
      --route)
        route_specified=1
        args=("${args[@]:1}")
        while [ "${#args[@]}" -gt 0 ] && [[ ! "${args[0]}" =~ ^-- ]]; do
          local parsed
          parsed=$(parse_number "${args[0]}")
          route_values+=("$parsed")
          args=("${args[@]:1}")
        done
        ;;
      --zones)
        zones_specified=1
        args=("${args[@]:1}")
        while [ "${#args[@]}" -gt 0 ] && [[ ! "${args[0]}" =~ ^-- ]]; do
          local parsed
          parsed=$(parse_number "${args[0]}")
          zone_values+=("$parsed")
          args=("${args[@]:1}")
        done
        ;;
      --update-route)
        update_route=1
        args=("${args[@]:1}")
        ;;
      --help|-h)
        usage
        exit 0
        ;;
      *)
        fail "start-stream: unknown option '$token'"
        ;;
    esac
  done

  local -a applied_route
  if [ "$route_specified" -eq 1 ]; then
    applied_route=("${route_values[@]}")
  else
    applied_route=("${DEFAULT_ROUTE[@]}")
  fi

  local -a applied_zones
  if [ "$zones_specified" -eq 1 ]; then
    applied_zones=("${zone_values[@]}")
  else
    applied_zones=("${DEFAULT_ZONES[@]}")
  fi

  open_serial

  if [ "$update_route" -eq 1 ]; then
    configure_route_registers "${applied_route[@]}"
  fi

  set_destination_zones_registers "${applied_zones[@]}"
  write_tx_control_value 2

  echo "Start stream command sent:"
  echo -n "  route : "
  format_value_list "${applied_route[@]}"
  if [ "$update_route" -eq 1 ]; then
    echo " (registers updated)"
  else
    echo " (registers left unchanged; use --update-route to write)"
  fi
  echo -n "  zones : "
  format_value_list "${applied_zones[@]}"
  echo
  printf "  TxControl <- 0x0002 (%d)\n" 2
}

main() {
  local command=""
  local -a cmd_args=()

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --port)
        [ "$#" -ge 2 ] || fail "--port requires a value."
        PORT="$2"
        shift 2
        ;;
      --baud)
        [ "$#" -ge 2 ] || fail "--baud requires a value."
        BAUD="$2"
        shift 2
        ;;
      --slave|-u)
        [ "$#" -ge 2 ] || fail "--slave requires a value."
        SLAVE_ID=$(parse_number "$2")
        shift 2
        ;;
      --timeout)
        [ "$#" -ge 2 ] || fail "--timeout requires a value."
        READ_TIMEOUT="$2"
        shift 2
        ;;
      --parity)
        [ "$#" -ge 2 ] || fail "--parity requires a value (N/E/O)."
        PARITY="${2^^}"
        shift 2
        ;;
      --pinctrl-active-high)
        PINCTRL_ACTIVE_HIGH=true
        shift
        ;;
      --pinctrl-active-low)
        PINCTRL_ACTIVE_HIGH=false
        shift
        ;;
      --pin)
        [ "$#" -ge 2 ] || fail "--pin requires a value."
        PINCTRL_PIN="$2"
        shift 2
        ;;
      --help|-h)
        usage
        exit 0
        ;;
      read|write|list|start-stream)
        command="$1"
        shift
        cmd_args=("$@")
        break
        ;;
      *)
        fail "Unknown option or command: $1"
        ;;
    esac
  done

  if [ -z "$command" ]; then
    usage
    exit 1
  fi

  PARITY="${PARITY^^}"
  case "$PARITY" in
    N|E|O) ;;
    *)
      fail "Unsupported parity setting '$PARITY' (expected N, E, or O)."
      ;;
  esac

  PINCTRL_ACTIVE_HIGH_FLAG=$(to_bool "$PINCTRL_ACTIVE_HIGH")

  SLAVE_ID=$(parse_number "$SLAVE_ID")
  (( SLAVE_ID >= 0 && SLAVE_ID <= 247 )) || fail "Slave id must be between 0 and 247."

  case "$command" in
    list)
      list_registers
      ;;
    start-stream)
      start_stream_command "${cmd_args[@]}"
      ;;
    read)
      [ "${#cmd_args[@]}" -ge 1 ] || fail "read command requires a register identifier."
      open_serial
      local quantity_arg="1"
      if [ "${#cmd_args[@]}" -ge 2 ]; then
        quantity_arg="${cmd_args[1]}"
      fi
      modbus_read "${cmd_args[0]}" "$quantity_arg"
      ;;
    write)
      [ "${#cmd_args[@]}" -ge 2 ] || fail "write command requires a register and a value."
      open_serial
      modbus_write "${cmd_args[0]}" "${cmd_args[1]}"
      ;;
    *)
      fail "Unknown command: $command"
      ;;
  esac
}

main "$@"

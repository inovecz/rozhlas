#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

PORT="${CONTROL_TAB_SERIAL_PORT:-/dev/ttyUSB0}"
BAUDRATE="${CONTROL_TAB_SERIAL_BAUDRATE:-115200}"
BYTESIZE="${CONTROL_TAB_SERIAL_BYTESIZE:-8}"
PARITY="${CONTROL_TAB_SERIAL_PARITY:-N}"
STOPBITS="${CONTROL_TAB_SERIAL_STOPBITS:-1}"
TIMEOUT="${CONTROL_TAB_SERIAL_TIMEOUT:-0.2}"
DURATION=10

usage() {
  cat <<'EOF'
Usage: control_tab_serial_probe.sh [options]

Options:
  --port PATH          Serial device (default: \$CONTROL_TAB_SERIAL_PORT or /dev/ttyUSB0)
  --baudrate N         Baudrate (default: \$CONTROL_TAB_SERIAL_BAUDRATE or 115200)
  --bytesize N         Data bits (default: \$CONTROL_TAB_SERIAL_BYTESIZE or 8)
  --parity {N,E,O}     Parity (default: \$CONTROL_TAB_SERIAL_PARITY or N)
  --stopbits N         Stop bits (default: \$CONTROL_TAB_SERIAL_STOPBITS or 1)
  --timeout SEC        Read timeout in seconds (default: \$CONTROL_TAB_SERIAL_TIMEOUT or 0.2)
  --duration SEC       How long to listen before exiting (default: 10)
  -h, --help           Show this help

Environment overrides: CONTROL_TAB_SERIAL_PORT, CONTROL_TAB_SERIAL_BAUDRATE,
CONTROL_TAB_SERIAL_BYTESIZE, CONTROL_TAB_SERIAL_PARITY, CONTROL_TAB_SERIAL_STOPBITS,
CONTROL_TAB_SERIAL_TIMEOUT.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port)
      [[ $# -lt 2 ]] && { echo "Missing value for --port" >&2; exit 1; }
      PORT="$2"
      shift 2
      ;;
    --baudrate)
      [[ $# -lt 2 ]] && { echo "Missing value for --baudrate" >&2; exit 1; }
      BAUDRATE="$2"
      shift 2
      ;;
    --bytesize)
      [[ $# -lt 2 ]] && { echo "Missing value for --bytesize" >&2; exit 1; }
      BYTESIZE="$2"
      shift 2
      ;;
    --parity)
      [[ $# -lt 2 ]] && { echo "Missing value for --parity" >&2; exit 1; }
      PARITY="$2"
      shift 2
      ;;
    --stopbits)
      [[ $# -lt 2 ]] && { echo "Missing value for --stopbits" >&2; exit 1; }
      STOPBITS="$2"
      shift 2
      ;;
    --timeout)
      [[ $# -lt 2 ]] && { echo "Missing value for --timeout" >&2; exit 1; }
      TIMEOUT="$2"
      shift 2
      ;;
    --duration)
      [[ $# -lt 2 ]] && { echo "Missing value for --duration" >&2; exit 1; }
      DURATION="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required to run this probe." >&2
  exit 1
fi

export PYTHONUNBUFFERED=1

python3 - <<PY
import json
import sys
import time

try:
    import serial
except ImportError as exc:  # pragma: no cover
    sys.stderr.write("pyserial is not installed: %s\n" % exc)
    sys.exit(1)


PORT = ${PORT@Q}
BAUDRATE = int(${BAUDRATE@Q})
BYTESIZE = int(${BYTESIZE@Q})
PARITY = ${PARITY@Q}.upper()
STOPBITS = int(${STOPBITS@Q})
TIMEOUT = float(${TIMEOUT@Q})
DURATION = float(${DURATION@Q})

PARITY_MAP = {
    "N": serial.PARITY_NONE,
    "E": serial.PARITY_EVEN,
    "O": serial.PARITY_ODD,
    "M": serial.PARITY_MARK,
    "S": serial.PARITY_SPACE,
}
parity_value = PARITY_MAP.get(PARITY, serial.PARITY_NONE)


def xor_crc(payload: str) -> str:
    crc = 0
    for byte in payload.encode("utf-8"):
        crc ^= byte
    return f"{crc:02X}"


def parse_frame(raw: str):
    raw = raw.strip()
    if not raw or not raw.startswith("<<<:") or not raw.endswith("<<<"):
        return None
    try:
        body_crc = raw[4:-3]
        body, crc_provided = body_crc.split(">>", 1)
        header, payload = body.split("=", 1)
        screen_str, panel_str, event_type_str = header.split(":")
        screen = int(screen_str)
        panel = int(panel_str)
        event_type = int(event_type_str)
    except Exception:
        return None

    crc_calculated = xor_crc(f"{screen}:{panel}:{event_type}={payload}")
    return {
        "raw": raw,
        "screen": screen,
        "panel": panel,
        "event_type": event_type,
        "payload": payload,
        "crc_provided": crc_provided or None,
        "crc_calculated": crc_calculated,
        "crc_ok": (crc_provided or "").upper() == crc_calculated,
    }


def build_ack(frame, status: int) -> str:
    body = f"{frame['screen']}:{frame['panel']}:{frame['event_type']}={status}"
    crc = xor_crc(body)
    return f"\n>>>:{body}>>{crc}<<<\n"


def describe_event(frame) -> str:
    event_type = frame.get("event_type")
    payload = frame.get("payload", "")
    label = {
        1: "panel_loaded",
        2: "button_pressed",
        3: "text_field_request",
    }.get(event_type, "unknown")
    info = {"type": label, "payload": payload}
    if event_type == 2:
        try:
            info["button_id"] = int(payload.strip() or "0")
        except ValueError:
            info["button_id"] = payload.strip()
    elif event_type == 3:
        info["field_id"] = payload.strip("?")
    return json.dumps(info, ensure_ascii=False)


print(
    json.dumps(
        {
            "status": "starting",
            "port": PORT,
            "baudrate": BAUDRATE,
            "bytesize": BYTESIZE,
            "parity": PARITY,
            "stopbits": STOPBITS,
            "timeout": TIMEOUT,
            "duration": DURATION,
        },
        ensure_ascii=False,
    )
)

try:
    ser = serial.Serial(
        port=PORT,
        baudrate=BAUDRATE,
        bytesize=BYTESIZE,
        parity=parity_value,
        stopbits=STOPBITS,
        timeout=TIMEOUT,
        write_timeout=TIMEOUT,
    )
except Exception as exc:
    sys.stderr.write(f"Failed to open serial port {PORT}: {exc}\\n")
    sys.exit(1)

deadline = time.time() + DURATION
frames = 0
errors = 0
acks_sent = 0

try:
    while time.time() < deadline:
        raw = ser.readline()
        if not raw:
            continue
        try:
            decoded = raw.decode("utf-8", errors="ignore").strip()
        except Exception:
            decoded = ""
        if not decoded:
            continue
        frame = parse_frame(decoded)
        if frame is None:
            errors += 1
            print(json.dumps({"status": "noise", "raw": decoded}, ensure_ascii=False))
            continue
        frames += 1
        print(
            json.dumps(
                {
                    "status": "frame",
                    "raw": frame["raw"],
                    "detail": describe_event(frame),
                    "crc_ok": frame["crc_ok"],
                },
                ensure_ascii=False,
            )
        )
        ack = build_ack(frame, 1)
        ser.write(ack.encode("utf-8"))
        ser.flush()
        acks_sent += 1
        print(json.dumps({"status": "ack_sent", "raw": ack.strip()}, ensure_ascii=False))
finally:
    ser.close()

print(
    json.dumps(
        {
            "status": "summary",
            "frames": frames,
            "acks_sent": acks_sent,
            "noise": errors,
            "duration": DURATION,
        },
        ensure_ascii=False,
    )
)

if frames == 0:
    sys.exit(2)
PY

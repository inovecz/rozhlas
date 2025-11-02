#!/usr/bin/env python3
"""Poll Modbus alarm buffer and forward entries to Laravel artisan."""

from __future__ import annotations

import argparse
import json
import os
import signal
import subprocess
import sys
import time
from contextlib import nullcontext
from pathlib import Path
from typing import Any

ROOT_DIR = Path(__file__).resolve().parents[1]
SRC_DIR = ROOT_DIR / "src"
if SRC_DIR.exists() and str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

try:
    from modbus_audio import ModbusAudioClient, ModbusAudioError, SerialSettings  # type: ignore
    from modbus_audio import constants as modbus_constants  # type: ignore
except Exception as exc:  # pragma: no cover - optional dependency
    raise SystemExit(f"pymodbus/modbus_audio not available: {exc}")

from _locks import PortLock


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Modbus alarm buffer poller daemon")
    parser.add_argument("--port", default=os.getenv("MODBUS_PORT", modbus_constants.DEFAULT_SERIAL_PORT))
    parser.add_argument("--baudrate", type=int, default=int(os.getenv("MODBUS_BAUDRATE", modbus_constants.DEFAULT_BAUDRATE)))
    parser.add_argument("--parity", default=os.getenv("MODBUS_PARITY", modbus_constants.DEFAULT_PARITY))
    parser.add_argument("--stopbits", type=int, default=int(os.getenv("MODBUS_STOPBITS", modbus_constants.DEFAULT_STOPBITS)))
    parser.add_argument("--bytesize", type=int, default=int(os.getenv("MODBUS_BYTESIZE", modbus_constants.DEFAULT_BYTESIZE)))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("MODBUS_TIMEOUT", modbus_constants.DEFAULT_TIMEOUT)))
    parser.add_argument("--timeout-ms", type=int, help="Serial timeout v milisekundách (přepíše --timeout)")
    parser.add_argument("--unit", type=int, default=int(os.getenv("MODBUS_UNIT_ID", modbus_constants.DEFAULT_UNIT_ID)))
    parser.add_argument("--interval", type=float, default=float(os.getenv("ALARM_POLL_INTERVAL", 2.0)))
    parser.add_argument("--once", action="store_true", help="Proveď jedno čtení a ukonči se")
    parser.add_argument("--artisan-bin", default=os.getenv("ARTISAN_BIN", "php"))
    parser.add_argument("--artisan-path", default=os.getenv("ARTISAN_PATH", "artisan"))
    parser.add_argument("--artisan-command", default=os.getenv("ALARM_ARTISAN_COMMAND", "alarm:poll"))
    parser.add_argument("--project-root", default=str(ROOT_DIR.parent))
    return parser


def call_artisan(bin_path: str, artisan_path: str, command: str, payload: dict[str, Any], cwd: Path, timeout: float = 10.0) -> None:
    input_bytes = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    subprocess.run(
        [bin_path, artisan_path, command, "--payload-stdin"],
        input=input_bytes,
        check=False,
        timeout=timeout,
        cwd=str(cwd),
    )


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    if args.timeout_ms is not None:
        args.timeout = max(0.01, args.timeout_ms / 1000.0)

    settings = SerialSettings()
    settings.port = args.port
    settings.baudrate = args.baudrate
    settings.parity = args.parity
    settings.stopbits = args.stopbits
    settings.bytesize = args.bytesize
    settings.timeout = args.timeout

    project_root = Path(args.project_root).expanduser()
    stop_event = False

    def handle_signal(_signum, _frame):  # noqa: ANN001
        nonlocal stop_event
        stop_event = True

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    lock_context = nullcontext()
    if args.port:
        lock_context = PortLock(args.port)

    try:
        with lock_context:
            with ModbusAudioClient(settings, unit_id=args.unit) as client:
                while not stop_event:
                    try:
                        entry = client.read_alarm_buffer()
                    except ModbusAudioError as exc:
                        print(json.dumps({"error": str(exc)}), flush=True)
                        if args.once:
                            break
                        time.sleep(args.interval)
                        continue

                    if entry.get("nest_address") or entry.get("repeat") or any(entry.get("data", [])):
                        payload = {
                            "source_address": entry.get("nest_address", 0),
                            "repeat": entry.get("repeat", 0),
                            "frames": entry.get("data", []),
                            "raw": entry,
                            "priority": "polling",
                        }
                        call_artisan(args.artisan_bin, args.artisan_path, args.artisan_command, payload, project_root)

                    if args.once:
                        break

                    time.sleep(max(0.1, args.interval))
    except ModbusAudioError as exc:
        raise SystemExit(f"Unable to open Modbus port: {exc}") from exc


if __name__ == "__main__":  # pragma: no cover
    main()

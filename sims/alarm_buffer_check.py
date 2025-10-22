#!/usr/bin/env python3
"""Periodické čtení LIFO alarm bufferu (0x3000–0x3009)."""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

CURRENT_DIR = Path(__file__).resolve().parent
ROOT_DIR = CURRENT_DIR.parent
PYTHON_CLIENT_DIR = ROOT_DIR / "python-client"
if str(PYTHON_CLIENT_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_CLIENT_DIR))

from modbus_audio import ModbusAudioClient, ModbusAudioError, SerialSettings


def build_settings(args: argparse.Namespace) -> SerialSettings:
    return SerialSettings(
        port=args.port,
        method="rtu",
        baudrate=args.baudrate,
        parity=args.parity,
        stopbits=args.stopbits,
        bytesize=args.bytesize,
        timeout=args.timeout,
    )


def main() -> None:
    parser = argparse.ArgumentParser(description="Kontrola alarmového LIFO bufferu")
    parser.add_argument("--loops", type=int, default=5, help="Počet iterací čtení")
    parser.add_argument("--delay", type=float, default=2.0, help="Prodleva mezi čteními (s)")
    parser.add_argument("--unit-id", type=int, default=1, help="Unit ID zařízení")
    parser.add_argument("--port", default="/dev/tty.usbserial-AV0K3CPZ", help="Seriový port")
    parser.add_argument("--baudrate", type=int, default=57600)
    parser.add_argument("--parity", default="N")
    parser.add_argument("--stopbits", type=int, default=1)
    parser.add_argument("--bytesize", type=int, default=8)
    parser.add_argument("--timeout", type=float, default=1.0)
    args = parser.parse_args()

    settings = build_settings(args)

    try:
        with ModbusAudioClient(settings=settings, unit_id=args.unit_id) as client:
            for iteration in range(args.loops):
                alarm = client.read_alarm_buffer()
                print(f"Iterace {iteration + 1}/{args.loops}")
                print(json.dumps(alarm, indent=2, ensure_ascii=False))
                time.sleep(args.delay)
    except ModbusAudioError as exc:
        print(f"Modbus chyba: {exc}")
        raise SystemExit(1)


if __name__ == "__main__":  # pragma: no cover
    main()

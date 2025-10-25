#!/usr/bin/env python3
"""Brute-force Modbus RTU probe for /dev/ttyAMA3 with configurable scans."""

from __future__ import annotations

import argparse
import itertools
import json
import os
import sys
from typing import Iterable

from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent
SRC_DIR = ROOT_DIR / "src"
if SRC_DIR.exists() and str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

from modbus_audio import ModbusAudioClient, ModbusAudioError, SerialSettings, constants


DEFAULT_BAUDRATES: tuple[int, ...] = (9600, 19200, 38400, 57600, 115200)
DEFAULT_PARITIES: tuple[str, ...] = ("N", "E", "O")
DEFAULT_UNITS: tuple[int, ...] = tuple(range(1, 57))
DEFAULT_READ_MODES: tuple[str, ...] = ("holding", "input")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Scan Modbus RTU settings and report successful responses.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--port", default=os.environ.get("MODBUS_PORT", "/dev/ttyAMA3"), help="Serial port path")
    parser.add_argument("--method", default=os.environ.get("MODBUS_METHOD", "rtu"), help="Modbus method")
    parser.add_argument(
        "--baudrate",
        type=int,
        nargs="*",
        default=[int(os.environ.get("MODBUS_BAUDRATE", b)) for b in DEFAULT_BAUDRATES],
        help="Baud rates to try",
    )
    parser.add_argument("--parity", nargs="*", default=[os.environ.get("MODBUS_PARITY", p) for p in DEFAULT_PARITIES])
    parser.add_argument("--stopbits", type=int, nargs="*", default=[int(os.environ.get("MODBUS_STOPBITS", 1))])
    parser.add_argument("--bytesize", type=int, nargs="*", default=[int(os.environ.get("MODBUS_BYTESIZE", 8))])
    parser.add_argument("--timeout", type=float, default=float(os.environ.get("MODBUS_TIMEOUT", 1.0)))
    parser.add_argument(
        "--unit",
        type=int,
        nargs="*",
        default=[int(os.environ.get("MODBUS_UNIT_ID", unit)) for unit in DEFAULT_UNITS],
        help="Unit IDs to probe",
    )
    parser.add_argument(
        "--register",
        type=lambda value: int(value, 0),
        default=constants.TX_CONTROL,
        help="Register address to read",
    )
    parser.add_argument("--count", type=int, default=1, help="Number of registers to request")
    parser.add_argument(
        "--mode",
        choices=("holding", "input", "both"),
        default="both",
        help="Which Modbus function(s) to exercise",
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Render machine-readable JSON output instead of text summary",
    )
    return parser.parse_args()


def unique(values: Iterable[int | str]) -> tuple[int | str, ...]:
    seen = []
    for value in values:
        if value not in seen:
            seen.append(value)
    return tuple(seen)


def read_input_registers(client: ModbusAudioClient, address: int, count: int, unit: int | None = None) -> list[int]:
    response = client._call_with_unit(  # type: ignore[attr-defined]
        client._client.read_input_registers,  # type: ignore[attr-defined]
        address=address,
        count=count,
        unit=unit,
    )
    if getattr(response, "isError", lambda: False)():
        raise ModbusAudioError(f"Modbus error reading input registers 0x{address:04X}")
    if not hasattr(response, "registers"):
        raise ModbusAudioError("Unexpected payload from read_input_registers")
    return list(response.registers)


def main() -> None:
    args = parse_args()

    baudrates = unique(args.baudrate or DEFAULT_BAUDRATES)
    parities = tuple(p.upper() for p in unique(args.parity or DEFAULT_PARITIES))
    stopbits = unique(args.stopbits or (1,))
    bytesizes = unique(args.bytesize or (8,))
    units = unique(args.unit or DEFAULT_UNITS)
    modes = {
        "holding": args.mode in {"holding", "both"},
        "input": args.mode in {"input", "both"},
    }

    results: list[dict[str, object]] = []

    total = len(baudrates) * len(parities) * len(stopbits) * len(bytesizes) * len(units)
    processed = 0
    successes: list[dict[str, object]] = []

    for baudrate, parity, stopbit, bytesize in itertools.product(baudrates, parities, stopbits, bytesizes):
        settings = SerialSettings(
            port=args.port,
            method=args.method,
            baudrate=int(baudrate),
            parity=str(parity),
            stopbits=int(stopbit),
            bytesize=int(bytesize),
            timeout=float(args.timeout),
        )

        for unit in units:
            outcome: dict[str, object] = {
                "baudrate": baudrate,
                "parity": parity,
                "stopbits": stopbit,
                "bytesize": bytesize,
                "unit": unit,
            }

            try:
                with ModbusAudioClient(settings, unit_id=unit) as client:
                    if modes["holding"]:
                        try:
                            holding = client.read_registers(args.register, args.count, unit=unit)
                            outcome["holding"] = holding
                            successes.append(outcome.copy())
                        except ModbusAudioError as exc:
                            outcome["holding_error"] = str(exc)

                    if modes["input"]:
                        try:
                            inputs = read_input_registers(client, args.register, args.count, unit=unit)
                            outcome["input"] = inputs
                            successes.append(outcome.copy())
                        except ModbusAudioError as exc:
                            outcome["input_error"] = str(exc)

            except ModbusAudioError as exc:
                outcome["connection_error"] = str(exc)

            results.append(outcome)
            processed += 1
            if args.json:
                processed += 1
                continue

            if "holding" in outcome or "input" in outcome:
                prefix = (
                    f"[{processed}/{total}] baud={baudrate} parity={parity} "
                    f"stopbits={stopbit} bytesize={bytesize} unit={unit}"
                )
                ok_parts = []
                if "holding" in outcome:
                    ok_parts.append(f"holding={outcome['holding']}")
                if "input" in outcome:
                    ok_parts.append(f"input={outcome['input']}")
                print(prefix + " → success: " + ", ".join(ok_parts))

            processed += 1

    if args.json:
        json.dump(results, sys.stdout, indent=2)
        sys.stdout.write("\n")
        return

    print("-" * 60)
    if successes:
        print(f"Successful combinations ({len(successes)} total):")
        for entry in successes:
            details = (
                f"baud={entry['baudrate']} parity={entry['parity']} "
                f"stopbits={entry['stopbits']} bytesize={entry['bytesize']} unit={entry['unit']}"
            )
            if "holding" in entry:
                details += f" holding={entry['holding']}"
            if "input" in entry:
                details += f" input={entry['input']}"
            print("  - " + details)
    else:
        print("No working Modbus combination found.")
    print(f"Tried {total} combinations.")

if __name__ == "__main__":  # pragma: no cover
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)

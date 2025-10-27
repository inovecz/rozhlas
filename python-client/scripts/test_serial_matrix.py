#!/usr/bin/env python3
"""Brute-force Modbus probe across multiple serial configurations.

This helper repeatedly invokes `modbus_control.py probe` with different
environment overrides (baud rate, unit id, RS485 polarity/delays) and records
the first configuration that returns a valid response.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from itertools import product
from pathlib import Path
from typing import Iterable


PROJECT_ROOT = Path(__file__).resolve().parents[1]
CLI_PATH = PROJECT_ROOT / "modbus_control.py"


def run_probe(env: dict[str, str], timeout: float) -> tuple[bool, dict]:
    command = [
        sys.executable,
        str(CLI_PATH),
        "--port",
        env["MODBUS_PORT"],
        "--unit",
        env["MODBUS_UNIT_ID"],
        "probe",
    ]

    try:
        proc = subprocess.run(
            command,
            capture_output=True,
            text=True,
            timeout=timeout,
            env={**os.environ, **env},
        )
    except subprocess.TimeoutExpired as exc:
        return False, {
            "status": "error",
            "message": f"probe timed out after {timeout}s",
            "command": "probe",
            "timeout": timeout,
            "stdout": exc.stdout,
            "stderr": exc.stderr,
        }

    stdout = proc.stdout.strip()
    result: dict
    try:
        result = json.loads(stdout) if stdout else {}
    except json.JSONDecodeError:
        result = {"status": "error", "message": stdout or "non-json output"}

    success = result.get("status") == "ok"
    return success, result


def iter_envs(
    port: str,
    baudrates: Iterable[int],
    units: Iterable[int],
    active_high_options: Iterable[bool],
    leads: Iterable[float],
    tails: Iterable[float],
) -> Iterable[dict[str, str]]:
    for baud, unit, active_high, lead, tail in product(
        baudrates, units, active_high_options, leads, tails
    ):
        yield {
            "MODBUS_PORT": port,
            "MODBUS_BAUDRATE": str(baud),
            "MODBUS_UNIT_ID": str(unit),
            "MODBUS_METHOD": os.environ.get("MODBUS_METHOD", "rtu"),
            "MODBUS_PARITY": os.environ.get("MODBUS_PARITY", "N"),
            "MODBUS_STOPBITS": os.environ.get("MODBUS_STOPBITS", "1"),
            "MODBUS_BYTESIZE": os.environ.get("MODBUS_BYTESIZE", "8"),
            "MODBUS_TIMEOUT": os.environ.get("MODBUS_TIMEOUT", "1.0"),
            "MODBUS_RS485_GPIO_ENABLE": "true",
            "MODBUS_RS485_GPIO_CHIP": os.environ.get("MODBUS_RS485_GPIO_CHIP", "/dev/gpiochip0"),
            "MODBUS_RS485_GPIO_LINE": os.environ.get("MODBUS_RS485_GPIO_LINE", "16"),
            "MODBUS_RS485_GPIO_ACTIVE_HIGH": "true" if active_high else "false",
            "MODBUS_RS485_GPIO_LEAD_SECONDS": f"{lead}",
            "MODBUS_RS485_GPIO_TAIL_SECONDS": f"{tail}",
            "MODBUS_RS485_GPIO_DEBUG": os.environ.get("MODBUS_RS485_GPIO_DEBUG", "false"),
            "MODBUS_RS485_DRIVER_ENABLE": "false",
        }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", default="/dev/ttyAMA3")
    parser.add_argument("--timeout", type=float, default=3.0, help="Probe timeout per attempt (seconds)")
    parser.add_argument("--baud", nargs="*", type=int, default=[57600, 38400, 19200])
    parser.add_argument("--unit", nargs="*", type=int, default=[1, 55])
    parser.add_argument("--active-high", nargs="*", type=str, default=["true", "false"], help="RS485 GPIO polarity options")
    parser.add_argument("--lead", nargs="*", type=float, default=[0.0002, 0.002, 0.01])
    parser.add_argument("--tail", nargs="*", type=float, default=[0.0002, 0.002, 0.01])
    args = parser.parse_args()

    polarity_options = [
        value.lower() in {"1", "true", "yes", "on"}
        for value in (args.active_high or ["true", "false"])
    ]

    attempts = list(
        iter_envs(
            args.port,
            args.baud or [57600],
            args.unit or [1],
            polarity_options,
            args.lead or [0.0002],
            args.tail or [0.0002],
        )
    )

    if not CLI_PATH.exists():
        raise SystemExit(f"Cannot find modbus_control.py at {CLI_PATH}")

    print(f"Testing {len(attempts)} configuration combinations...", flush=True)
    for index, env in enumerate(attempts, start=1):
        label = (
            f"baud={env['MODBUS_BAUDRATE']} unit={env['MODBUS_UNIT_ID']} "
            f"active_high={env['MODBUS_RS485_GPIO_ACTIVE_HIGH']} "
            f"lead={env['MODBUS_RS485_GPIO_LEAD_SECONDS']} "
            f"tail={env['MODBUS_RS485_GPIO_TAIL_SECONDS']}"
        )
        print(f"[{index}/{len(attempts)}] {label}", flush=True)
        success, result = run_probe(env, args.timeout)
        print(f"    -> {result}")
        if success:
            print("\nSUCCESS: Working configuration found:")
            print(json.dumps(env, indent=2))
            break
    else:
        print("\nNo configuration produced a valid response.", file=sys.stderr)


if __name__ == "__main__":
    main()

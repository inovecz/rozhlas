#!/usr/bin/env python3

"""Unlock SIM7600-based GSM modems by submitting the configured SIM PIN."""

from __future__ import annotations

import argparse
import sys
from contextlib import nullcontext
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[2]
DAEMON_ROOT = PROJECT_ROOT / "python-client" / "daemons"
sys.path.insert(0, str(DAEMON_ROOT))

# Import after adjusting sys.path so the daemons directory is resolvable.
from gsm_listener import Sim7600ATClient  # type: ignore import
from _locks import PortLock  # type: ignore import


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Submit SIM PIN to SIM7600G-H-PCIE modem to unlock the SIM card.",
    )
    parser.add_argument("--port", required=True, help="Serial device of the GSM modem.")
    parser.add_argument("--pin", required=True, help="SIM PIN code to submit.")
    parser.add_argument("--baudrate", type=int, default=115200, help="Serial baudrate (default: 115200).")
    parser.add_argument("--bytesize", type=int, default=8, help="Serial bytesize (default: 8).")
    parser.add_argument("--parity", default="N", help="Serial parity (default: N).")
    parser.add_argument("--stopbits", type=int, default=1, help="Serial stop bits (default: 1).")
    parser.add_argument("--timeout", type=float, default=0.5, help="Serial timeout in seconds (default: 0.5).")
    parser.add_argument(
        "--write-timeout",
        type=float,
        default=1.0,
        help="Serial write timeout in seconds (default: 1.0).",
    )
    parser.add_argument(
        "--lock",
        action="store_true",
        default=True,
        help="Lock the serial port while initialising (enabled by default).",
    )
    parser.add_argument(
        "--no-lock",
        dest="lock",
        action="store_false",
        help="Do not take an exclusive lock on the serial port.",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    client = Sim7600ATClient(
        port=args.port,
        baudrate=args.baudrate,
        bytesize=args.bytesize,
        parity=args.parity,
        stopbits=args.stopbits,
        timeout=args.timeout,
        write_timeout=args.write_timeout,
        sim_pin=args.pin,
    )

    context = PortLock(args.port) if args.lock else nullcontext()

    try:
        with context:
            client.open()
    except RuntimeError as exc:  # pragma: no cover - hardware dependent
        message = str(exc)
        if "locked by another process" in message.lower():
            print(f"SKIP: Port {args.port} is currently locked by another process; leaving SIM untouched.")
            return 2
        print(f"ERROR: Failed to unlock SIM on {args.port}: {message}")
        return 1
    except Exception as exc:  # pragma: no cover - depends on hardware
        print(f"ERROR: Failed to unlock SIM on {args.port}: {exc}")
        return 1
    finally:
        client.close()

    print(f"OK: SIM on {args.port} is ready.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3

"""Simple CLI for controlling a TEA5767 FM receiver (mute and frequency)."""

from __future__ import annotations

import argparse
import sys
from dataclasses import dataclass
from typing import Sequence

try:
    from smbus2 import SMBus, i2c_msg  # type: ignore import
except ModuleNotFoundError as exc:  # pragma: no cover - environment dependent
    print("ERROR: smbus2 module is required to control the TEA5767 (pip install smbus2).", file=sys.stderr)
    raise SystemExit(1) from exc


I2C_ADDRESS_DEFAULT = 0x60
IF_OFFSET_HZ = 225_000
PLL_REF_DIVIDER = 8_192  # 32768 / 4


@dataclass(slots=True)
class RadioState:
    frequency_mhz: float
    pll: int
    raw: Sequence[int]


def frequency_to_pll(freq_mhz: float) -> int:
    freq_hz = int(round(freq_mhz * 1_000_000))
    pll = int(round((freq_hz + IF_OFFSET_HZ) / PLL_REF_DIVIDER))
    if not 0 <= pll <= 0x3FFF:
        raise ValueError(f"Frequency {freq_mhz} MHz results in invalid PLL value {pll}.")
    return pll


def pll_to_frequency(pll: int) -> float:
    freq_hz = (pll * PLL_REF_DIVIDER) - IF_OFFSET_HZ
    return freq_hz / 1_000_000.0


def read_state(bus_id: int, address: int) -> RadioState:
    with SMBus(bus_id) as bus:
        read = i2c_msg.read(address, 5)
        bus.i2c_rdwr(read)
        data = list(read)
    if len(data) != 5:
        raise RuntimeError("Unexpected response length from TEA5767.")
    pll = ((data[0] & 0x3F) << 8) | data[1]
    freq_mhz = pll_to_frequency(pll)
    return RadioState(frequency_mhz=freq_mhz, pll=pll, raw=data)


def build_control_bytes(freq_mhz: float, mute: bool) -> list[int]:
    pll = frequency_to_pll(freq_mhz)
    byte0 = ((pll >> 8) & 0x3F) | (0x80 if mute else 0x00)  # MUTE flag lives in bit 7
    byte1 = pll & 0xFF

    # Byte2: HLSI (bit4) = 1 for high-side injection; keep other bits cleared.
    byte2 = 0x10

    # Byte3: XTAL (bit4) = 1 for 32.768 kHz reference.
    byte3 = 0x10

    # Byte4: De-emphasis 75us (bits 6-5 = 00), stereo noise cancelling disabled.
    byte4 = 0x00

    return [byte0, byte1, byte2, byte3, byte4]


def write_control(bus_id: int, address: int, freq_mhz: float, mute: bool) -> None:
    control_bytes = build_control_bytes(freq_mhz, mute)
    with SMBus(bus_id) as bus:
        msg = i2c_msg.write(address, bytes(control_bytes))
        bus.i2c_rdwr(msg)


def command_status(args: argparse.Namespace) -> None:
    state = read_state(args.bus, args.address)
    print(f"Frequency : {state.frequency_mhz:.2f} MHz")
    print("Ready flag: {}".format("yes" if state.raw[0] & 0x80 else "no"))
    print("Stereo    : {}".format("yes" if state.raw[2] & 0x80 else "no"))
    print(f"Raw bytes : {' '.join(f'0x{byte:02X}' for byte in state.raw)}")


def command_set(args: argparse.Namespace) -> None:
    if args.mute and args.unmute:
        raise SystemExit("Cannot specify both --mute and --unmute.")

    desired_mute: bool
    if args.mute:
        desired_mute = True
    elif args.unmute:
        desired_mute = False
    else:
        desired_mute = False  # default to unmuted if not specified

    write_control(args.bus, args.address, args.frequency, desired_mute)
    print(f"Set TEA5767 to {args.frequency:.2f} MHz ({'muted' if desired_mute else 'playing'}).")


def command_mute(args: argparse.Namespace, mute: bool) -> None:
    state = read_state(args.bus, args.address)
    write_control(args.bus, args.address, state.frequency_mhz, mute)
    action = "Muted" if mute else "Unmuted"
    print(f"{action} TEA5767 at {state.frequency_mhz:.2f} MHz.")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Control TEA5767 FM tuner (mute/unmute/set frequency).")
    parser.add_argument("--bus", type=int, default=1, help="I2C bus number (default: 1).")
    parser.add_argument("--address", type=lambda value: int(value, 0), default=I2C_ADDRESS_DEFAULT, help="I2C address (default: 0x60).")

    subparsers = parser.add_subparsers(dest="command", required=True)

    status_parser = subparsers.add_parser("status", help="Read current tuner status.")
    status_parser.set_defaults(func=command_status)

    set_parser = subparsers.add_parser("set", help="Set frequency (in MHz) and optional mute state.")
    set_parser.add_argument("frequency", type=float, help="Target frequency in MHz (e.g. 101.10).")
    set_parser.add_argument("--mute", action="store_true", help="Mute audio after tuning.")
    set_parser.add_argument("--unmute", action="store_true", help="Unmute audio after tuning.")
    set_parser.set_defaults(func=command_set)

    mute_parser = subparsers.add_parser("mute", help="Mute the tuner without changing frequency.")
    mute_parser.set_defaults(func=lambda parsed: command_mute(parsed, True))

    unmute_parser = subparsers.add_parser("unmute", help="Unmute the tuner without changing frequency.")
    unmute_parser.set_defaults(func=lambda parsed: command_mute(parsed, False))

    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    try:
        args.func(args)
    except OSError as exc:
        print(f"ERROR: I2C communication failed: {exc}", file=sys.stderr)
        return 1
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

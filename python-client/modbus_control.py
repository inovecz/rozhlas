#!/usr/bin/env python3
"""Backend-facing entrypoint for Modbus audio control.

This script is meant to be triggered by the PHP backend via ``PythonClient``.
It wraps the higher level helpers from ``modbus_audio`` and prints JSON
responses so callers can reliably inspect the outcome.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path
from typing import Any


ROOT_DIR = Path(__file__).resolve().parent
SRC_DIR = ROOT_DIR / "src"
if SRC_DIR.exists() and str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

from modbus_audio import ModbusAudioClient, ModbusAudioError, SerialSettings, constants  # noqa: E402


def int_from_string(value: str) -> int:
    """Parse decimal or ``0x`` prefixed integers from CLI arguments."""

    return int(value, 0)


def env_int(name: str, default: int) -> int:
    """Return integer environment variable or the provided default."""

    value = os.environ.get(name)
    if value is None:
        return default
    try:
        return int(value, 0)
    except ValueError as exc:
        raise ValueError(f"Invalid integer value for environment variable {name}: {value}") from exc


def env_float(name: str, default: float) -> float:
    """Return floating-point environment variable or the provided default."""

    value = os.environ.get(name)
    if value is None:
        return default
    try:
        return float(value)
    except ValueError as exc:
        raise ValueError(f"Invalid float value for environment variable {name}: {value}") from exc


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Control Modbus audio transmitter functions")
    parser.add_argument("--port", help="Serial port path, e.g. /dev/ttyUSB0 or COM3")
    parser.add_argument("--method", help="Modbus method (defaults to env/MODBUS_METHOD or rtu)")
    parser.add_argument("--baudrate", type=int, help="Serial baud rate")
    parser.add_argument("--parity", help="Serial parity (N/E/O)")
    parser.add_argument("--stopbits", type=int, help="Stop bits (1/2)")
    parser.add_argument("--bytesize", type=int, help="Serial byte size (default 8)")
    parser.add_argument("--timeout", type=float, help="Serial read timeout in seconds")
    parser.add_argument("--unit-id", type=int, help="Modbus unit/slave identifier")

    sub = parser.add_subparsers(dest="command", required=True)

    start_cmd = sub.add_parser("start-stream", help="Configure optional routing, zones and start audio streaming")
    start_cmd.add_argument("--route", type=int_from_string, nargs="*", help="Optional hop addresses for the RF route")
    start_cmd.add_argument(
        "--zones",
        type=int_from_string,
        nargs="*",
        help="Optional list of destination zones (defaults to library constants when omitted)",
    )

    sub.add_parser("stop-stream", help="Stop audio streaming by clearing TxControl")

    sub.add_parser("device-info", help="Read a snapshot of documented device registers")

    sub.add_parser("status", help="Read TxControl, Status and Error registers for quick diagnostics")

    probe_cmd = sub.add_parser("probe", help="Verify the Modbus device responds on the configured address")
    probe_cmd.add_argument(
        "--register",
        type=int_from_string,
        default=constants.PROBE_REGISTER,
        help=f"Register address to probe (default: 0x{constants.PROBE_REGISTER:04X})",
    )

    read_reg_cmd = sub.add_parser("read-register", help="Read one or more consecutive holding registers")
    read_reg_cmd.add_argument("--address", type=int_from_string, required=True, help="Starting register address")
    read_reg_cmd.add_argument("--count", type=int, default=1, help="Number of registers to read (default: 1)")

    write_reg_cmd = sub.add_parser("write-register", help="Write a single holding register")
    write_reg_cmd.add_argument("--address", type=int_from_string, required=True, help="Register address")
    write_reg_cmd.add_argument("--value", type=int_from_string, required=True, help="Value to write")

    write_regs_cmd = sub.add_parser("write-registers", help="Write multiple consecutive holding registers")
    write_regs_cmd.add_argument("--address", type=int_from_string, required=True, help="Starting register address")
    write_regs_cmd.add_argument(
        "--values",
        type=int_from_string,
        nargs="+",
        required=True,
        help="Sequence of values to write (decimal or 0x prefixed)",
    )

    read_block_cmd = sub.add_parser(
        "read-block",
        help="Read a documented register block by name (see modbus_audio.constants.DOCUMENTED_REGISTERS)",
    )
    read_block_cmd.add_argument("--name", required=True, help="Block name, case insensitive (e.g. Frequency)")

    write_block_cmd = sub.add_parser(
        "write-block",
        help="Write a documented register block by name (see modbus_audio.constants.DOCUMENTED_REGISTERS)",
    )
    write_block_cmd.add_argument("--name", required=True, help="Block name, case insensitive (e.g. TxControl)")
    write_block_cmd.add_argument(
        "--values",
        type=int_from_string,
        nargs="+",
        required=True,
        help="Values to write into the block",
    )

    return parser


def resolve_serial_settings(args: argparse.Namespace) -> tuple[SerialSettings, int]:
    """Build serial settings object from CLI arguments and environment."""

    port = args.port or os.environ.get("MODBUS_PORT") or constants.DEFAULT_SERIAL_PORT
    if not port:
        raise ValueError("Serial port must be provided via --port or MODBUS_PORT environment variable")

    method = args.method or os.environ.get("MODBUS_METHOD") or "rtu"
    parity = (args.parity or os.environ.get("MODBUS_PARITY") or constants.DEFAULT_PARITY).upper()

    baudrate = args.baudrate if args.baudrate is not None else env_int("MODBUS_BAUDRATE", constants.DEFAULT_BAUDRATE)
    stopbits = args.stopbits if args.stopbits is not None else env_int("MODBUS_STOPBITS", constants.DEFAULT_STOPBITS)
    bytesize = args.bytesize if args.bytesize is not None else env_int("MODBUS_BYTESIZE", constants.DEFAULT_BYTESIZE)
    timeout = args.timeout if args.timeout is not None else env_float("MODBUS_TIMEOUT", constants.DEFAULT_TIMEOUT)
    unit_id = args.unit_id if args.unit_id is not None else env_int("MODBUS_UNIT_ID", constants.DEFAULT_UNIT_ID)

    settings = SerialSettings(
        port=port,
        method=method,
        baudrate=baudrate,
        parity=parity,
        stopbits=stopbits,
        bytesize=bytesize,
        timeout=timeout,
    )

    return settings, unit_id


def command_start_stream(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    applied_route = list(args.route) if args.route else None
    zones = list(args.zones) if args.zones else None
    applied_zones = zones if zones is not None else list(constants.DEFAULT_DESTINATION_ZONES)

    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        if applied_route is not None:
            client.configure_route(applied_route)
        client.start_stream(zones=zones)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "zones": applied_zones,
        "route": applied_route,
    }


def command_stop_stream(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.stop_stream()

    return {
        "port": settings.port,
        "unitId": unit_id,
    }


def command_device_info(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        info = client.get_device_info()

    return {
        "port": settings.port,
        "unitId": unit_id,
        "info": info,
    }


def command_status(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        tx_control = client.read_register(constants.TX_CONTROL)
        status_reg = client.read_register(constants.STATUS_REGISTER)
        error_reg = client.read_register(constants.ERROR_REGISTER)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "registers": {
            "txControl": tx_control,
            "status": status_reg,
            "error": error_reg,
        },
    }


def command_probe(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    register = args.register
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        value = client.read_register(register)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "register": f"0x{register:04X}",
        "value": value,
    }


def command_read_register(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    address = args.address
    count = max(1, args.count)
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        values = client.read_registers(address, count)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "address": f"0x{address:04X}",
        "count": len(values),
        "values": values,
    }


def command_write_register(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    address = args.address
    value = args.value
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.write_register(address, value)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "address": f"0x{address:04X}",
        "values": [value],
    }


def command_write_registers(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    address = args.address
    values = list(args.values)
    if not values:
        raise ValueError("At least one value must be provided for write-registers")
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.write_registers(address, values)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "address": f"0x{address:04X}",
        "values": values,
    }


def _resolve_descriptor(name: str) -> constants.RegisterDescriptor:
    target = name.strip().lower()
    for descriptor in constants.DOCUMENTED_REGISTERS:
        if descriptor.name.lower() == target:
            return descriptor
    valid = ", ".join(desc.name for desc in constants.DOCUMENTED_REGISTERS)
    raise ValueError(f"Unknown register block '{name}'. Valid options: {valid}")


def command_read_block(args: argparse.Namespace) -> dict[str, Any]:
    descriptor = _resolve_descriptor(args.name)
    settings, unit_id = resolve_serial_settings(args)
    block = descriptor.block
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        values = client.read_registers(block.start, block.quantity)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "name": descriptor.name,
        "address": f"0x{block.start:04X}",
        "quantity": block.quantity,
        "values": values,
    }


def command_write_block(args: argparse.Namespace) -> dict[str, Any]:
    descriptor = _resolve_descriptor(args.name)
    values = list(args.values)
    block = descriptor.block

    if len(values) != block.quantity:
        raise ValueError(
            f"Register block '{descriptor.name}' expects {block.quantity} value(s); received {len(values)}"
        )

    settings, unit_id = resolve_serial_settings(args)
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        if block.quantity == 1:
            client.write_register(block.start, values[0])
        else:
            client.write_registers(block.start, values)

    return {
        "port": settings.port,
        "unitId": unit_id,
        "name": descriptor.name,
        "address": f"0x{block.start:04X}",
        "values": values,
    }


def dispatch(args: argparse.Namespace) -> dict[str, Any]:
    if args.command == "start-stream":
        return command_start_stream(args)
    if args.command == "stop-stream":
        return command_stop_stream(args)
    if args.command == "device-info":
        return command_device_info(args)
    if args.command == "status":
        return command_status(args)
    if args.command == "probe":
        return command_probe(args)
    if args.command == "read-register":
        return command_read_register(args)
    if args.command == "write-register":
        return command_write_register(args)
    if args.command == "write-registers":
        return command_write_registers(args)
    if args.command == "read-block":
        return command_read_block(args)
    if args.command == "write-block":
        return command_write_block(args)
    raise ValueError(f"Unsupported command: {args.command}")


def output_success(command: str, data: dict[str, Any] | None = None) -> None:
    payload: dict[str, Any] = {"status": "ok", "command": command}
    if data is not None:
        payload["data"] = data
    print(json.dumps(payload, ensure_ascii=False))
    raise SystemExit(0)


def output_error(command: str | None, message: str, *, error_type: str | None = None) -> None:
    payload: dict[str, Any] = {"status": "error", "message": message}
    if command:
        payload["command"] = command
    if error_type:
        payload["errorType"] = error_type
    print(json.dumps(payload, ensure_ascii=False))
    raise SystemExit(1)


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    try:
        data = dispatch(args)
    except ModbusAudioError as exc:
        output_error(args.command, str(exc), error_type="ModbusAudioError")
    except Exception as exc:
        output_error(getattr(args, "command", None), str(exc), error_type=exc.__class__.__name__)
    else:
        output_success(args.command, data)


if __name__ == "__main__":  # pragma: no cover
    main()

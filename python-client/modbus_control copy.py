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
from typing import Any, Iterable


ROOT_DIR = Path(__file__).resolve().parent
SRC_DIR = ROOT_DIR / "src"
if SRC_DIR.exists() and str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

VENV_DIR = ROOT_DIR / ".venv"
if VENV_DIR.exists():
    for pattern in ("lib/python*/site-packages", "lib64/python*/site-packages"):
        for candidate in VENV_DIR.glob(pattern):
            candidate_path = str(candidate)
            if candidate.is_dir() and candidate_path not in sys.path:
                sys.path.insert(0, candidate_path)

def load_env_file(path: Path) -> None:
    if not path.exists():
        return
    for raw_line in path.read_text().splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if not key or key in os.environ:
            continue
        value = value.strip()
        if len(value) >= 2 and ((value.startswith('"') and value.endswith('"')) or (value.startswith("'") and value.endswith("'"))):
            value = value[1:-1]
        os.environ[key] = value

load_env_file(ROOT_DIR.parent / ".env")

from modbus_audio import ModbusAudioClient, ModbusAudioError, SerialSettings, constants  # noqa: E402


JSVV_SAMPLE_REGISTER_BASE = 0x0011
JSVV_SAMPLE_REGISTER_COUNT = 4
JSVV_PRIORITY_REGISTER = 0x0010
DEFAULT_JSVV_NESTS = (101, 102)

DTRX_SEQUENCE_SYMBOLS = {
    "1": 1,
    "2": 2,
    "4": 3,
    "8": 5,
    "9": 6,
    "A": 7,
    "B": 8,
    "C": 9,
    "D": 10,
    "E": 11,
    "F": 12,
    "G": 13,
    "P": 14,
    "Q": 15,
    "R": 16,
    "S": 17,
    "T": 18,
    "U": 19,
    "V": 20,
    "X": 21,
    "Y": 22,
}


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
    start_cmd.add_argument(
        "--update-route",
        action="store_true",
        help="Reconfigure hop route registers before starting (defaults to skipping route programming)",
    )

    sub.add_parser("stop-stream", help="Stop audio streaming by clearing TxControl")

    sub.add_parser("device-info", help="Read a snapshot of documented device registers")

    sub.add_parser("status", help="Read TxControl, Status and Error registers for quick diagnostics")

    sub.add_parser("defaults", help="Return default serial settings and register constants")

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

    play_cmd = sub.add_parser(
        "play-sequence",
        help="Naplánuje sample kódy a spustí vysílání JSVV sekvence.",
    )
    play_cmd.add_argument(
        "sequence",
        help="Sekvence symbolů (např. '2,8,A,9' nebo '28A9').",
    )
    play_cmd.add_argument(
        "--update-route",
        action="store_true",
        help="Rekonfiguruje RAM trasu (0x0000-0x0005) před zápisem sekvence.",
    )

    read_freq_cmd = sub.add_parser("read-frequency", help="Read current RF frequency register")
    read_freq_cmd.add_argument("--unit-id", type=int, help="Optional unit id override")

    set_freq_cmd = sub.add_parser("set-frequency", help="Write RF frequency register")
    set_freq_cmd.add_argument("--value", type=int_from_string, required=True, help="Target frequency value")
    set_freq_cmd.add_argument("--unit-id", type=int, help="Optional unit id override")

    read_nest_cmd = sub.add_parser("read-nest-status", help="Read status/error registers for a specific nest")
    read_nest_cmd.add_argument("--nest", type=int_from_string, required=True, help="Bidirectional nest address (A16)")
    read_nest_cmd.add_argument(
        "--route",
        type=int_from_string,
        nargs="*",
        help="Optional hop addresses to prepend before nest address (e.g. hub or repeaters)",
    )

    route_cmd = sub.add_parser("set-route", help="Populate hop route registers without starting stream")
    route_cmd.add_argument(
        "--addresses",
        type=int_from_string,
        nargs="*",
        help="Route hop addresses; omit to clear",
    )

    zones_cmd = sub.add_parser("set-zones", help="Populate destination zone registers without starting stream")
    zones_cmd.add_argument(
        "--zones",
        type=int_from_string,
        nargs="*",
        help="Destination zones; omit to clear",
    )

    read_route_cmd = sub.add_parser("read-route", help="Return configured hop route and zones")
    read_route_cmd.add_argument("--unit-id", type=int, help="Optional unit id override")

    alarms_cmd = sub.add_parser("read-alarms", help="Read the alarm LIFO buffer (0x3000-0x3009)")
    alarms_cmd.add_argument("--unit-id", type=int, help="Optional unit id override")

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


def _fallback_serial_context(args: argparse.Namespace) -> dict[str, Any]:
    def _select_value(arg_name: str, env_name: str) -> Any:
        value = getattr(args, arg_name, None)
        if value is not None:
            return value
        return os.environ.get(env_name)

    def _coerce_int(raw: Any, default: int) -> Any:
        if raw is None:
            return default
        if isinstance(raw, int):
            return raw
        try:
            return int(str(raw), 0)
        except (TypeError, ValueError):
            return raw

    def _coerce_float(raw: Any, default: float) -> Any:
        if raw is None:
            return default
        if isinstance(raw, (int, float)):
            return float(raw)
        try:
            return float(str(raw))
        except (TypeError, ValueError):
            return raw

    port = _select_value("port", "MODBUS_PORT") or constants.DEFAULT_SERIAL_PORT
    method = _select_value("method", "MODBUS_METHOD") or "rtu"
    parity_source = _select_value("parity", "MODBUS_PARITY") or constants.DEFAULT_PARITY
    parity = parity_source.upper() if isinstance(parity_source, str) else parity_source

    baudrate = _coerce_int(_select_value("baudrate", "MODBUS_BAUDRATE"), constants.DEFAULT_BAUDRATE)
    stopbits = _coerce_int(_select_value("stopbits", "MODBUS_STOPBITS"), constants.DEFAULT_STOPBITS)
    bytesize = _coerce_int(_select_value("bytesize", "MODBUS_BYTESIZE"), constants.DEFAULT_BYTESIZE)
    timeout = _coerce_float(_select_value("timeout", "MODBUS_TIMEOUT"), constants.DEFAULT_TIMEOUT)
    unit_id = _coerce_int(_select_value("unit_id", "MODBUS_UNIT_ID"), constants.DEFAULT_UNIT_ID)

    return {
        "port": port,
        "method": method,
        "baudrate": baudrate,
        "parity": parity,
        "stopbits": stopbits,
        "bytesize": bytesize,
        "timeout": timeout,
        "unitId": unit_id,
    }


def gather_serial_context(args: argparse.Namespace) -> dict[str, Any]:
    try:
        settings, unit_id = resolve_serial_settings(args)
    except Exception:
        return _fallback_serial_context(args)
    return {
        "port": settings.port,
        "method": settings.method,
        "baudrate": settings.baudrate,
        "parity": settings.parity,
        "stopbits": settings.stopbits,
        "bytesize": settings.bytesize,
        "timeout": settings.timeout,
        "unitId": unit_id,
    }


def merge_serial_payload(data: Any, serial_context: dict[str, Any]) -> dict[str, Any]:
    merged: dict[str, Any] = dict(serial_context)
    if data is None:
        return merged
    if isinstance(data, dict):
        merged.update(data)
        return merged
    merged["result"] = data
    return merged


def remember_response_data(args: argparse.Namespace, data: dict[str, Any]) -> dict[str, Any]:
    setattr(args, "_response_data", data)
    return data


def _env_optional_int(name: str) -> int | None:
    raw = os.environ.get(name)
    if raw is None:
        return None
    stripped = raw.strip()
    if not stripped:
        return None
    try:
        return int(stripped, 0)
    except ValueError as exc:
        raise ValueError(f"Invalid integer for {name}: {raw}") from exc


def resolve_jsvv_register_layout() -> dict[str, int | bool]:
    base_address = _env_optional_int("JSVV_REMOTE_DTRX_BASE")
    command_start = _env_optional_int("JSVV_REMOTE_DTRX_COMMAND_START") or 11
    command_count = _env_optional_int("JSVV_REMOTE_DTRX_COMMAND_COUNT") or JSVV_SAMPLE_REGISTER_COUNT
    priority_register = _env_optional_int("JSVV_REMOTE_DTRX_PRIORITY_REGISTER") or 10

    if command_count <= 0:
        raise ValueError("JSVV command register count must be greater than zero.")

    if base_address is None:
        # Legacy layout with absolute register addresses
        return {
            "commands_base": JSVV_SAMPLE_REGISTER_BASE,
            "commands_count": command_count,
            "priority_register": JSVV_PRIORITY_REGISTER,
            "configured": False,
        }

    if command_start <= 0:
        raise ValueError("JSVV remote trigger command register start must be positive.")
    if priority_register <= 0:
        raise ValueError("JSVV remote trigger priority register number must be positive.")

    commands_base = base_address + (command_start - 1)
    priority_address = base_address + (priority_register - 1)

    return {
        "commands_base": commands_base,
        "commands_count": command_count,
        "priority_register": priority_address,
        "configured": True,
    }


def _parse_sequence_string(sequence: str) -> list[int]:
    cleaned = sequence.strip().upper().replace(" ", "")
    if not cleaned:
        raise ValueError("Sekvence musí obsahovat alespoň jeden symbol.")

    if "," in cleaned:
        symbols = [part for part in cleaned.split(",") if part]
    else:
        symbols = list(cleaned)

    sample_codes: list[int] = []
    for symbol in symbols:
        code = DTRX_SEQUENCE_SYMBOLS.get(symbol)
        if code is None:
            valid = ", ".join(sorted(DTRX_SEQUENCE_SYMBOLS.keys()))
            raise ValueError(f"Neznámý symbol '{symbol}'. Povolené symboly: {valid}")
        sample_codes.append(code)

    return sample_codes


def _parse_default_nests() -> list[int]:
    raw = os.environ.get("MODBUS_JSVV_NESTS")
    if not raw:
        return list(DEFAULT_JSVV_NESTS)

    nests: list[int] = []
    for part in raw.split(","):
        candidate = part.strip()
        if not candidate:
            continue
        try:
            nests.append(int(candidate, 0))
        except ValueError as exc:
            raise ValueError(f"Neplatná hodnota '{candidate}' v MODBUS_JSVV_NESTS") from exc

    if not nests:
        return list(DEFAULT_JSVV_NESTS)
    return nests


def _pad_sequence(values: Iterable[int], size: int, fill: int = 0) -> list[int]:
    result = list(values)[:size]
    while len(result) < size:
        result.append(fill)
    return result


def command_start_stream(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    applied_route = list(args.route) if args.route is not None else list(constants.DEFAULT_ROUTE)
    zones = list(args.zones) if args.zones else None
    applied_zones = zones if zones is not None else list(constants.DEFAULT_DESTINATION_ZONES)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "zones": applied_zones,
            "route": applied_route,
        },
    )

    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.start_stream(applied_route, zones=zones, configure_route=args.update_route)

    return response


def command_stop_stream(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.stop_stream()

    return response


def command_read_alarm_buffer(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "alarm": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        alarm = client.read_alarm_buffer()

    response["alarm"] = alarm
    return response


def command_read_nest_status(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    nest_address = int(args.nest)
    route_prefix = list(args.route) if args.route else []
    default_route = route_prefix + [nest_address]
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "nest": nest_address,
            "route": default_route,
            "status": None,
            "error": None,
        },
    )

    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        status_payload = client.read_nest_status(nest_address, route=route_prefix)

    response["route"] = status_payload.get("route", default_route)
    response["status"] = status_payload.get("status")
    response["error"] = status_payload.get("error")
    return response


def command_device_info(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "info": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        info = client.get_device_info()

    response["info"] = info
    return response


def command_status(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "registers": {
                "txControl": None,
                "status": None,
                "error": None,
            },
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        tx_control = client.read_register(constants.TX_CONTROL)
        status_reg = client.read_register(constants.STATUS_REGISTER)
        error_reg = client.read_register(constants.ERROR_REGISTER)

    response["registers"]["txControl"] = tx_control
    response["registers"]["status"] = status_reg
    response["registers"]["error"] = error_reg
    return response


def command_probe(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    register = args.register
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "register": f"0x{register:04X}",
            "value": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        value = client.read_register(register)

    response["value"] = value
    return response


def command_read_register(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    address = args.address
    count = max(1, args.count)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "address": f"0x{address:04X}",
            "count": count,
            "values": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        values = client.read_registers(address, count)

    response["values"] = values
    response["count"] = len(values)
    return response


def command_write_register(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    address = args.address
    value = args.value
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "address": f"0x{address:04X}",
            "values": [value],
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.write_register(address, value)

    return response


def command_write_registers(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    address = args.address
    values = list(args.values)
    if not values:
        raise ValueError("At least one value must be provided for write-registers")
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "address": f"0x{address:04X}",
            "values": values,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.write_registers(address, values)

    return response


def command_play_sequence(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    sample_codes = _parse_sequence_string(args.sequence)
    nests = _parse_default_nests()
    priority = env_int("MODBUS_JSVV_PRIORITY", 2)
    layout = resolve_jsvv_register_layout()

    if len(nests) > constants.MAX_ADDR_ENTRIES:
        nests = nests[: constants.MAX_ADDR_ENTRIES]

    sequence_registers = _pad_sequence(sample_codes, int(layout["commands_count"]))
    should_update_route = getattr(args, "update_route", False)
    register_writes: list[dict[str, object]] = []
    if should_update_route:
        register_writes.append(
            {
                "address": f"0x{constants.NUM_ADDR_RAM:04X}",
                "values": [len(nests)],
                "description": "numAddrRam",
            }
        )
        register_writes.append(
            {
                "address": f"0x{constants.ADDR_RAM_BASE:04X}",
                "values": nests[: constants.MAX_ADDR_ENTRIES],
                "description": "addrRam",
            }
        )
    register_writes.append(
        {
            "address": f"0x{int(layout['commands_base']):04X}",
            "values": sequence_registers,
            "description": "sampleCodes",
        }
    )
    register_writes.append(
        {
            "address": f"0x{int(layout['priority_register']):04X}",
            "values": [priority],
            "description": "commandPriority",
        }
    )
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "sequence": sequence_registers,
            "priority": priority,
            "nests": nests,
            "updateRoute": should_update_route,
            "commandRegister": int(layout["commands_base"]),
            "priorityRegister": int(layout["priority_register"]),
            "customLayout": bool(layout["configured"]),
            "registerWrites": register_writes,
        },
    )

    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        if should_update_route:
            client.configure_route(nests)
        client.write_registers(int(layout["commands_base"]), sequence_registers)
        client.write_registers(int(layout["priority_register"]), [priority])

    return response


def command_read_frequency(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "frequency": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        value = client.read_frequency()

    response["frequency"] = value
    return response


def command_set_frequency(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    value = args.value
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "frequency": value,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.write_frequency(value=value)

    return response


def command_set_route(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    addresses = list(args.addresses) if args.addresses else []
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "route": addresses,
            "count": len(addresses),
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.configure_route(addresses)

    return response


def command_set_zones(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    zones = list(args.zones) if args.zones else []
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "zones": zones,
            "count": len(zones),
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        client.set_destination_zones(zones)

    return response


def command_read_route(args: argparse.Namespace) -> dict[str, Any]:
    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "route": None,
            "zones": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        info = client.get_device_info()

    route = info.get("configured_route")
    zones = info.get("destination_zones")
    response["route"] = route
    response["zones"] = zones
    return response


def command_defaults(args: argparse.Namespace) -> dict[str, Any]:
    return remember_response_data(
        args,
        {
            "serial": {
                "port": constants.DEFAULT_SERIAL_PORT,
                "baudrate": constants.DEFAULT_BAUDRATE,
                "parity": constants.DEFAULT_PARITY,
                "stopbits": constants.DEFAULT_STOPBITS,
                "bytesize": constants.DEFAULT_BYTESIZE,
                "timeout": constants.DEFAULT_TIMEOUT,
                "unitId": constants.DEFAULT_UNIT_ID,
            },
            "registers": {
                "txControl": constants.TX_CONTROL,
                "rxControl": constants.RX_CONTROL,
                "status": constants.STATUS_REGISTER,
                "error": constants.ERROR_REGISTER,
                "frequency": constants.FREQUENCY_REGISTER,
                "destZoneBase": constants.RF_DEST_ZONE_BASE,
                "addrRamBase": constants.ADDR_RAM_BASE,
            },
            "limits": {
                "maxRouteEntries": constants.MAX_ADDR_ENTRIES,
                "maxDestinationZones": constants.MAX_DEST_ZONES,
                "defaultFrequency": constants.DEFAULT_FREQUENCY,
                "defaultRoute": list(constants.DEFAULT_ROUTE),
                "defaultZones": list(constants.DEFAULT_DESTINATION_ZONES),
            },
        },
    )


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
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "name": descriptor.name,
            "address": f"0x{block.start:04X}",
            "quantity": block.quantity,
            "values": None,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        values = client.read_registers(block.start, block.quantity)

    response["values"] = values
    return response


def command_write_block(args: argparse.Namespace) -> dict[str, Any]:
    descriptor = _resolve_descriptor(args.name)
    values = list(args.values)
    block = descriptor.block

    if len(values) != block.quantity:
        raise ValueError(
            f"Register block '{descriptor.name}' expects {block.quantity} value(s); received {len(values)}"
        )

    settings, unit_id = resolve_serial_settings(args)
    response = remember_response_data(
        args,
        {
            "port": settings.port,
            "unitId": unit_id,
            "name": descriptor.name,
            "address": f"0x{block.start:04X}",
            "values": values,
        },
    )
    with ModbusAudioClient(settings=settings, unit_id=unit_id) as client:
        if block.quantity == 1:
            client.write_register(block.start, values[0])
        else:
            client.write_registers(block.start, values)

    return response


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
    if args.command == "read-frequency":
        return command_read_frequency(args)
    if args.command == "set-frequency":
        return command_set_frequency(args)
    if args.command == "play-sequence":
        return command_play_sequence(args)
    if args.command == "read-nest-status":
        return command_read_nest_status(args)
    if args.command == "set-route":
        return command_set_route(args)
    if args.command == "set-zones":
        return command_set_zones(args)
    if args.command == "read-route":
        return command_read_route(args)
    if args.command == "read-alarms":
        return command_read_alarm_buffer(args)
    if args.command == "defaults":
        return command_defaults(args)
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


def output_error(
    command: str | None,
    message: str,
    *,
    error_type: str | None = None,
    data: dict[str, Any] | None = None,
) -> None:
    payload: dict[str, Any] = {"status": "error", "message": message}
    if command:
        payload["command"] = command
    if error_type:
        payload["errorType"] = error_type
    if data is not None:
        payload["data"] = data
    print(json.dumps(payload, ensure_ascii=False))
    raise SystemExit(1)


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    setattr(args, "_response_data", None)

    try:
        data = dispatch(args)
    except ModbusAudioError as exc:
        serial_context = gather_serial_context(args)
        command_data = getattr(args, "_response_data", None)
        enriched = merge_serial_payload(command_data, serial_context)
        output_error(args.command, str(exc), error_type="ModbusAudioError", data=enriched)
    except Exception as exc:
        serial_context = gather_serial_context(args)
        command_data = getattr(args, "_response_data", None)
        enriched = merge_serial_payload(command_data, serial_context)
        output_error(
            getattr(args, "command", None),
            str(exc),
            error_type=exc.__class__.__name__,
            data=enriched,
        )
    else:
        serial_context = gather_serial_context(args)
        enriched = merge_serial_payload(data, serial_context)
        output_success(args.command, enriched)


if __name__ == "__main__":  # pragma: no cover
    main()

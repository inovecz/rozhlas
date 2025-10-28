#!/usr/bin/env python3
"""
Utility scenarios for verifying Modbus audio + two‑way communication.

The script talks to the same ``modbus_audio`` helpers that the backend calls via
``python-client/modbus_control.py``.  It can:

* start/stop a broadcast (writes TxControl + destination zones),
* read the quick status registers,
* poll individual nests via the two-way route prefix,
* tail the alarm buffer (0x3000-0x3009) to confirm alarm propagation.
"""

from __future__ import annotations

import argparse
import json
import time
from typing import Iterable, Sequence

from modbus_audio import ModbusAudioClient, SerialSettings, constants


def parse_int_list(values: Iterable[str] | None) -> list[int]:
    result: list[int] = []
    if not values:
        return result
    for raw in values:
        if raw is None:
            continue
        for part in str(raw).split(","):
            part = part.strip()
            if not part:
                continue
            try:
                value = int(part, 0)
            except ValueError:
                raise argparse.ArgumentTypeError(f"Invalid integer: {part}")
            if value not in result:
                result.append(value)
    return result


def build_serial_settings(args: argparse.Namespace) -> SerialSettings:
    return SerialSettings(
        port=args.port or constants.DEFAULT_SERIAL_PORT,
        method=args.method or "rtu",
        baudrate=args.baudrate or constants.DEFAULT_BAUDRATE,
        parity=args.parity or constants.DEFAULT_PARITY,
        stopbits=args.stopbits or constants.DEFAULT_STOPBITS,
        bytesize=args.bytesize or constants.DEFAULT_BYTESIZE,
        timeout=args.timeout or constants.DEFAULT_TIMEOUT,
    )


def start_stream(client: ModbusAudioClient, args: argparse.Namespace) -> None:
    route = parse_int_list(args.route)
    zones = parse_int_list(args.zones) or constants.DEFAULT_DESTINATION_ZONES
    client.start_stream(route, zones, configure_route=args.update_route)
    print(f"✓ Stream started (route={route or '[no change]'}, zones={list(zones)})")


def stop_stream(client: ModbusAudioClient) -> None:
    client.stop_stream()
    print("✓ Stream stopped (TxControl reset)")


def read_status(client: ModbusAudioClient) -> None:
    info = client.get_device_info()
    tx_control = info.get("rx_control")
    status = info.get("status")
    error = info.get("error")
    zones = info.get("destination_zones")
    route = info.get("configured_route")
    payload = {
        "tx_control": tx_control,
        "status": status,
        "error": error,
        "route": route,
        "zones": zones,
    }
    print(json.dumps(payload, indent=2, ensure_ascii=False))


def poll_nests(client: ModbusAudioClient, addresses: Sequence[int], prefix: Sequence[int]) -> None:
    if not addresses:
        raise ValueError("Provide at least one --nest address.")
    for address in addresses:
        response = client.read_nest_status(address, prefix)
        print(f"[Nest {address}] {json.dumps(response, ensure_ascii=False)}")


def tail_alarm_buffer(client: ModbusAudioClient, interval: float) -> None:
    print("Listening for Modbus alarm frames (Ctrl+C to stop)...")
    try:
        while True:
            response = client.read_alarm_buffer()
            print(json.dumps(response, ensure_ascii=False))
            time.sleep(interval)
    except KeyboardInterrupt:
        print("\nStopped.")


def scenario_start_stop(client: ModbusAudioClient, args: argparse.Namespace) -> None:
    wait_seconds = max(1.0, float(args.wait))
    start_stream(client, args)
    print(f"… waiting {wait_seconds:.1f} s before stop")
    time.sleep(wait_seconds)
    stop_stream(client)


def main() -> None:
    parser = argparse.ArgumentParser(description="Modbus audio / two-way communication simulator")
    parser.add_argument("--port", help=f"Serial port (default: {constants.DEFAULT_SERIAL_PORT})")
    parser.add_argument("--method", help="Modbus method (rtu/ascii)")
    parser.add_argument("--baudrate", type=int, help=f"Serial baudrate (default: {constants.DEFAULT_BAUDRATE})")
    parser.add_argument("--parity", help=f"Serial parity (default: {constants.DEFAULT_PARITY})")
    parser.add_argument("--stopbits", type=int, help=f"Stop bits (default: {constants.DEFAULT_STOPBITS})")
    parser.add_argument("--bytesize", type=int, help=f"Byte size (default: {constants.DEFAULT_BYTESIZE})")
    parser.add_argument("--timeout", type=float, help=f"Modbus timeout (default: {constants.DEFAULT_TIMEOUT})")
    parser.add_argument("--unit-id", type=int, default=constants.DEFAULT_UNIT_ID, help="Modbus unit/slave id")

    sub = parser.add_subparsers(dest="command", required=True)

    start_cmd = sub.add_parser("start", help="Start broadcast via TxControl")
    start_cmd.add_argument("--route", nargs="*", help="Route addresses (decimal or 0x prefixed)")
    start_cmd.add_argument("--zones", nargs="*", help="Destination zones (defaults to firmware defaults)")
    start_cmd.add_argument("--update-route", action="store_true", help="Rewrite hop route registers before start")

    stop_cmd = sub.add_parser("stop", help="Stop broadcast (TxControl=1)")

    sub.add_parser("status", help="Dump selected status registers (TxControl, Status, Error, route, zones)")

    poll_cmd = sub.add_parser("poll-nests", help="Read two-way status for nests by address")
    poll_cmd.add_argument("--nest", nargs="+", required=True, help="Nest addresses (decimal or 0x prefixed)")
    poll_cmd.add_argument("--prefix", nargs="*", help="Route prefix (hub -> repeaters)")

    alarm_cmd = sub.add_parser("listen-alarms", help="Continuously read alarm buffer 0x3000-0x3009")
    alarm_cmd.add_argument("--interval", type=float, default=2.0, help="Polling interval in seconds (default 2)")

    scenario_cmd = sub.add_parser("scenario", help="Start → wait → stop convenience scenario")
    scenario_cmd.add_argument("--route", nargs="*", help="Route addresses (decimal or 0x prefixed)")
    scenario_cmd.add_argument("--zones", nargs="*", help="Destination zones")
    scenario_cmd.add_argument("--update-route", action="store_true", help="Rewrite hop route registers before start")
    scenario_cmd.add_argument("--wait", type=float, default=10.0, help="Seconds to wait before stop (default 10)")

    args = parser.parse_args()

    settings = build_serial_settings(args)
    with ModbusAudioClient(settings, unit_id=args.unit_id) as client:
        if args.command == "start":
            start_stream(client, args)
        elif args.command == "stop":
            stop_stream(client)
        elif args.command == "status":
            read_status(client)
        elif args.command == "poll-nests":
            nests = parse_int_list(args.nest)
            prefix = parse_int_list(args.prefix)
            poll_nests(client, nests, prefix)
        elif args.command == "listen-alarms":
            tail_alarm_buffer(client, args.interval)
        elif args.command == "scenario":
            scenario_start_stop(client, args)
        else:  # pragma: no cover
            raise RuntimeError(f"Unhandled command {args.command}")


if __name__ == "__main__":  # pragma: no cover
    main()

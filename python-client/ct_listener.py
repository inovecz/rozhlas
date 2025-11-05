#!/usr/bin/env python3
"""Utility helpers for interacting with the Control Tab serial link.

This CLI supplements the main daemon by providing ad-hoc helpers for:

* Sending TEXT frames (field updates / push notifications)
* Triggering a panel switch notification (panel_loaded acknowledgement)
* Composing multi-field updates with the correct CRC and inter-message delay

The serial settings (port, baudrate, etc.) default to the environment
variables documented in ``docs/requirements_docs/Protokol pro komunikaci za
pomoci Control Tabu.md`` and used by ``control_tab_listener.py``.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path
from typing import Iterable, List, Sequence, Tuple


ROOT_DIR = Path(__file__).resolve().parent
DAEMONS_DIR = ROOT_DIR / "daemons"
if str(DAEMONS_DIR) not in sys.path:
    sys.path.insert(0, str(DAEMONS_DIR))

# Import utilities shared with the long-running listener.
from control_tab_listener import (  # type: ignore  # noqa: E402
    ControlTabSerial,
    EVENT_TYPE_PANEL,
    build_ack_frame,
    build_text_frame,
    configure_logging,
    xor_crc,
    _env_flag,
)


DEFAULT_DELAY_MS = float(os.getenv("CONTROL_TAB_INTER_MESSAGE_DELAY_MS", "5"))


def build_panel_notification(screen: int, panel: int, status: int = 1) -> str:
    """Build an RX frame that mimics a panel_loaded acknowledgement."""
    return build_ack_frame(screen, panel, EVENT_TYPE_PANEL, status)


def build_panel_loaded_frame(screen: int, panel: int) -> str:
    """Build a request equivalent to ESP32 panel_loaded (for completeness)."""
    body = f"{screen}:{panel}:1="
    crc = xor_crc(body)
    return f"\n>>>:{screen}:{panel}:1=>>{crc}<<<\n"


def parse_fields_from_args(args: argparse.Namespace) -> List[Tuple[int, str]]:
    """Parse field specifications into an ordered list of updates."""
    fields: list[tuple[int, str]] = []

    def add(field_id: int, text: str) -> None:
        text = str(text)
        for index, (existing_id, _) in enumerate(fields):
            if existing_id == field_id:
                fields[index] = (field_id, text)
                break
        else:
            fields.append((field_id, text))

    if getattr(args, "fields_json", None):
        json_source = args.fields_json
        if isinstance(json_source, str) and json_source.startswith("@"):
            json_path = Path(json_source[1:]).expanduser()
            json_source = json_path.read_text(encoding="utf-8")
        mapping = json.loads(json_source)
        if not isinstance(mapping, dict):
            raise ValueError("fields_json must be a JSON object mapping field ids to text.")
        for key, value in mapping.items():
            add(int(key), value)

    if getattr(args, "fields_file", None):
        json_path = Path(args.fields_file).expanduser()
        mapping = json.loads(json_path.read_text(encoding="utf-8"))
        if not isinstance(mapping, dict):
            raise ValueError("fields_file must contain a JSON object mapping field ids to text.")
        for key, value in mapping.items():
            add(int(key), value)

    for spec in getattr(args, "field", []) or []:
        if "=" not in spec:
            raise ValueError(f"Invalid --field specification '{spec}'. Expected format FIELD_ID=TEXT.")
        field_str, text = spec.split("=", 1)
        add(int(field_str.strip()), text)

    if not fields:
        raise ValueError("At least one field update must be provided.")

    return fields


def serial_kwargs_from_args(args: argparse.Namespace) -> dict:
    return {
        "port": args.port,
        "baudrate": args.baudrate,
        "bytesize": args.bytesize,
        "parity": args.parity,
        "stopbits": args.stopbits,
        "timeout": args.timeout,
        "write_timeout": args.write_timeout,
    }


def send_frames(
    frames: Sequence[str],
    serial_kwargs: dict,
    delay_ms: float,
    dry_run: bool,
    logger,
) -> None:
    frames = list(frames)
    if frames == []:
        logger.info("No frames to send – exiting.")
        return

    if dry_run:
        for frame in frames:
            clean = frame.strip()
            logger.info("Dry-run frame: %s", clean)
            print(clean)
        return

    transport = ControlTabSerial(**serial_kwargs)
    try:
        transport.open()
    except Exception as exc:
        logger.error("Unable to open Control Tab serial port %s: %s", serial_kwargs["port"], exc)
        raise SystemExit(2) from exc

    try:
        interval = max(0.0, delay_ms) / 1000.0
        for index, frame in enumerate(frames):
            transport.write(frame)
            logger.info("TX frame: %s", frame.strip())
            if index < len(frames) - 1 and interval > 0:
                time.sleep(interval)
    finally:
        transport.close()


def run_send_fields(args: argparse.Namespace, logger) -> None:
    try:
        fields = parse_fields_from_args(args)
    except ValueError as exc:
        logger.error(str(exc))
        raise SystemExit(1) from exc

    frames: list[str] = []
    if getattr(args, "switch_panel", False):
        repeat = max(1, getattr(args, "panel_repeat", 1))
        status = int(getattr(args, "panel_status", 1))
        for _ in range(repeat):
            frames.append(build_panel_notification(args.screen, args.panel, status))

    for field_id, text in fields:
        frames.append(build_text_frame(field_id, text))

    serial_kwargs = serial_kwargs_from_args(args)
    send_frames(frames, serial_kwargs, args.delay_ms, args.dry_run, logger)


def run_send_text(args: argparse.Namespace, logger) -> None:
    simple_args = argparse.Namespace(
        port=args.port,
        baudrate=args.baudrate,
        bytesize=args.bytesize,
        parity=args.parity,
        stopbits=args.stopbits,
        timeout=args.timeout,
        write_timeout=args.write_timeout,
        delay_ms=args.delay_ms,
        dry_run=args.dry_run,
        screen=args.screen,
        panel=args.panel,
        switch_panel=args.switch_panel,
        panel_repeat=args.panel_repeat,
        panel_status=args.panel_status,
        fields_json=None,
        fields_file=None,
        field=[f"{args.field_id}={args.text}"],
    )
    run_send_fields(simple_args, logger)


def run_panel(args: argparse.Namespace, logger) -> None:
    frames: list[str] = []
    repeat = max(1, args.repeat)
    if args.plain:
        for _ in range(repeat):
            frames.append(build_panel_loaded_frame(args.screen, args.panel))
    else:
        for _ in range(repeat):
            frames.append(build_panel_notification(args.screen, args.panel, args.status))
    serial_kwargs = serial_kwargs_from_args(args)
    send_frames(frames, serial_kwargs, args.delay_ms, args.dry_run, logger)


def add_serial_arguments(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--port", default=os.getenv("CONTROL_TAB_SERIAL_PORT", "/dev/ttyUSB3"))
    parser.add_argument("--baudrate", type=int, default=int(os.getenv("CONTROL_TAB_SERIAL_BAUDRATE", "115200")))
    parser.add_argument("--bytesize", type=int, default=int(os.getenv("CONTROL_TAB_SERIAL_BYTESIZE", "8")))
    parser.add_argument("--parity", default=os.getenv("CONTROL_TAB_SERIAL_PARITY", "N"))
    parser.add_argument("--stopbits", type=int, default=int(os.getenv("CONTROL_TAB_SERIAL_STOPBITS", "1")))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("CONTROL_TAB_SERIAL_TIMEOUT", "0.2")))
    parser.add_argument("--write-timeout", type=float, default=float(os.getenv("CONTROL_TAB_SERIAL_WRITE_TIMEOUT", "1")))
    parser.add_argument("--delay-ms", type=float, default=DEFAULT_DELAY_MS)
    parser.add_argument("--dry-run", action="store_true", help="Log frames without opening the serial port.")
    parser.add_argument("--log-file", default=os.getenv("CONTROL_TAB_LOG_FILE"))
    parser.add_argument("--debug", action="store_true", help="Enable DEBUG logging for this command.")


def add_panel_options(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--screen", type=int, default=3)
    parser.add_argument("--panel", type=int, default=1)
    parser.add_argument("--switch-panel", action="store_true", help="Send panel notification before text frames.")
    parser.add_argument("--panel-repeat", type=int, default=1, help="How many times to repeat the panel notification.")
    parser.add_argument("--panel-status", type=int, choices=[0, 1], default=1, help="Status flag used for the panel notification (ACK/NACK).")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Control Tab utility helpers")
    add_serial_arguments(parser)

    subcommands = parser.add_subparsers(dest="command", required=True)

    send_fields_parser = subcommands.add_parser("send-fields", help="Send multiple text field updates (JSON or --field).")
    add_panel_options(send_fields_parser)
    send_fields_parser.add_argument("--fields-json", help="JSON object mapping field IDs to text. Prefix with @ to read from file.")
    send_fields_parser.add_argument("--fields-file", help="Path to JSON file mapping field IDs to text.")
    send_fields_parser.add_argument("--field", action="append", help="Field update specified as FIELD_ID=TEXT. Can be repeated.")

    send_text_parser = subcommands.add_parser("send-text", help="Send a single text field update.")
    add_panel_options(send_text_parser)
    send_text_parser.add_argument("--field-id", type=int, required=True)
    send_text_parser.add_argument("--text", required=True)

    panel_parser = subcommands.add_parser("panel", help="Send a panel notification or panel_loaded request.")
    panel_parser.add_argument("--screen", type=int, default=3)
    panel_parser.add_argument("--panel", type=int, default=1)
    panel_parser.add_argument("--status", type=int, choices=[0, 1], default=1, help="Status bit for ACK-style notification.")
    panel_parser.add_argument("--repeat", type=int, default=1, help="How many identical frames to send.")
    panel_parser.add_argument(
        "--plain",
        action="store_true",
        help="Send a panel_loaded-style frame instead of an ACK notification.",
    )

    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    debug = bool(args.debug or _env_flag("CONTROL_TAB_DEBUG", False))
    logger = configure_logging(args.log_file, debug)
    logger.debug("ct_listener utility invoked with args: %s", vars(args))

    command = args.command
    if command == "send-fields":
        run_send_fields(args, logger)
    elif command == "send-text":
        run_send_text(args, logger)
    elif command == "panel":
        run_panel(args, logger)
    else:
        parser.error(f"Unsupported command {command!r}")


if __name__ == "__main__":  # pragma: no cover
    main()

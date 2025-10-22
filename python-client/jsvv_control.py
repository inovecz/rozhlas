#!/usr/bin/env python3
"""Backend-facing CLI for interacting with the JSVV control layer."""

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

from jsvv import JSVVClient, JSVVError, SerialSettings, constants  # noqa: E402
from jsvv.assets import build_asset_list  # noqa: E402


def env_int(name: str, default: int) -> int:
    value = os.environ.get(name)
    if value is None:
        return default
    try:
        return int(value, 0)
    except ValueError as exc:
        raise ValueError(f"Invalid integer value for environment variable {name}: {value}") from exc


def env_float(name: str, default: float) -> float:
    value = os.environ.get(name)
    if value is None:
        return default
    try:
        return float(value)
    except ValueError as exc:
        raise ValueError(f"Invalid float value for environment variable {name}: {value}") from exc


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="JSVV control helpers exposed to the backend")
    parser.add_argument("--port", help="Serial port (default env/JSVV_PORT or library default)")
    parser.add_argument("--baudrate", type=int, help="Serial baudrate")
    parser.add_argument("--parity", help="Serial parity (N/E/O)")
    parser.add_argument("--stopbits", type=int, help="Serial stop bits")
    parser.add_argument("--bytesize", type=int, help="Serial byte size")
    parser.add_argument("--timeout", type=float, help="Serial read timeout")
    parser.add_argument("--audio-root", help="Override path to JSVV audio assets root directory")
    parser.add_argument("--unit-id", type=int, help="Reserved for future compatibility (ignored)")

    sub = parser.add_subparsers(dest="command", required=True)

    assets_cmd = sub.add_parser("list-assets", help="List available assets")
    assets_cmd.add_argument("--slot", type=int, help="Filter by slot number")
    assets_cmd.add_argument("--voice", help="Filter by voice (male/female)")
    assets_cmd.add_argument("--include-paths", action="store_true", help="Include absolute file paths")
    assets_cmd.add_argument("--category", choices=("verbal", "siren"), help="Limit to specific asset category")

    commands_cmd = sub.add_parser("list-commands", help="List documented JSVV command specs")
    commands_cmd.add_argument("--mid", help="Filter to a specific MID")

    defaults_cmd = sub.add_parser("defaults", help="Return default serial and protocol configuration")
    defaults_cmd.add_argument("--include-assets", action="store_true", help="Include asset directory metadata")

    parse_cmd = sub.add_parser("parse-frame", help="Parse a raw frame string into structured data")
    parse_cmd.add_argument("--frame", required=True, help="Raw frame string to parse")
    parse_cmd.add_argument("--skip-crc", action="store_true", help="Skip CRC validation during parsing")

    build_cmd = sub.add_parser("build-frame", help="Build a frame string from MID and parameters")
    build_cmd.add_argument("--mid", required=True, help="Message identifier (e.g. VERBAL)")
    build_cmd.add_argument("--params", nargs="*", help="Optional parameters")
    build_cmd.add_argument("--no-crc", action="store_true", help="Do not append CRC tokens")

    trigger_cmd = sub.add_parser("trigger", help="Build (and optionally send) a frame")
    trigger_cmd.add_argument("--mid", required=True, help="Message identifier")
    trigger_cmd.add_argument("--params", nargs="*", help="Optional parameters")
    trigger_cmd.add_argument("--no-crc", action="store_true", help="Do not append CRC")
    trigger_cmd.add_argument("--send", action="store_true", help="Send the frame via serial link")

    listen_cmd = sub.add_parser("listen", help="Listen for frames on the serial port")
    listen_cmd.add_argument("--timeout", type=float, default=5.0, help="Read timeout per frame")
    listen_cmd.add_argument("--max-frames", type=int, default=1, help="Maximum frames to capture before exiting")
    listen_cmd.add_argument("--network-id", type=int, default=1, help="Network ID for payload context")
    listen_cmd.add_argument("--vyc-id", type=int, default=1, help="VyC identifier for payload context")
    listen_cmd.add_argument("--kpps-address", default="0x0001", help="KPPS address for payload context")
    listen_cmd.add_argument("--operator-id", type=int, help="Optional operator id")
    listen_cmd.add_argument("--skip-crc", action="store_true", help="Skip CRC validation when receiving frames")
    listen_cmd.add_argument("--dedup-window", type=float, help="Override deduplication window seconds")
    listen_cmd.add_argument(
        "--until-timeout",
        action="store_true",
        help="Ignore timeout errors and exit cleanly when no data is received",
    )

    sequence_cmd = sub.add_parser("plan-sequence", help="Resolve assets for a planned verbal sequence")
    sequence_cmd.add_argument(
        "--sequence-json",
        help="JSON array describing sequence items ({\"slot\": int, \"voice\": str?, \"repeat\": int?})",
    )
    sequence_cmd.add_argument(
        "--sequence",
        nargs="*",
        help="Quick sequence tokens (slot[:voice][xrepeat]), e.g. 3:female 5x2",
    )
    sequence_cmd.add_argument("--zones", type=int, nargs="*", help="Destination zones associated with playback")
    sequence_cmd.add_argument("--priority", default="P2", help="Requested priority level")
    sequence_cmd.add_argument(
        "--hold-seconds",
        type=float,
        default=5.0,
        help="Suggested duration to keep Modbus TxControl active after playback",
    )

    return parser


def resolve_serial_settings(args: argparse.Namespace) -> SerialSettings:
    port = args.port or os.environ.get("JSVV_PORT") or constants.DEFAULT_SERIAL_PORT
    baudrate = args.baudrate if args.baudrate is not None else env_int("JSVV_BAUDRATE", constants.DEFAULT_BAUDRATE)
    parity = (args.parity or os.environ.get("JSVV_PARITY") or constants.DEFAULT_PARITY).upper()
    stopbits = args.stopbits if args.stopbits is not None else env_int("JSVV_STOPBITS", constants.DEFAULT_STOPBITS)
    bytesize = args.bytesize if args.bytesize is not None else env_int("JSVV_BYTESIZE", constants.DEFAULT_BYTESIZE)
    timeout = args.timeout if args.timeout is not None else env_float("JSVV_TIMEOUT", constants.DEFAULT_TIMEOUT)

    return SerialSettings(
        port=port,
        baudrate=baudrate,
        parity=parity,
        stopbits=stopbits,
        bytesize=bytesize,
        timeout=timeout,
    )


def resolve_audio_root(args: argparse.Namespace) -> Path:
    if args.audio_root:
        return Path(args.audio_root).expanduser().resolve()
    candidate = os.environ.get("JSVV_AUDIO_ROOT")
    if candidate:
        return Path(candidate).expanduser().resolve()
    return ROOT_DIR / "assets" / "jsvv"


def build_sequence_from_tokens(tokens: Iterable[str]) -> list[dict[str, Any]]:
    sequence: list[dict[str, Any]] = []
    for token in tokens:
        token = token.strip()
        if not token:
            continue
        repeat = 1
        if "x" in token:
            base, repeat_token = token.rsplit("x", 1)
            token = base
            try:
                repeat = max(1, int(repeat_token, 10))
            except ValueError as exc:
                raise ValueError(f"Invalid repeat suffix in sequence token '{token}x{repeat_token}'") from exc
        category = "verbal"
        voice_token: str | None = None
        parts = token.split(":")
        working = parts
        if parts:
            try:
                int(parts[0], 10)
            except ValueError:
                category = parts[0].lower()
                working = parts[1:]
        if not working:
            raise ValueError(f"Sequence token '{token}' is missing slot information")
        slot_token = working[0]
        if len(working) > 1:
            voice_token = working[1]
        try:
            slot = int(slot_token, 10)
        except ValueError as exc:
            raise ValueError(f"Invalid slot value '{slot_token}' in sequence token '{token}'") from exc
        entry: dict[str, Any] = {"slot": slot, "category": category}
        if voice_token and category != "siren":
            entry["voice"] = voice_token
        if voice_token and category == "siren":
            raise ValueError("Siren category does not accept voice parameter")
        if repeat > 1:
            entry["repeat"] = repeat
        sequence.append(entry)
    return sequence


def resolve_sequence(args: argparse.Namespace) -> list[dict[str, Any]]:
    if args.sequence_json:
        try:
            data = json.loads(args.sequence_json)
        except json.JSONDecodeError as exc:
            raise ValueError("Unable to decode sequence JSON payload") from exc
        if not isinstance(data, list):
            raise ValueError("Sequence JSON must be an array of objects")
        normalized: list[dict[str, Any]] = []
        for item in data:
            if not isinstance(item, dict):
                raise ValueError("Each sequence entry must be an object")
            if "slot" not in item:
                raise ValueError("Sequence entry missing 'slot'")
            entry = dict(item)
            entry.setdefault("category", "verbal")
            if entry["category"] not in {"verbal", "siren"}:
                raise ValueError("Sequence entry category must be 'verbal' or 'siren'")
            if entry["category"] == "siren" and "voice" in entry:
                raise ValueError("Siren category must not include a voice parameter")
            normalized.append(entry)
        return normalized
    if args.sequence:
        return build_sequence_from_tokens(args.sequence)
    raise ValueError("Sequence must be provided via --sequence-json or --sequence tokens")


def command_list_assets(args: argparse.Namespace) -> dict[str, Any]:
    root = resolve_audio_root(args)
    client = JSVVClient(SerialSettings(), audio_root=root)
    assets = []
    if args.category in (None, "verbal"):
        assets.extend(build_asset_list(client._verbal_index, "verbal"))  # noqa: SLF001
    if args.category in (None, "siren"):
        assets.extend(build_asset_list(client._siren_index, "siren"))  # noqa: SLF001

    rendered: list[dict[str, Any]] = []
    for asset in assets:
        if args.slot is not None and asset.slot != args.slot:
            continue
        if args.voice and asset.voice and asset.voice.lower() != args.voice.lower():
            continue
        info = {
            "slot": asset.slot,
            "category": asset.category,
            "voice": asset.voice,
            "filename": asset.path.name,
            "size": asset.size,
            "modified": asset.modified,
        }
        if args.include_paths:
            info["path"] = str(asset.path)
        rendered.append(info)
    return {
        "assetRoot": str(root),
        "count": len(rendered),
        "assets": rendered,
    }


def command_list_commands(args: argparse.Namespace) -> dict[str, Any]:
    specs = []
    for mid, spec in sorted(constants.COMMAND_SPECS.items()):
        if args.mid and mid.lower() != args.mid.lower():
            continue
        specs.append(
            {
                "mid": mid,
                "command": spec.command,
                "type": spec.type,
                "priority": spec.priority,
                "description": spec.description,
                "expectsResponse": spec.expects_response,
                "parameters": [
                    {
                        "name": param.name,
                        "optional": param.optional,
                        "rest": param.rest,
                        "description": param.description,
                    }
                    for param in spec.parameters
                ],
            }
        )
    return {
        "count": len(specs),
        "commands": specs,
    }


def command_defaults(args: argparse.Namespace) -> dict[str, Any]:
    audio_root = resolve_audio_root(args)
    payload: dict[str, Any] = {
        "serial": {
            "port": constants.DEFAULT_SERIAL_PORT,
            "baudrate": constants.DEFAULT_BAUDRATE,
            "parity": constants.DEFAULT_PARITY,
            "stopbits": constants.DEFAULT_STOPBITS,
            "bytesize": constants.DEFAULT_BYTESIZE,
            "timeout": constants.DEFAULT_TIMEOUT,
        },
        "dedupWindowSeconds": constants.DEFAULT_DEDUP_WINDOW_SECONDS,
        "audioAssetRoot": str(audio_root),
    }
    if args.include_assets:
        client = JSVVClient(SerialSettings(), audio_root=audio_root)
        verbal_assets = build_asset_list(client._verbal_index, "verbal")  # noqa: SLF001
        siren_assets = build_asset_list(client._siren_index, "siren")  # noqa: SLF001
        payload["assets"] = {
            "verbal": {
                "count": len(verbal_assets),
                "uniqueSlots": sorted({asset.slot for asset in verbal_assets}),
            },
            "siren": {
                "count": len(siren_assets),
                "uniqueSignals": sorted({asset.slot for asset in siren_assets}),
            },
        }
    return payload


def command_parse_frame(args: argparse.Namespace) -> dict[str, Any]:
    frame = JSVVClient.parse_frame(args.frame, validate_crc=not args.skip_crc)
    payload = {
        "mid": frame.mid,
        "params": list(frame.params),
        "raw": frame.raw,
        "crcOk": frame.crc_ok(),
        "providedCrc": frame.provided_crc,
        "calculatedCrc": frame.calculated_crc,
    }
    if frame.spec is not None:
        payload["command"] = frame.spec.command
        payload["type"] = frame.spec.type
        payload["priority"] = frame.spec.priority
    if frame.parsed_params:
        payload["parsed"] = frame.parsed_params
    return payload


def command_build_frame(args: argparse.Namespace) -> dict[str, Any]:
    params = args.params if args.params else []
    frame = JSVVClient.build_frame(args.mid, params, include_crc=not args.no_crc)
    return {
        "mid": args.mid,
        "params": params,
        "frame": frame.rstrip("\n"),
        "length": len(frame),
    }


def command_trigger(args: argparse.Namespace) -> dict[str, Any]:
    params = args.params if args.params else []
    frame = JSVVClient.build_frame(args.mid, params, include_crc=not args.no_crc)
    sent = False
    if args.send:
        settings = resolve_serial_settings(args)
        with JSVVClient(settings) as client:
            client.send_frame(args.mid, params, include_crc=not args.no_crc)
        sent = True
    return {
        "mid": args.mid,
        "params": params,
        "frame": frame.rstrip("\n"),
        "sent": sent,
    }


def command_listen(args: argparse.Namespace) -> dict[str, Any]:
    settings = resolve_serial_settings(args)
    dedup_window = args.dedup_window if args.dedup_window is not None else constants.DEFAULT_DEDUP_WINDOW_SECONDS
    captured: list[dict[str, Any]] = []
    with JSVVClient(settings=settings, dedup_window=dedup_window) as client:
        frames_captured = 0
        while args.max_frames <= 0 or frames_captured < args.max_frames:
            try:
                frame = client.receive_frame(timeout=args.timeout, validate_crc=not args.skip_crc)
            except JSVVError as exc:
                if args.until_timeout:
                    break
                raise
            payload = client.build_json_payload(
                frame,
                network_id=args.network_id,
                vyc_id=args.vyc_id,
                kpps_address=args.kpps_address,
                operator_id=args.operator_id,
            )
            duplicate = not client.validate_and_track(
                frame,
                network_id=args.network_id,
                vyc_id=args.vyc_id,
                kpps_address=args.kpps_address,
                operator_id=args.operator_id,
            )
            captured.append(
                {
                    "raw": frame.raw,
                    "payload": payload,
                    "crcOk": frame.crc_ok(),
                    "duplicate": duplicate,
                }
            )
            frames_captured += 1
    return {
        "frames": captured,
        "count": len(captured),
        "port": settings.port,
    }


def command_plan_sequence(args: argparse.Namespace) -> dict[str, Any]:
    sequence = resolve_sequence(args)
    root = resolve_audio_root(args)
    client = JSVVClient(SerialSettings(), audio_root=root)
    verbal_index = client._verbal_index  # noqa: SLF001
    siren_index = client._siren_index  # noqa: SLF001
    resolved_items: list[dict[str, Any]] = []
    total_entries = 0
    for item in sequence:
        slot = int(item["slot"])
        category = item.get("category", "verbal")
        voice = item.get("voice")
        repeat = max(1, int(item.get("repeat", 1)))
        normalized_voice = JSVVClient._normalize_voice(voice) if voice else None  # type: ignore[attr-defined]
        index = verbal_index if category != "siren" else siren_index
        path = None
        if category == "siren":
            key = (slot, "siren")
            path = index.get(key)
        else:
            key = (slot, normalized_voice) if normalized_voice else None
            if key and key in index:
                path = index[key]
            else:
                fallback_key = (slot, "male")
                if key != fallback_key and fallback_key in index:
                    path = index[fallback_key]
                else:
                    alt = next((p for (s, _v), p in index.items() if s == slot), None)
                    if alt is not None:
                        path = alt
        if path is None:
            raise JSVVError(f"No asset available for slot {slot} (voice={voice})")
        entry = {
            "slot": slot,
            "category": category,
            "voice": normalized_voice if category != "siren" else None,
            "repeat": repeat,
            "filename": path.name,
            "path": str(path),
            "size": path.stat().st_size if path.exists() else None,
        }
        entry.update({k: v for k, v in item.items() if k not in {"slot", "voice", "repeat"}})
        resolved_items.append(entry)
        total_entries += repeat
    return {
        "sequence": resolved_items,
        "originalLength": len(sequence),
        "expandedLength": total_entries,
        "zones": args.zones or [],
        "priority": args.priority,
        "holdSeconds": args.hold_seconds,
        "note": "Sequence planning only; playback and Modbus coordination handled by backend orchestrator.",
    }


def dispatch(args: argparse.Namespace) -> dict[str, Any]:
    if args.command == "list-assets":
        return command_list_assets(args)
    if args.command == "list-commands":
        return command_list_commands(args)
    if args.command == "defaults":
        return command_defaults(args)
    if args.command == "parse-frame":
        return command_parse_frame(args)
    if args.command == "build-frame":
        return command_build_frame(args)
    if args.command == "trigger":
        return command_trigger(args)
    if args.command == "listen":
        return command_listen(args)
    if args.command == "plan-sequence":
        return command_plan_sequence(args)
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
    except JSVVError as exc:
        output_error(args.command, str(exc), error_type="JSVVError")
    except Exception as exc:
        output_error(getattr(args, "command", None), str(exc), error_type=exc.__class__.__name__)
    else:
        output_success(args.command, data)


if __name__ == "__main__":  # pragma: no cover
    main()

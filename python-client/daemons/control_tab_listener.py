#!/usr/bin/env python3
"""Control Tab UART listener for ESP32-P4 touch panel.

Reads frames described in ``docs/requirements_docs/Protokol...Control Tabu.md``,
parses events (panel_loaded, button_pressed, text_field_request) and forwards
them to the Laravel backend. The backend response specifies whether the panel
should receive an ACK or TEXT payload. When pyserial is not available or the
serial port cannot be opened, the daemon falls back to a simulation mode that
emits synthetic events for development purposes.
"""

from __future__ import annotations

import argparse
import json
import os
import queue
import signal
import threading
import time
import uuid
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import Any, Optional

import requests

try:  # pragma: no cover - optional dependency
    import serial  # type: ignore
    from serial import Serial
except Exception:  # pragma: no cover - fallback when pyserial missing
    serial = None  # type: ignore
    Serial = object  # type: ignore


EVENT_TYPE_BUTTON = 2
EVENT_TYPE_TEXT = 3
EVENT_TYPE_PANEL = 1


def _env_flag(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


DEBUG = _env_flag("CONTROL_TAB_DEBUG", False)


def _debug_log(payload: dict[str, Any]) -> None:
    if not DEBUG:
        return
    print(json.dumps({"debug": payload}, ensure_ascii=False), flush=True)


@dataclass(slots=True)
class ControlTabFrame:
    raw: str
    screen: int
    panel: int
    event_type: int
    payload: str
    crc_provided: Optional[str]
    crc_calculated: Optional[str]
    crc_valid: bool

    def to_event_payload(self) -> dict[str, Any]:
        data: dict[str, Any] = {
            "screen": self.screen,
            "panel": self.panel,
            "eventType": self.event_type,
            "payload": self.payload,
            "raw": self.raw,
            "crc": {
                "provided": self.crc_provided,
                "calculated": self.crc_calculated,
                "valid": self.crc_valid,
            },
        }

        if self.event_type == EVENT_TYPE_BUTTON:
            data["buttonId"] = self._parse_int(self.payload)
        elif self.event_type == EVENT_TYPE_TEXT:
            data["fieldId"] = self._parse_int(self.payload.strip("?"))

        return data

    @staticmethod
    def _parse_int(value: str) -> Optional[int]:
        value = value.strip()
        if value == "":
            return None
        try:
            return int(value)
        except ValueError:
            return None


def xor_crc(data: str) -> str:
    crc = 0
    for byte in data.encode("utf-8"):
        crc ^= byte
    return f"{crc:02X}"


class BackendSink:
    def __init__(self, webhook_url: Optional[str], token: Optional[str], timeout: float) -> None:
        self._webhook_url = webhook_url
        self._token = token
        self._timeout = timeout

    def send(self, payload: dict[str, Any]) -> dict[str, Any]:
        if not self._webhook_url:
            print(json.dumps(payload, ensure_ascii=False), flush=True)
            return {"action": "ack", "ack": {"status": 1}}

        headers = {"Content-Type": "application/json"}
        if self._token:
            headers["Authorization"] = f"Bearer {self._token}"

        response = requests.post(
            self._webhook_url,
            headers=headers,
            data=json.dumps(payload).encode("utf-8"),
            timeout=self._timeout,
        )
        response.raise_for_status()
        try:
            return response.json()
        except ValueError:  # pragma: no cover - backend always returns JSON
            return {"action": "ack", "ack": {"status": 1}}


class ControlTabSerial:
    def __init__(
        self,
        port: str,
        baudrate: int,
        bytesize: int,
        parity: str,
        stopbits: int,
        timeout: float,
        write_timeout: float,
    ) -> None:
        self._port = port
        self._baudrate = baudrate
        self._bytesize = bytesize
        self._parity = parity
        self._stopbits = stopbits
        self._timeout = timeout
        self._write_timeout = write_timeout
        self._serial: Optional[Serial] = None

    def open(self) -> None:
        if serial is None:
            raise RuntimeError("pyserial is not installed")

        self._serial = serial.Serial(  # type: ignore[attr-defined]
            port=self._port,
            baudrate=self._baudrate,
            bytesize=self._bytesize,
            parity=self._parity,
            stopbits=self._stopbits,
            timeout=self._timeout,
            write_timeout=self._write_timeout,
        )

    def close(self) -> None:
        if self._serial and self._serial.is_open:
            self._serial.close()
        self._serial = None

    def readline(self) -> Optional[str]:
        if self._serial is None or not self._serial.is_open:
            return None
        try:
            data = self._serial.readline()
        except Exception:
            return None
        if not data:
            return None
        return data.decode("utf-8", errors="ignore")

    def write(self, payload: str) -> None:
        if self._serial is None or not self._serial.is_open:
            return
        self._serial.write(payload.encode("utf-8"))
        self._serial.flush()


class ControlTabListener:
    def __init__(
        self,
        sink: BackendSink,
        transport: Optional[ControlTabSerial],
        poll_interval: float,
        graceful_timeout: float,
        retry_backoff: float,
    ) -> None:
        self._sink = sink
        self._transport = transport
        self._poll_interval = max(0.01, poll_interval)
        self._graceful_timeout = graceful_timeout
        self._retry_backoff = max(0.05, retry_backoff / 1000)

        self._queue: queue.Queue[tuple[ControlTabFrame, dict[str, Any]]] = queue.Queue()
        self._stop_event = threading.Event()
        self._dispatcher = threading.Thread(target=self._dispatch_loop, daemon=True)
        self._simulation = transport is None
        self._buffer = ""

    def start(self) -> None:
        self._dispatcher.start()

        if self._simulation:
            self._simulate_events()
            return

        assert self._transport is not None
        try:
            self._transport.open()
        except Exception as exc:
            print(json.dumps({"error": f"Failed to open Control Tab serial port: {exc}"}), flush=True)
            self._simulate_events()
            return

        try:
            while not self._stop_event.is_set():
                line = self._transport.readline()
                if line is None:
                    time.sleep(self._poll_interval)
                    continue
                self._ingest(line)
        finally:
            self._transport.close()

    def stop(self) -> None:
        self._stop_event.set()
        self._dispatcher.join(timeout=self._graceful_timeout)

    def _dispatch_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                frame, payload = self._queue.get(timeout=0.2)
            except queue.Empty:
                continue

            try:
                response = self._sink.send(payload)
            except Exception as exc:  # pragma: no cover - protects runtime
                print(json.dumps({"error": str(exc), "payload": payload}), flush=True)
                time.sleep(self._retry_backoff)
                continue

            self._handle_response(frame, response)

    def _ingest(self, chunk: str) -> None:
        data = chunk
        if not data:
            return
        self._buffer += data
        while True:
            start = self._buffer.find("<<<")
            if start == -1:
                # keep buffer from growing uncontrollably; retain last few characters in case of partial frame
                if len(self._buffer) > 128:
                    self._buffer = self._buffer[-128:]
                break
            if start > 0:
                noise = self._buffer[:start]
                if noise.strip():
                    _debug_log({"noise": noise})
                self._buffer = self._buffer[start:]

            if len(self._buffer) < 7:
                # not enough data yet for even the smallest frame
                break

            end = self._buffer.find("<<<", 3)
            if end == -1:
                # wait for the rest of the frame
                break

            frame_str = self._buffer[: end + 3]
            remainder = self._buffer[end + 3 :]
            # When frames end with newline, keep the remainder (including newline) for the next iteration
            self._buffer = remainder

            frame_str = frame_str.strip()
            if not frame_str:
                continue

            _debug_log({"incoming_raw": frame_str})
            frame = self._parse_frame(frame_str)
            if frame is None:
                _debug_log({"dropped": frame_str})
                continue
            payload = self._build_event_payload(frame)
            _debug_log({"parsed": payload})
            self._queue.put((frame, payload))

    def _handle_response(self, frame: ControlTabFrame, response: dict[str, Any]) -> None:
        action = response.get("action", "ack")
        if action == "ack":
            ack = response.get("ack", {})
            status = int(bool(ack.get("status", True)))
            message = self._build_ack(frame, status)
            self._write(message)
        elif action == "text":
            text_payload = response.get("text", {})
            field_id = text_payload.get("fieldId")
            text = text_payload.get("text", "")
            if field_id is None and frame.event_type == EVENT_TYPE_TEXT:
                field_id = ControlTabFrame._parse_int(frame.payload.strip("?"))
            message = self._build_text(field_id or 0, str(text))
            self._write(message)
        elif action == "error":
            message = self._build_ack(frame, 0)
            self._write(message)

    def _write(self, message: str) -> None:
        if self._simulation or self._transport is None:
            print(json.dumps({"outgoing": message.strip()}), flush=True)
            return
        self._transport.write(message)

    def _parse_frame(self, line: str) -> Optional[ControlTabFrame]:
        line = line.strip()
        if not line:
            return None
        if not line.startswith("<<<:") or not line.endswith("<<<"):
            _debug_log({"invalid_prefix": line})
            return None

        content = line[4:-3]  # remove leading "<<<:" and trailing "<<<"
        if ">>" not in content:
            _debug_log({"missing_crc_marker": line})
            return None
        body, crc_segment = content.split(">>", 1)
        crc_provided = crc_segment if crc_segment else None

        parts = body.split("=", 1)
        header = parts[0]
        payload = parts[1] if len(parts) > 1 else ""
        header_tokens = header.split(":")
        if len(header_tokens) != 3:
            _debug_log({"invalid_header": line})
            return None

        try:
            screen = int(header_tokens[0])
            panel = int(header_tokens[1])
            event_type = int(header_tokens[2])
        except ValueError:
            _debug_log({"non_int_header": line})
            return None

        crc_calculated = xor_crc(f"{screen}:{panel}:{event_type}={payload}")
        crc_valid = crc_provided is None or crc_provided.upper() == crc_calculated
        if not crc_valid:
            _debug_log(
                {
                    "crc_mismatch": {
                        "line": line,
                        "calculated": crc_calculated,
                        "provided": crc_provided,
                    }
                }
            )

        return ControlTabFrame(
            raw=line,
            screen=screen,
            panel=panel,
            event_type=event_type,
            payload=payload,
            crc_provided=crc_provided.upper() if crc_provided else None,
            crc_calculated=crc_calculated,
            crc_valid=crc_valid,
        )

    def _build_event_payload(self, frame: ControlTabFrame) -> dict[str, Any]:
        payload = frame.to_event_payload()
        payload["type"] = {
            EVENT_TYPE_PANEL: "panel_loaded",
            EVENT_TYPE_BUTTON: "button_pressed",
            EVENT_TYPE_TEXT: "text_field_request",
        }.get(frame.event_type, "unknown")
        payload["timestamp"] = time.time()
        payload["sessionId"] = uuid.uuid4().hex
        return payload

    def _build_ack(self, frame: ControlTabFrame, status: int) -> str:
        body = f"{frame.screen}:{frame.panel}:{frame.event_type}={status}"
        crc = xor_crc(body)
        return f"\n>>>:{frame.screen}:{frame.panel}:{frame.event_type}={status}>>{crc}<<<\n"

    def _build_text(self, field_id: int, text: str) -> str:
        safe_text = text.replace('"', "'")
        body = f'TEXT:{field_id}:"{safe_text}"'
        crc = xor_crc(body)
        return f'\n>>>TEXT:{field_id}:"{safe_text}">>{crc}<<<\n'

    def _simulate_events(self) -> None:
        frames = [
            ControlTabFrame(
                raw='<<<:1:1:2=1>>00<<<',
                screen=1,
                panel=1,
                event_type=EVENT_TYPE_BUTTON,
                payload="1",
                crc_provided="00",
                crc_calculated="00",
                crc_valid=True,
            ),
            ControlTabFrame(
                raw='<<<:1:1:3=?1?>>7E<<<',
                screen=1,
                panel=1,
                event_type=EVENT_TYPE_TEXT,
                payload="?1?",
                crc_provided="7E",
                crc_calculated="7E",
                crc_valid=True,
            ),
        ]
        for frame in frames:
            payload = self._build_event_payload(frame)
            self._queue.put((frame, payload))
            time.sleep(1.0)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Control Tab UART listener")
    parser.add_argument("--webhook", default=os.getenv("CONTROL_TAB_WEBHOOK"))
    parser.add_argument("--token", default=os.getenv("CONTROL_TAB_TOKEN"))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("CONTROL_TAB_TIMEOUT", "5")))
    parser.add_argument("--port", default=os.getenv("CONTROL_TAB_SERIAL_PORT", "/dev/ttyUSB3"))
    parser.add_argument("--baudrate", type=int, default=int(os.getenv("CONTROL_TAB_SERIAL_BAUDRATE", "115200")))
    parser.add_argument("--bytesize", type=int, default=int(os.getenv("CONTROL_TAB_SERIAL_BYTESIZE", "8")))
    parser.add_argument("--parity", default=os.getenv("CONTROL_TAB_SERIAL_PARITY", "N"))
    parser.add_argument("--stopbits", type=int, default=int(os.getenv("CONTROL_TAB_SERIAL_STOPBITS", "1")))
    parser.add_argument("--timeout-serial", type=float, default=float(os.getenv("CONTROL_TAB_SERIAL_TIMEOUT", "0.2")))
    parser.add_argument("--write-timeout", type=float, default=float(os.getenv("CONTROL_TAB_SERIAL_WRITE_TIMEOUT", "1")))
    parser.add_argument("--poll", type=float, default=float(os.getenv("CONTROL_TAB_POLL_INTERVAL", "0.05")))
    parser.add_argument("--graceful", type=float, default=float(os.getenv("CONTROL_TAB_GRACEFUL_TIMEOUT", "5")))
    parser.add_argument("--retry-backoff", type=int, default=int(os.getenv("CONTROL_TAB_RETRY_BACKOFF_MS", "250")))
    parser.add_argument("--simulate", action="store_true", help="Run without serial port (emit demo events)")
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    sink = BackendSink(args.webhook, args.token, args.timeout)

    transport: Optional[ControlTabSerial]
    if args.simulate:
        transport = None
    else:
        transport = ControlTabSerial(
            port=args.port,
            baudrate=args.baudrate,
            bytesize=args.bytesize,
            parity=args.parity,
            stopbits=args.stopbits,
            timeout=args.timeout_serial,
            write_timeout=args.write_timeout,
        )

    listener = ControlTabListener(
        sink=sink,
        transport=transport,
        poll_interval=args.poll,
        graceful_timeout=args.graceful,
        retry_backoff=float(args.retry_backoff),
    )

    def handle_signal(signum, _frame):  # noqa: ANN001
        print(json.dumps({"signal": signum, "note": "Shutting down"}), flush=True)
        listener.stop()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    listener.start()


if __name__ == "__main__":  # pragma: no cover
    main()

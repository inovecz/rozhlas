#!/usr/bin/env python3
"""GSM module listener for Waveshare SIM7600G-H-PCIE.

The daemon monitors the modem over an AT serial interface, normalises call
events (ringing/accepted/finished/error) and forwards them to the Laravel
backend through an HTTP webhook. The backend responds with the desired action
(`answer`, `reject`, `hangup`, `ack`), enabling auto-answer for whitelisted
callers and controlled hang-up when broadcasts end.

If `pyserial` is unavailable or the modem cannot be opened, the daemon falls
back to a simulation mode to keep development/testing workflows functional.
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
from enum import Enum
from pathlib import Path
from typing import Any, Optional

import requests

try:  # pragma: no cover - optional dependency
    import serial  # type: ignore
    from serial import Serial
except Exception:  # pragma: no cover - graceful fallback
    serial = None  # type: ignore
    Serial = object  # type: ignore


class CallState(Enum):
    RINGING = "ringing"
    ACCEPTED = "accepted"
    REJECTED = "rejected"
    FINISHED = "finished"
    ERROR = "error"


@dataclass(slots=True)
class CallEvent:
    state: CallState
    caller: str
    session_id: str
    timestamp: float
    metadata: dict[str, Any]

    def to_payload(self) -> dict[str, Any]:
        payload = asdict(self)
        payload["state"] = self.state.value
        return payload


class BackendSink:
    def __init__(self, webhook_url: str | None, auth_token: str | None, timeout: float) -> None:
        self._webhook_url = webhook_url
        self._auth_token = auth_token
        self._timeout = timeout

    def send(self, event: CallEvent) -> dict[str, Any]:
        payload = event.to_payload()
        if not self._webhook_url:
            print(json.dumps(payload, ensure_ascii=False), flush=True)
            return {"status": "simulated"}

        headers = {"Content-Type": "application/json"}
        if self._auth_token:
            headers["Authorization"] = f"Bearer {self._auth_token}"

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
            return {"status": "ok"}


class Sim7600ATClient:
    def __init__(
        self,
        port: str,
        baudrate: int,
        bytesize: int,
        parity: str,
        stopbits: int,
        timeout: float,
        write_timeout: float,
        sim_pin: str | None = None,
    ) -> None:
        self._port = port
        self._baudrate = baudrate
        self._bytesize = bytesize
        self._parity = parity
        self._stopbits = stopbits
        self._timeout = timeout
        self._write_timeout = write_timeout
        self._serial: Optional[Serial] = None
        self._sim_pin = sim_pin.strip() if sim_pin and sim_pin.strip() else None

    def open(self) -> None:
        if serial is None:
            raise RuntimeError("pyserial is not installed; cannot access GSM modem")

        self._serial = serial.Serial(  # type: ignore[attr-defined]
            port=self._port,
            baudrate=self._baudrate,
            bytesize=self._bytesize,
            parity=self._parity,
            stopbits=self._stopbits,
            timeout=self._timeout,
            write_timeout=self._write_timeout,
        )

        self._send_command("AT")
        self._send_command("ATE0")  # disable echo
        self._send_command("AT+CMEE=2")  # verbose error codes
        self._send_command("AT+CLIP=1")  # caller ID notifications
        self._send_command("AT+COLP=1")  # connected line identification
        self._send_command("AT+CLCC=1")  # call list notifications
        self._ensure_sim_ready()

    def close(self) -> None:
        if self._serial and self._serial.is_open:
            self._serial.close()
        self._serial = None

    def readline(self) -> Optional[str]:
        if self._serial is None or not self._serial.is_open:
            return None
        try:
            raw = self._serial.readline()
        except Exception:
            return None
        if not raw:
            return None
        return raw.decode("utf-8", errors="ignore").strip()

    def answer(self) -> bool:
        ok, _ = self._send_command("ATA", expect_response=True)
        return ok

    def hangup(self) -> bool:
        ok, _ = self._send_command("ATH", expect_response=True)
        return ok

    def signal_quality(self) -> Optional[int]:
        ok, response = self._send_command("AT+CSQ", expect_response=True)
        if not ok or response is None:
            return None
        for line in response:
            if line.startswith("+CSQ:"):
                try:
                    value = int(line.split(":")[1].split(",")[0].strip())
                    if value == 99:
                        return None
                    return value
                except (ValueError, IndexError):
                    return None
        return None

    def _send_command(self, command: str, expect_response: bool = False) -> tuple[bool, Optional[list[str]]]:
        if self._serial is None or not self._serial.is_open:
            return False, None
        try:
            self._serial.write((command + "\r").encode("utf-8"))
            self._serial.flush()
        except Exception:
            return False, None

        if not expect_response:
            return True, None

        lines: list[str] = []
        deadline = time.time() + 2.0
        while time.time() < deadline:
            line = self.readline()
            if line is None:
                continue
            if line == "":
                continue
            if line in {"AT", command}:
                continue
            lines.append(line)
            if line in {"OK", "ERROR"}:
                break
        ok = any(line == "OK" for line in lines)
        return ok, lines if lines else None

    def _query_sim_status(self) -> Optional[str]:
        ok, response = self._send_command("AT+CPIN?", expect_response=True)
        if not ok or response is None:
            return None
        for line in response:
            if line.startswith("+CPIN:"):
                status = line.split(":", 1)[1].strip().strip('"')
                return status.upper()
        return None

    def _ensure_sim_ready(self) -> None:
        if self._serial is None or not self._serial.is_open:
            return

        status = self._query_sim_status()
        if status in {None, "READY"}:
            return

        if status == "SIM PIN":
            if self._sim_pin is None:
                raise RuntimeError("SIM requires PIN but GSM_SIM_PIN is not configured.")
            ok, _ = self._send_command(f'AT+CPIN="{self._sim_pin}"', expect_response=True)
            if not ok:
                raise RuntimeError("Submitting SIM PIN failed (AT+CPIN returned ERROR).")

            for _ in range(10):
                time.sleep(1.0)
                status = self._query_sim_status()
                if status == "READY":
                    return
            raise RuntimeError("SIM PIN sent but modem did not report READY state.")

        if status == "SIM PUK":
            raise RuntimeError("SIM requires PUK code. Unlock the card before starting the listener.")

        raise RuntimeError(f"SIM not ready (status={status}).")


class GSMListener:
    def __init__(
        self,
        sink: BackendSink,
        client: Optional[Sim7600ATClient],
        poll_interval: float,
        graceful_timeout: float,
        signal_interval: float,
        auto_answer_delay_ms: int,
        max_ring_attempts: int,
    ) -> None:
        self._sink = sink
        self._client = client
        self._poll_interval = max(0.05, poll_interval)
        self._graceful_timeout = graceful_timeout
        self._signal_interval = max(5.0, signal_interval)
        self._auto_answer_delay_ms = auto_answer_delay_ms
        self._max_ring_attempts = max(1, max_ring_attempts)

        self._queue: queue.Queue[tuple[CallEvent, bool]] = queue.Queue()
        self._stop_event = threading.Event()
        self._dispatcher = threading.Thread(target=self._dispatch_loop, daemon=True)

        self._current_session: Optional[dict[str, Any]] = None
        self._last_signal_check = 0.0
        self._ring_count = 0

        self._simulation = client is None

    def start(self) -> None:
        self._dispatcher.start()

        if self._simulation:
            self._simulate_events()
            return

        assert self._client is not None
        try:
            self._client.open()
        except Exception as exc:
            print(json.dumps({"error": f"Failed to open modem: {exc}"}), flush=True)
            self._simulate_events()
            return

        try:
            while not self._stop_event.is_set():
                line = self._client.readline()
                if line is None:
                    self._maybe_poll_signal()
                    continue
                self._handle_modem_line(line)
        finally:
            self._client.close()

    def stop(self) -> None:
        self._stop_event.set()
        self._dispatcher.join(timeout=self._graceful_timeout)

    def _dispatch_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                event, expect_response = self._queue.get(timeout=0.2)
            except queue.Empty:
                continue
            try:
                response = self._sink.send(event) if expect_response else {}
                self._handle_backend_response(event, response)
            except Exception as exc:  # pragma: no cover - runtime safeguard
                print(json.dumps({
                    "error": str(exc),
                    "event": event.to_payload(),
                }), flush=True)

    def _handle_backend_response(self, event: CallEvent, response: dict[str, Any]) -> None:
        action = response.get("action")
        if event.state == CallState.RINGING:
            if action == "answer":
                self._attempt_answer()
            elif action == "reject":
                self._hangup()
        if event.state == CallState.ACCEPTED and action == "hangup":
            self._hangup()

    # ------------------------------------------------------------------
    # Modem handling
    # ------------------------------------------------------------------
    def _handle_modem_line(self, line: str) -> None:
        line = line.strip()
        if not line:
            return

        if line == "RING":
            self._ring_count += 1
            if self._ring_count > self._max_ring_attempts:
                self._hangup()
                self._ring_count = 0
                return
            self._emit_event(CallState.RINGING, expect_response=True)
            return

        if line.startswith("+CLIP:"):
            caller = self._parse_clip(line)
            if caller:
                self._ensure_session()
                self._current_session["caller"] = caller
            return

        if line in {"NO CARRIER", "BUSY", "NO ANSWER"}:
            self._emit_event(CallState.FINISHED)
            self._reset_session()
            return

        if "VOICE CALL: END" in line or "VOICE CALL: HANGUP" in line:
            self._emit_event(CallState.FINISHED)
            self._reset_session()
            return

        if line.startswith("+CIEV: \"CALL\","):
            if ",1" in line:
                # Call active
                self._emit_event(CallState.ACCEPTED)
            elif ",0" in line:
                self._emit_event(CallState.FINISHED)
                self._reset_session()
            return

    def _attempt_answer(self) -> None:
        if self._client is None or self._simulation:
            self._emit_event(CallState.ACCEPTED)
            return

        if self._current_session is None:
            self._ensure_session()

        time.sleep(self._auto_answer_delay_ms / 1000.0)
        success = self._client.answer()
        if success:
            self._emit_event(CallState.ACCEPTED)
        else:
            self._emit_event(CallState.ERROR)

    def _hangup(self) -> None:
        if self._client is not None and not self._simulation:
            self._client.hangup()
        self._emit_event(CallState.REJECTED)
        self._reset_session()

    def _maybe_poll_signal(self) -> None:
        if self._client is None or self._simulation:
            return
        if (time.time() - self._last_signal_check) < self._signal_interval:
            return
        self._last_signal_check = time.time()
        quality = self._client.signal_quality()
        if quality is not None and self._current_session is not None:
            self._current_session.setdefault("metadata", {})["signal_quality"] = quality

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------
    def _ensure_session(self) -> None:
        if self._current_session is None:
            self._current_session = {
                "id": uuid.uuid4().hex,
                "caller": "",
                "metadata": {},
                "started_at": time.time(),
            }

    def _reset_session(self) -> None:
        self._current_session = None
        self._ring_count = 0

    def _current_caller(self) -> str:
        if self._current_session and self._current_session.get("caller"):
            return str(self._current_session["caller"])
        return ""

    def _current_metadata(self) -> dict[str, Any]:
        if self._current_session is None:
            return {}
        metadata = dict(self._current_session.get("metadata", {}))
        if self._current_session.get("started_at"):
            metadata.setdefault("started_at", self._current_session["started_at"])
        return metadata

    def _parse_clip(self, line: str) -> Optional[str]:
        try:
            section = line.split(":", 1)[1]
            number = section.split(",")[0].strip().strip('"')
            return number
        except IndexError:
            return None

    def _emit_event(self, state: CallState, expect_response: bool = False) -> None:
        self._ensure_session()
        assert self._current_session is not None
        session_id = self._current_session["id"]
        caller = self._current_caller()
        metadata = self._current_metadata()
        if state == CallState.FINISHED and self._current_session.get("started_at"):
            metadata["duration_seconds"] = max(0.0, time.time() - float(self._current_session["started_at"]))

        event = CallEvent(
            state=state,
            caller=caller,
            session_id=session_id,
            timestamp=time.time(),
            metadata=metadata,
        )
        self._queue.put((event, expect_response))

    def _simulate_events(self) -> None:
        session_id = uuid.uuid4().hex
        now = time.time()
        states = [
            CallEvent(CallState.RINGING, "+420123456789", session_id, now, {"signal": 20}),
            CallEvent(CallState.ACCEPTED, "+420123456789", session_id, now + 2, {}),
            CallEvent(CallState.FINISHED, "+420123456789", session_id, now + 15, {"duration_seconds": 13}),
        ]
        for event in states:
            if self._stop_event.is_set():
                break
            self._queue.put((event, True))
            time.sleep(1.0)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="GSM SIM7600G listener")
    parser.add_argument("--webhook", default=os.getenv("GSM_WEBHOOK"))
    parser.add_argument("--token", default=os.getenv("GSM_TOKEN"))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("GSM_WEBHOOK_TIMEOUT", "5")))
    parser.add_argument("--port", default=os.getenv("GSM_SERIAL_PORT", "/dev/ttyUSB2"))
    parser.add_argument("--baudrate", type=int, default=int(os.getenv("GSM_SERIAL_BAUDRATE", "115200")))
    parser.add_argument("--bytesize", type=int, default=int(os.getenv("GSM_SERIAL_BYTESIZE", "8")))
    parser.add_argument("--parity", default=os.getenv("GSM_SERIAL_PARITY", "N"))
    parser.add_argument("--stopbits", type=int, default=int(os.getenv("GSM_SERIAL_STOPBITS", "1")))
    parser.add_argument("--timeout-serial", type=float, default=float(os.getenv("GSM_SERIAL_TIMEOUT", "0.5")))
    parser.add_argument("--write-timeout", type=float, default=float(os.getenv("GSM_SERIAL_WRITE_TIMEOUT", "1")))
    parser.add_argument("--poll", type=float, default=float(os.getenv("GSM_POLL_INTERVAL", "0.2")))
    parser.add_argument("--graceful", type=float, default=float(os.getenv("GSM_GRACEFUL_TIMEOUT", "5")))
    parser.add_argument("--signal-interval", type=float, default=float(os.getenv("GSM_SIGNAL_INTERVAL", "30")))
    parser.add_argument("--answer-delay", type=int, default=int(os.getenv("GSM_AUTO_ANSWER_DELAY_MS", "1000")))
    parser.add_argument("--max-ring", type=int, default=int(os.getenv("GSM_MAX_RING_ATTEMPTS", "6")))
    parser.add_argument("--simulate", action="store_true", help="Force simulation mode (ignore modem)")
    parser.add_argument("--sim-pin", default=os.getenv("GSM_SIM_PIN"))
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    sink = BackendSink(args.webhook, args.token, args.timeout)

    client: Optional[Sim7600ATClient]
    if args.simulate:
        client = None
    else:
        client = Sim7600ATClient(
            port=args.port,
            baudrate=args.baudrate,
            bytesize=args.bytesize,
            parity=args.parity,
            stopbits=args.stopbits,
            timeout=args.timeout_serial,
            write_timeout=args.write_timeout,
            sim_pin=args.sim_pin,
        )

    listener = GSMListener(
        sink=sink,
        client=client,
        poll_interval=args.poll,
        graceful_timeout=args.graceful,
        signal_interval=args.signal_interval,
        auto_answer_delay_ms=args.answer_delay,
        max_ring_attempts=args.max_ring,
    )

    def handle_signal(signum, _frame):  # noqa: ANN001
        print(json.dumps({"signal": signum, "note": "Shutting down"}), flush=True)
        listener.stop()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    listener.start()


if __name__ == "__main__":  # pragma: no cover
    main()

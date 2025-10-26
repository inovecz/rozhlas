#!/usr/bin/env python3
"""Run end-to-end smoke tests for JSVV, GSM, FM radio, and Control Tab.

Each subsystem is triggered sequentially via the public API. The script prints
progress and summarises pass/fail status for quick validation on a running
device.
"""

from __future__ import annotations

import argparse
import importlib.util
import sys
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Optional

import requests

try:  # pragma: no cover - optional dependency
    import serial  # type: ignore
except Exception:  # pragma: no cover
    serial = None  # type: ignore


@dataclass
class TestResult:
    name: str
    success: bool
    detail: str


class SmokeTester:
    def __init__(
        self,
        base_url: str,
        token: str | None,
        timeout: float,
        jsvv_serial: Optional[dict[str, Any]] = None,
        control_tab_serial: Optional[dict[str, Any]] = None,
        gsm_serial: Optional[dict[str, Any]] = None,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.jsvv_serial = jsvv_serial
        self.control_tab_serial = control_tab_serial
        self.gsm_serial = gsm_serial

    # ------------------------------------------------------------------ helpers
    def _headers(self) -> dict[str, str]:
        headers = {"Content-Type": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        return headers

    def _post(self, path: str, json_payload: dict[str, Any]) -> requests.Response:
        url = f"{self.base_url}{path}"
        response = requests.post(url, json=json_payload, headers=self._headers(), timeout=self.timeout)
        response.raise_for_status()
        return response

    def _log(self, message: str) -> None:
        print(message, flush=True)

    # ------------------------------------------------------------------ tests
    def test_jsvv(self) -> TestResult:
        if self.jsvv_serial:
            serial_result = self.test_jsvv_serial()
            if serial_result is not None:
                return serial_result

        self._log("▶  JSVV: sending synthetic SIREN_SIGNAL frame …")
        timestamp = int(time.time())
        payload = {
            "raw": f"SIREN 1 {timestamp}",
            "payload": {
                "networkId": 1,
                "vycId": 1,
                "kppsAddress": "0x0001",
                "operatorId": 42,
                "type": "ACTIVATION",
                "command": "SIREN_SIGNAL",
                "params": {"signalType": 1},
                "priority": "P2",
                "timestamp": timestamp,
                "rawMessage": f"SIREN 1 {timestamp}",
            },
        }

        try:
            response = self._post("/jsvv/events", payload)
            data = response.json()
            status = data.get("status", "accepted")
            success = status in {"accepted", "ok"}
            return TestResult("JSVV", success, f"status={status}")
        except Exception as exc:
            return TestResult("JSVV", False, f"error={exc}")

    def test_jsvv_serial(self) -> Optional[TestResult]:
        try:
            module_path = Path(__file__).resolve().parents[1] / 'src' / 'jsvv' / 'client.py'
            spec = importlib.util.spec_from_file_location('jsvv_client_module', module_path)
            if not spec or not spec.loader:
                raise ImportError("Unable to load JSVV client")
            jsvv_client_module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(jsvv_client_module)
            JSVVClient = getattr(jsvv_client_module, 'JSVVClient')
            SerialSettings = getattr(jsvv_client_module, 'SerialSettings')
        except Exception as exc:  # pragma: no cover - load failure fallback
            self._log(f"  ⚠️  JSVV serial unavailable ({exc}); falling back to HTTP test")
            return None

        settings = SerialSettings(
            port=self.jsvv_serial['port'],
            baudrate=self.jsvv_serial['baudrate'],
            parity=self.jsvv_serial['parity'],
            stopbits=self.jsvv_serial['stopbits'],
            bytesize=self.jsvv_serial['bytesize'],
            timeout=self.jsvv_serial['timeout'],
        )

        self._log("▶  JSVV (serial): sending SIREN frame over UART …")
        try:
            timestamp = int(time.time())
            with JSVVClient(settings=settings) as client:
                client.send_frame('SIREN', [1, timestamp])
            return TestResult("JSVV (serial)", True, f"frame_sent timestamp={timestamp}")
        except Exception as exc:
            return TestResult("JSVV (serial)", False, f"error={exc}")

    def test_gsm(self) -> TestResult:
        if self.gsm_serial and serial is not None:
            serial_result = self.test_gsm_serial()
            if serial_result is not None:
                return serial_result

        self._log("▶  GSM: simulating ringing → accepted → finished via API …")
        session_id = uuid.uuid4().hex
        caller = "+420123456789"
        metadata = {"route": [], "zones": []}

        events = [
            {"state": "ringing", "session_id": session_id, "caller": caller, "metadata": metadata},
            {"state": "accepted", "session_id": session_id, "caller": caller, "metadata": metadata},
            {"state": "finished", "session_id": session_id, "caller": caller, "metadata": metadata},
        ]

        try:
            last_response: dict[str, Any] = {}
            for event in events:
                response = self._post("/gsm/events", event)
                last_response = response.json()
                time.sleep(0.5)

            action = last_response.get("action")
            success = action in {"ack", None}
            return TestResult("GSM", success, f"last_action={action}")
        except Exception as exc:
            return TestResult("GSM", False, f"error={exc}")

    def test_gsm_serial(self) -> Optional[TestResult]:
        if serial is None:
            self._log("  ⚠️  pyserial missing; skipping GSM serial test")
            return None

        self._log("▶  GSM (serial): pushing RING/CLIP trace …")
        try:
            ser = serial.Serial(  # type: ignore[attr-defined]
                port=self.gsm_serial['port'],
                baudrate=self.gsm_serial['baudrate'],
                bytesize=self.gsm_serial['bytesize'],
                parity=self.gsm_serial['parity'],
                stopbits=self.gsm_serial['stopbits'],
                timeout=self.gsm_serial['timeout'],
                write_timeout=self.gsm_serial['write_timeout'],
            )
        except Exception as exc:
            return TestResult("GSM (serial)", False, f"open_error={exc}")

        try:
            caller = "+420123456789"
            session_lines = [
                "RING",
                f'+CLIP: "{caller}",129,"",0,""',
                '+CIEV: "CALL",1',
                'VOICE CALL: END',
            ]
            for line in session_lines:
                ser.write((line + '\r\n').encode('utf-8'))
                time.sleep(0.5)
            ser.close()
            return TestResult("GSM (serial)", True, "AT trace sent")
        except Exception as exc:
            ser.close()
            return TestResult("GSM (serial)", False, f"error={exc}")

    def test_fm_radio(self) -> TestResult:
        self._log("▶  FM: starting and stopping fm_radio stream …")

        start_payload = {
            "source": "fm_radio",
            "route": [],
            "locations": [],
            "options": {"origin": "smoke_test"},
        }

        try:
            start_response = self._post("/live-broadcast/start", start_payload).json()
            session = start_response.get("session", {})
            session_id = session.get("id")
            time.sleep(1.0)

            stop_response = self._post("/live-broadcast/stop", {"reason": "smoke_test"}).json()
            stopped_session = stop_response.get("session", {})

            success = bool(session_id) and (stopped_session.get("status") in {"stopped", "idle"})
            detail = f"session_id={session_id}, stop_status={stopped_session.get('status')}"
            return TestResult("FM radio", success, detail)
        except Exception as exc:
            return TestResult("FM radio", False, f"error={exc}")

    def test_control_tab(self) -> TestResult:
        if self.control_tab_serial and serial is not None:
            serial_result = self.test_control_tab_serial()
            if serial_result is not None:
                return serial_result

        self._log("▶  Control Tab: simulating button press and text request via API …")
        try:
            button_payload = {
                "type": "button_pressed",
                "screen": 1,
                "panel": 1,
                "button_id": 1,
                "sessionId": uuid.uuid4().hex,
                "raw": "<<<:1:1:2=1>>00<<<",
            }
            button_response = self._post("/control-tab/events", button_payload).json()
            button_ok = button_response.get("action") == "ack"

            text_payload = {
                "type": "text_field_request",
                "screen": 1,
                "panel": 1,
                "field_id": 1,
                "sessionId": uuid.uuid4().hex,
                "raw": "<<<:1:1:3=?1?>>00<<<",
            }
            text_response = self._post("/control-tab/events", text_payload).json()
            text_ok = text_response.get("action") == "text"

            success = button_ok and text_ok
            detail = f"button_action={button_response.get('action')}, text_action={text_response.get('action')}"
            return TestResult("Control Tab", success, detail)
        except Exception as exc:
            return TestResult("Control Tab", False, f"error={exc}")

    def test_control_tab_serial(self) -> Optional[TestResult]:
        if serial is None:
            self._log("  ⚠️  pyserial missing; skipping Control Tab serial test")
            return None

        self._log("▶  Control Tab (serial): sending button frame …")
        try:
            ser = serial.Serial(  # type: ignore[attr-defined]
                port=self.control_tab_serial['port'],
                baudrate=self.control_tab_serial['baudrate'],
                bytesize=self.control_tab_serial['bytesize'],
                parity=self.control_tab_serial['parity'],
                stopbits=self.control_tab_serial['stopbits'],
                timeout=self.control_tab_serial['timeout'],
                write_timeout=self.control_tab_serial['write_timeout'],
            )
        except Exception as exc:
            return TestResult("Control Tab (serial)", False, f"open_error={exc}")

        try:
            frame = self._build_control_tab_frame(screen=1, panel=1, event_type=2, payload="1")
            ser.write(frame.encode('utf-8'))
            ack = ser.readline().decode('utf-8', errors='ignore')
            ok = ack.startswith('\n>>>:') and '>>' in ack

            text_request = self._build_control_tab_frame(screen=1, panel=1, event_type=3, payload="?1?")
            ser.write(text_request.encode('utf-8'))
            text_response = ser.readline().decode('utf-8', errors='ignore')
            ok = ok and text_response.startswith('\n>>>TEXT:')
            ser.close()
            return TestResult("Control Tab (serial)", ok, f"ack={ack.strip()}, text={text_response.strip()}")
        except Exception as exc:
            ser.close()
            return TestResult("Control Tab (serial)", False, f"error={exc}")

    def _build_control_tab_frame(self, screen: int, panel: int, event_type: int, payload: str) -> str:
        body = f"{screen}:{panel}:{event_type}={payload}"
        crc = self._xor_crc(body)
        return f"<<<:{body}>>{crc}<<<\n"

    @staticmethod
    def _xor_crc(data: str) -> str:
        crc = 0
        for byte in data.encode('utf-8'):
            crc ^= byte
        return f"{crc:02X}"

    def run_all(self) -> list[TestResult]:
        tests: list[tuple[str, Callable[[], TestResult]]] = [
            ("JSVV", self.test_jsvv),
            ("GSM", self.test_gsm),
            ("FM radio", self.test_fm_radio),
            ("Control Tab", self.test_control_tab),
        ]

        results: list[TestResult] = []
        for name, test in tests:
            result = test()
            status = "OK" if result.success else "FAIL"
            self._log(f"✓ {name} -> {status}: {result.detail}" if result.success else f"✗ {name} -> {result.detail}")
            results.append(result)
        return results


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run sequential smoke tests across subsystems.")
    parser.add_argument("--base-url", default="http://127.0.0.1:8001/api", help="Laravel API base URL (default: %(default)s)")
    parser.add_argument("--token", help="Optional bearer token for API authentication")
    parser.add_argument("--timeout", type=float, default=10.0, help="HTTP timeout in seconds (default: %(default)s)")

    parser.add_argument("--jsvv-port", help="Serial port for JSVV simulation (overrides HTTP test)")
    parser.add_argument("--jsvv-baud", type=int, default=9600)
    parser.add_argument("--jsvv-parity", default="N")
    parser.add_argument("--jsvv-stopbits", type=int, default=1)
    parser.add_argument("--jsvv-bytesize", type=int, default=8)
    parser.add_argument("--jsvv-serial-timeout", type=float, default=0.5)

    parser.add_argument("--gsm-port", help="Serial port for GSM simulation (optional)")
    parser.add_argument("--gsm-baud", type=int, default=115200)
    parser.add_argument("--gsm-parity", default="N")
    parser.add_argument("--gsm-stopbits", type=int, default=1)
    parser.add_argument("--gsm-bytesize", type=int, default=8)
    parser.add_argument("--gsm-serial-timeout", type=float, default=0.5)
    parser.add_argument("--gsm-write-timeout", type=float, default=1.0)

    parser.add_argument("--control-tab-port", help="Serial port for Control Tab simulation")
    parser.add_argument("--control-tab-baud", type=int, default=115200)
    parser.add_argument("--control-tab-parity", default="N")
    parser.add_argument("--control-tab-stopbits", type=int, default=1)
    parser.add_argument("--control-tab-bytesize", type=int, default=8)
    parser.add_argument("--control-tab-serial-timeout", type=float, default=0.5)
    parser.add_argument("--control-tab-write-timeout", type=float, default=1.0)

    return parser.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)
    jsvv_serial = None
    if args.jsvv_port:
        jsvv_serial = {
            'port': args.jsvv_port,
            'baudrate': args.jsvv_baud,
            'parity': args.jsvv_parity,
            'stopbits': args.jsvv_stopbits,
            'bytesize': args.jsvv_bytesize,
            'timeout': args.jsvv_serial_timeout,
        }

    gsm_serial = None
    if args.gsm_port:
        gsm_serial = {
            'port': args.gsm_port,
            'baudrate': args.gsm_baud,
            'parity': args.gsm_parity,
            'stopbits': args.gsm_stopbits,
            'bytesize': args.gsm_bytesize,
            'timeout': args.gsm_serial_timeout,
            'write_timeout': args.gsm_write_timeout,
        }

    control_tab_serial = None
    if args.control_tab_port:
        control_tab_serial = {
            'port': args.control_tab_port,
            'baudrate': args.control_tab_baud,
            'parity': args.control_tab_parity,
            'stopbits': args.control_tab_stopbits,
            'bytesize': args.control_tab_bytesize,
            'timeout': args.control_tab_serial_timeout,
            'write_timeout': args.control_tab_write_timeout,
        }

    tester = SmokeTester(
        args.base_url,
        args.token,
        args.timeout,
        jsvv_serial=jsvv_serial,
        control_tab_serial=control_tab_serial,
        gsm_serial=gsm_serial,
    )
    results = tester.run_all()

    failed = [result for result in results if not result.success]
    print("\nSummary:")
    for result in results:
        print(f" - {result.name}: {'OK' if result.success else 'FAIL'} ({result.detail})")

    return 0 if not failed else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

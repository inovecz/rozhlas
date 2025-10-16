#!/usr/bin/env python3
"""JSVV KPPS frame listener daemon.

Continuously reads frames from the JSVV serial link and forwards normalised
payloads to the Laravel backend. The daemon uses the underlying ``JSVVClient``
for parsing, deduplication, and asset lookup.
"""

from __future__ import annotations

import argparse
import json
import os
import queue
import signal
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests

from jsvv import JSVVClient, JSVVError, SerialSettings  # type: ignore


@dataclass(slots=True)
class ListenerConfig:
    network_id: int
    vyc_id: int
    kpps_address: str
    operator_id: int | None
    webhook_url: str | None
    auth_token: str | None
    webhook_timeout: float
    dedup_window: float
    audio_root: Path | None


class PayloadSink:
    def __init__(self, config: ListenerConfig) -> None:
        self.config = config

    def emit(self, payload: dict[str, Any]) -> None:
        if not self.config.webhook_url:
            print(json.dumps(payload, ensure_ascii=False), flush=True)
            return
        headers = {"Content-Type": "application/json"}
        if self.config.auth_token:
            headers["Authorization"] = f"Bearer {self.config.auth_token}"
        requests.post(
            self.config.webhook_url,
            headers=headers,
            data=json.dumps(payload).encode("utf-8"),
            timeout=self.config.webhook_timeout,
        )


class FrameListener:
    def __init__(self, client: JSVVClient, sink: PayloadSink, config: ListenerConfig, poll_timeout: float) -> None:
        self._client = client
        self._sink = sink
        self._config = config
        self._poll_timeout = poll_timeout
        self._queue: queue.Queue[dict[str, Any]] = queue.Queue()
        self._stop_event = threading.Event()
        self._dispatcher = threading.Thread(target=self._dispatch_loop, daemon=True)

    def start(self) -> None:
        self._dispatcher.start()
        while not self._stop_event.is_set():
            try:
                frame = self._client.receive_frame(timeout=self._poll_timeout)
            except JSVVError as exc:
                payload = {"status": "error", "message": str(exc)}
                self._queue.put(payload)
                continue
            payload = self._client.build_json_payload(
                frame,
                network_id=self._config.network_id,
                vyc_id=self._config.vyc_id,
                kpps_address=self._config.kpps_address,
                operator_id=self._config.operator_id,
            )
            duplicate = not self._client.validate_and_track(
                frame,
                network_id=self._config.network_id,
                vyc_id=self._config.vyc_id,
                kpps_address=self._config.kpps_address,
                operator_id=self._config.operator_id,
            )
            self._queue.put({
                "status": "ok",
                "payload": payload,
                "duplicate": duplicate,
            })

    def stop(self) -> None:
        self._stop_event.set()
        self._dispatcher.join(timeout=5.0)

    def _dispatch_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                payload = self._queue.get(timeout=0.5)
            except queue.Empty:
                continue
            try:
                self._sink.emit(payload)
            except Exception as exc:  # pragma: no cover - transport errors handled at runtime
                print(json.dumps({"status": "transport_error", "message": str(exc), "payload": payload}))


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="JSVV listener daemon")
    parser.add_argument("--port", default=os.getenv("JSVV_PORT"))
    parser.add_argument("--baudrate", type=int, default=int(os.getenv("JSVV_BAUDRATE", "9600")))
    parser.add_argument("--parity", default=os.getenv("JSVV_PARITY", "N"))
    parser.add_argument("--stopbits", type=int, default=int(os.getenv("JSVV_STOPBITS", "1")))
    parser.add_argument("--bytesize", type=int, default=int(os.getenv("JSVV_BYTESIZE", "8")))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("JSVV_TIMEOUT", "1.0")))
    parser.add_argument("--network", type=int, default=int(os.getenv("JSVV_NETWORK_ID", "1")))
    parser.add_argument("--vyc", type=int, default=int(os.getenv("JSVV_VYC_ID", "1")))
    parser.add_argument("--kpps", default=os.getenv("JSVV_KPPS_ADDRESS", "0x0001"))
    parser.add_argument("--operator", type=int)
    parser.add_argument("--webhook", default=os.getenv("JSVV_WEBHOOK"))
    parser.add_argument("--token", default=os.getenv("JSVV_TOKEN"))
    parser.add_argument("--webhook-timeout", type=float, default=float(os.getenv("JSVV_WEBHOOK_TIMEOUT", "5")))
    parser.add_argument("--dedup-window", type=float, default=float(os.getenv("JSVV_DEDUP_WINDOW", "180")))
    parser.add_argument("--poll-timeout", type=float, default=float(os.getenv("JSVV_POLL_TIMEOUT", "2")))
    parser.add_argument("--audio-root", default=os.getenv("JSVV_AUDIO_ROOT"))
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    if not args.port:
        raise SystemExit("JSVV port must be provided via --port or JSVV_PORT env var")

    settings = SerialSettings(
        port=args.port,
        baudrate=args.baudrate,
        parity=args.parity,
        stopbits=args.stopbits,
        bytesize=args.bytesize,
        timeout=args.timeout,
    )

    audio_root = Path(args.audio_root).expanduser() if args.audio_root else None
    client = JSVVClient(settings=settings, dedup_window=args.dedup_window, audio_root=audio_root)

    config = ListenerConfig(
        network_id=args.network,
        vyc_id=args.vyc,
        kpps_address=args.kpps,
        operator_id=args.operator,
        webhook_url=args.webhook,
        auth_token=args.token,
        webhook_timeout=args.webhook_timeout,
        dedup_window=args.dedup_window,
        audio_root=audio_root,
    )
    sink = PayloadSink(config)
    listener = FrameListener(client, sink, config, args.poll_timeout)

    def handle_signal(signum, _frame):  # noqa: ANN001
        print(json.dumps({"signal": signum, "note": "Shutting down"}), flush=True)
        listener.stop()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    with client:
        listener.start()


if __name__ == "__main__":  # pragma: no cover
    main()

#!/usr/bin/env python3
"""GSM call listener daemon.

This daemon encapsulates the logic for monitoring the GSM expansion module,
normalising call events, and forwarding them to the Laravel backend via a
pluggable transport (HTTP webhook, Redis pub/sub, or stdout JSON stream).

The current implementation focuses on structure and instrumentation – replace
`_simulate_event_source()` with the real hardware driver once available.
"""

from __future__ import annotations

import argparse
import json
import os
import queue
import signal
import threading
import time
from dataclasses import dataclass, asdict
from enum import Enum
from pathlib import Path
from typing import Any, Callable, Iterable

import requests


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
    timestamp: float
    session_id: str
    metadata: dict[str, Any]

    def to_json(self) -> str:
        payload = asdict(self)
        payload["state"] = self.state.value
        return json.dumps(payload, ensure_ascii=False)


class BackendSink:
    def __init__(self, webhook_url: str | None, auth_token: str | None, timeout: float) -> None:
        self._webhook_url = webhook_url
        self._auth_token = auth_token
        self._timeout = timeout

    def send(self, event: CallEvent) -> None:
        if not self._webhook_url:
            print(event.to_json(), flush=True)
            return
        headers = {"Content-Type": "application/json"}
        if self._auth_token:
            headers["Authorization"] = f"Bearer {self._auth_token}"
        requests.post(
            self._webhook_url,
            headers=headers,
            data=event.to_json().encode("utf-8"),
            timeout=self._timeout,
        )


class GSMListener:
    def __init__(self, sink: BackendSink, poll_interval: float, graceful_timeout: float) -> None:
        self._sink = sink
        self._poll_interval = poll_interval
        self._graceful_timeout = graceful_timeout
        self._queue: queue.Queue[CallEvent] = queue.Queue()
        self._stop_event = threading.Event()
        self._dispatcher = threading.Thread(target=self._dispatch_loop, daemon=True)

    def start(self) -> None:
        self._dispatcher.start()
        for event in self._simulate_event_source():
            if self._stop_event.is_set():
                break
            self._queue.put(event)
            time.sleep(self._poll_interval)

    def stop(self) -> None:
        self._stop_event.set()
        self._dispatcher.join(timeout=self._graceful_timeout)

    def _dispatch_loop(self) -> None:
        while not self._stop_event.is_set():
            try:
                event = self._queue.get(timeout=0.5)
            except queue.Empty:
                continue
            try:
                self._sink.send(event)
            except Exception as exc:  # pragma: no cover - transport errors handled at runtime
                print(json.dumps({
                    "error": str(exc),
                    "event": event.to_json(),
                }), flush=True)

    def _simulate_event_source(self) -> Iterable[CallEvent]:
        session_id = f"demo-{int(time.time())}"
        caller = "+420123456789"
        now = time.time()
        yield CallEvent(CallState.RINGING, caller, now, session_id, {"signal": "strong"})
        yield CallEvent(CallState.ACCEPTED, caller, now + 2, session_id, {"channels": 1})
        yield CallEvent(CallState.FINISHED, caller, now + 30, session_id, {"duration": 28})


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="GSM module listener daemon")
    parser.add_argument("--webhook", help="Backend webhook URL to push events to")
    parser.add_argument("--token", help="Optional bearer token for authentication")
    parser.add_argument("--timeout", type=float, default=float(os.getenv("GSM_WEBHOOK_TIMEOUT", "5")))
    parser.add_argument("--poll", type=float, default=float(os.getenv("GSM_POLL_INTERVAL", "1")))
    parser.add_argument("--graceful", type=float, default=float(os.getenv("GSM_GRACEFUL_TIMEOUT", "5")))
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    sink = BackendSink(args.webhook, args.token, args.timeout)
    listener = GSMListener(sink, args.poll, args.graceful)

    def handle_signal(signum, _frame):  # noqa: ANN001
        print(json.dumps({"signal": signum, "note": "Shutting down"}), flush=True)
        listener.stop()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    listener.start()


if __name__ == "__main__":  # pragma: no cover
    main()

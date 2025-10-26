#!/usr/bin/env python3
"""JSVV KPPS parser and dispatcher daemon.

Reads ASCII frames from the JSVV serial link, enforces priority-based dispatching
with retry/backoff, and forwards normalised JSON payloads to Laravel via
``php artisan jsvv:process-message``.
"""

from __future__ import annotations

import argparse
import copy
import json
import logging
import os
import signal
import subprocess
import threading
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from jsvv import JSVVClient, JSVVError, SerialSettings  # type: ignore

PRIORITY_MAP = {"P1": 0, "P2": 1, "P3": 2}
DEFAULT_PRIORITY_VALUE = 3


@dataclass(slots=True)
class ListenerConfig:
    network_id: int
    vyc_id: int
    kpps_address: str
    operator_id: int | None
    dedup_window: float
    artisan_bin: str
    artisan_path: str
    artisan_timeout: float
    max_retries: int
    retry_backoff: float
    log_file: Path | None
    log_level: str
    audio_root: Path | None


@dataclass(slots=True)
class DispatchTask:
    payload: dict[str, Any]
    raw_message: str
    priority: str
    duplicate: bool
    created_at: float = field(default_factory=time.monotonic)
    attempts: int = 0
    max_attempts: int = 3
    next_attempt_at: float = field(default_factory=time.monotonic)

    def priority_value(self) -> int:
        return PRIORITY_MAP.get(self.priority.upper(), DEFAULT_PRIORITY_VALUE)

    def schedule_retry(self, backoff: float) -> None:
        self.attempts += 1
        delay = backoff * (2 ** max(0, self.attempts - 1))
        self.next_attempt_at = time.monotonic() + delay


class PriorityScheduler:
    """A minimal thread-safe priority scheduler with delayed retry support."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._condition = threading.Condition(self._lock)
        self._queue: list[tuple[int, float, int, DispatchTask]] = []
        self._sequence = 0
        self._stopped = False

    def put(self, task: DispatchTask) -> None:
        with self._condition:
            entry = (task.priority_value(), task.next_attempt_at, self._sequence, task)
            self._sequence += 1
            self._queue.append(entry)
            self._queue.sort(key=lambda item: (item[0], item[1], item[2]))
            self._condition.notify()

    def get(self) -> DispatchTask | None:
        with self._condition:
            while True:
                if self._stopped and not self._queue:
                    return None
                if not self._queue:
                    self._condition.wait(timeout=0.5)
                    continue
                priority_value, available_at, _, task = self._queue[0]
                delay = available_at - time.monotonic()
                if delay > 0:
                    self._condition.wait(timeout=delay)
                    continue
                self._queue.pop(0)
                return task

    def stop(self) -> None:
        with self._condition:
            self._stopped = True
            self._condition.notify_all()


class ArtisanInvoker:
    def __init__(self, config: ListenerConfig, logger: logging.Logger) -> None:
        self._config = config
        self._logger = logger
        root = Path(__file__).resolve().parents[2]
        self._cwd = str(root)
        self._command = [config.artisan_bin, config.artisan_path, "jsvv:process-message"]

    def invoke(self, payload: dict[str, Any]) -> subprocess.CompletedProcess[Any] | None:
        try:
            encoded = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        except (TypeError, ValueError) as exc:
            self._logger.error("Failed to encode payload to JSON: %s", exc, extra={"payload": payload})
            return None

        try:
            completed = subprocess.run(
                self._command,
                input=encoded,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=self._config.artisan_timeout,
                check=False,
                cwd=self._cwd,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            self._logger.error("Artisan invocation failed: %s", exc, extra={"payload": payload})
            return None

        return completed


class DispatchWorker(threading.Thread):
    def __init__(
        self,
        scheduler: PriorityScheduler,
        invoker: ArtisanInvoker,
        config: ListenerConfig,
        logger: logging.Logger,
    ) -> None:
        super().__init__(daemon=True)
        self._scheduler = scheduler
        self._invoker = invoker
        self._config = config
        self._logger = logger
        self._stop_event = threading.Event()

    def run(self) -> None:
        while not self._stop_event.is_set():
            task = self._scheduler.get()
            if task is None:
                return

            now_monotonic = time.monotonic()
            if task.next_attempt_at > now_monotonic:
                time.sleep(task.next_attempt_at - now_monotonic)

            payload = copy.deepcopy(task.payload)
            meta = payload.setdefault("meta", {})
            meta["duplicate"] = task.duplicate
            meta["attempt"] = task.attempts + 1
            meta["maxAttempts"] = task.max_attempts

            self._logger.info(
                "[FORWARDED] %s priority=%s attempt=%d/%d duplicate=%s",
                payload.get("command"),
                task.priority,
                task.attempts + 1,
                task.max_attempts,
                task.duplicate,
            )

            completed = self._invoker.invoke(payload)
            if completed is None:
                self._handle_failure(task, "invoke_error")
                continue

            success = completed.returncode == 0

            self._logger.debug(
                "Artisan exit code %s stdout=%s stderr=%s",
                completed.returncode,
                completed.stdout.decode("utf-8", errors="replace"),
                completed.stderr.decode("utf-8", errors="replace"),
            )

            if success:
                self._logger.info(
                    "[DONE] %s priority=%s duplicate=%s",
                    payload.get("command"),
                    task.priority,
                    task.duplicate,
                )
                continue

            self._handle_failure(task, completed.returncode)

    def stop(self) -> None:
        self._stop_event.set()
        self._scheduler.stop()

    def _handle_failure(self, task: DispatchTask, reason: Any) -> None:
        if task.attempts + 1 >= task.max_attempts:
            self._logger.error(
                "[FAILED] %s priority=%s attempts=%d reason=%s",
                task.payload.get("command"),
                task.priority,
                task.attempts + 1,
                reason,
            )
            return

        task.schedule_retry(self._config.retry_backoff)
        self._logger.warning(
            "[RETRY] %s priority=%s attempt=%d/%d reason=%s next_in=%.2fs",
            task.payload.get("command"),
            task.priority,
            task.attempts,
            task.max_attempts,
            reason,
            max(0.0, task.next_attempt_at - time.monotonic()),
        )
        self._scheduler.put(task)


class ParserDaemon:
    def __init__(self, client: JSVVClient, config: ListenerConfig, logger: logging.Logger) -> None:
        self._client = client
        self._config = config
        self._logger = logger
        self._scheduler = PriorityScheduler()
        self._worker = DispatchWorker(self._scheduler, ArtisanInvoker(config, logger), config, logger)
        self._stop_event = threading.Event()

    def start(self) -> None:
        self._worker.start()
        self._logger.info("JSVV parser daemon started.")

        while not self._stop_event.is_set():
            try:
                frame = self._client.receive_frame(timeout=self._client.settings.timeout)
            except JSVVError as exc:
                self._logger.error("[REJECTED] raw=? reason=%s", exc)
                continue

            self._logger.info("[RECEIVED] %s", frame.raw)

            priority = self._resolve_priority(frame)
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

            payload["priority"] = priority
            payload["rawMessage"] = frame.raw

            task = DispatchTask(
                payload=payload,
                raw_message=frame.raw,
                priority=priority,
                duplicate=duplicate,
                max_attempts=max(1, self._config.max_retries),
            )

            if duplicate:
                self._logger.info("[DUPLICATE] %s priority=%s", frame.body(), priority)

            self._logger.info("[QUEUED] %s priority=%s", frame.body(), priority)
            self._scheduler.put(task)

        self._logger.info("JSVV parser stopping ...")
        self._worker.stop()
        self._worker.join(timeout=5.0)

    def stop(self) -> None:
        self._stop_event.set()

    def _resolve_priority(self, frame) -> str:
        spec_priority = frame.spec.priority if frame.spec else None
        if spec_priority and spec_priority in PRIORITY_MAP:
            return spec_priority
        return "P3"


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="JSVV listener and parser daemon")
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
    parser.add_argument("--dedup-window", type=float, default=float(os.getenv("JSVV_DEDUP_WINDOW", "180")))
    parser.add_argument("--artisan-bin", default=os.getenv("ARTISAN_BIN", "php"))
    parser.add_argument("--artisan-path", default=os.getenv("ARTISAN_PATH", "artisan"))
    parser.add_argument("--artisan-timeout", type=float, default=float(os.getenv("ARTISAN_TIMEOUT", "5")))
    parser.add_argument("--max-retries", type=int, default=int(os.getenv("PARSER_MAX_RETRIES", "3")))
    parser.add_argument("--retry-backoff", type=float, default=float(os.getenv("PARSER_RETRY_BACKOFF", "0.5")))
    parser.add_argument("--log-file", default=os.getenv("JSVV_PARSER_LOG"))
    parser.add_argument("--log-level", default=os.getenv("JSVV_LOG_LEVEL", "INFO"))
    parser.add_argument("--audio-root", default=os.getenv("JSVV_AUDIO_ROOT"))
    return parser


def configure_logging(config: ListenerConfig) -> logging.Logger:
    logger = logging.getLogger("jsvv.parser")
    level = getattr(logging, config.log_level.upper(), logging.INFO)
    logger.setLevel(level)
    formatter = logging.Formatter(
        "%(asctime)s %(levelname)s %(message)s", datefmt="%Y-%m-%dT%H:%M:%S.%f%z"
    )

    handler: logging.Handler
    if config.log_file:
        config.log_file.parent.mkdir(parents=True, exist_ok=True)
        handler = logging.FileHandler(config.log_file, encoding="utf-8")
    else:
        handler = logging.StreamHandler()
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.propagate = False
    return logger


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    if not args.port:
        raise SystemExit("JSVV port must be provided via --port or JSVV_PORT env var")

    audio_root = Path(args.audio_root).expanduser() if args.audio_root else None
    settings = SerialSettings(
        port=args.port,
        baudrate=args.baudrate,
        parity=args.parity,
        stopbits=args.stopbits,
        bytesize=args.bytesize,
        timeout=args.timeout,
    )

    config = ListenerConfig(
        network_id=args.network,
        vyc_id=args.vyc,
        kpps_address=args.kpps,
        operator_id=args.operator,
        dedup_window=args.dedup_window,
        artisan_bin=args.artisan_bin,
        artisan_path=args.artisan_path,
        artisan_timeout=args.artisan_timeout,
        max_retries=max(1, args.max_retries),
        retry_backoff=max(0.1, args.retry_backoff),
        log_file=Path(args.log_file).expanduser() if args.log_file else None,
        log_level=args.log_level,
        audio_root=audio_root,
    )

    logger = configure_logging(config)
    client = JSVVClient(settings=settings, dedup_window=args.dedup_window, audio_root=audio_root)
    daemon = ParserDaemon(client, config, logger)

    def handle_signal(signum, _frame):  # noqa: ANN001
        logger.info("Received signal %s; shutting down", signum)
        daemon.stop()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    with client:
        daemon.start()


if __name__ == "__main__":  # pragma: no cover
    main()

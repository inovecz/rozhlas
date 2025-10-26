#!/usr/bin/env python3
"""Control channel worker handling backend coordination commands.

Provides a Unix domain socket bridge between Laravel and the Modbus layer. The
worker keeps a lightweight FSM in sync with real Modbus polling (or a dry-run
simulation when hardware is unavailable) and returns JSON acknowledgements that
match the requirements in ``docs/requirements_docs/jsvv/05_integrace_modbus.md``.
"""

from __future__ import annotations

import argparse
import asyncio
import contextlib
import json
import logging
import os
import signal
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT_DIR = Path(__file__).resolve().parents[1]
SRC_DIR = ROOT_DIR / "src"
if str(SRC_DIR) not in sys.path:
    sys.path.insert(0, str(SRC_DIR))

try:  # pragma: no cover - runtime dependency may be missing during dry runs
    from modbus_audio import ModbusAudioClient, ModbusAudioError, SerialSettings, constants
except Exception:  # pragma: no cover - fall back to dry run mode automatically
    ModbusAudioClient = None  # type: ignore[assignment]
    ModbusAudioError = RuntimeError  # type: ignore[assignment]
    SerialSettings = object  # type: ignore[assignment]
    constants = None  # type: ignore[assignment]

STATE_IDLE = "IDLE"
STATE_TRANSMITTING = "TRANSMITTING"
STATE_PAUSED = "PAUSED"
STATE_STOPPED = "STOPPED"


@dataclass(slots=True)
class ModbusConfig:
    port: str
    method: str
    baudrate: int
    parity: str
    stopbits: int
    bytesize: int
    timeout: float
    unit_id: int
    poll_interval: float
    dry_run: bool


@dataclass(slots=True)
class WorkerConfig:
    socket_path: Path
    timeout: float
    log_file: Path | None
    log_level: str
    heartbeat_interval: float
    modbus: ModbusConfig


def _bool_env(name: str, default: bool) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() not in {"0", "false", "no", "off"}


def _ts_iso(timestamp: float | None) -> str | None:
    if timestamp is None:
        return None
    return datetime.fromtimestamp(timestamp, tz=timezone.utc).isoformat()


class ModbusManager:
    """Bridge between async control channel commands and the Modbus client."""

    def __init__(self, config: ModbusConfig, logger: logging.Logger) -> None:
        self._config = config
        self._logger = logger
        self._dry_run = config.dry_run or ModbusAudioClient is None
        self._state = STATE_IDLE
        self._state_lock = asyncio.Lock()
        self._snapshot_lock = asyncio.Lock()
        self._pause_event = asyncio.Event()
        self._stop_event = asyncio.Event()
        self._poll_task: asyncio.Task[None] | None = None
        self._client: ModbusAudioClient | None = None  # type: ignore[assignment]
        self._last_poll: float | None = None
        self._last_error: str | None = None
        self._last_snapshot: dict[str, Any] = {}

        if self._dry_run:
            self._logger.warning("Control channel worker running in dry-run mode (no Modbus hardware detected).")

    async def resume(self, reason: str | None = None) -> tuple[bool, dict[str, Any], str]:
        async with self._state_lock:
            if self._state == STATE_STOPPED:
                return False, {"error": "Cannot resume from STOPPED state"}, self._state
            if self._state == STATE_TRANSMITTING:
                return True, {"note": "Already transmitting"}, self._state
            previous = self._state

        try:
            await self._ensure_client()
        except Exception as exc:
            self._logger.error("Failed to initialise Modbus client: %s", exc)
            return False, {"error": str(exc)}, self._state

        await self._ensure_poll_task()
        self._stop_event.clear()
        self._pause_event.set()

        async with self._state_lock:
            self._state = STATE_TRANSMITTING

        return True, {"previous": previous, "reason": reason}, STATE_TRANSMITTING

    async def pause(self, reason: str | None = None) -> tuple[bool, dict[str, Any], str]:
        async with self._state_lock:
            if self._state == STATE_STOPPED:
                return False, {"error": "Channel already stopped"}, self._state
            if self._state == STATE_PAUSED:
                return True, {"note": "Already paused"}, self._state
            previous = self._state
            self._state = STATE_PAUSED
            self._pause_event.clear()

        return True, {"previous": previous, "reason": reason}, STATE_PAUSED

    async def stop(self, reason: str | None = None) -> tuple[bool, dict[str, Any], str]:
        async with self._state_lock:
            if self._state == STATE_STOPPED:
                return True, {"note": "Already stopped"}, STATE_STOPPED
            previous = self._state
            self._state = STATE_STOPPED
            self._stop_event.set()
            self._pause_event.set()

        await self._drain_poll_task()
        await self._close_client()

        return True, {"previous": previous, "reason": reason}, STATE_STOPPED

    async def status(self) -> tuple[bool, dict[str, Any], str]:
        async with self._snapshot_lock:
            snapshot = dict(self._last_snapshot)
            last_error = self._last_error
            last_poll = self._last_poll
        async with self._state_lock:
            state = self._state

        payload = {
            "snapshot": snapshot,
            "dryRun": self._dry_run,
            "lastPollAt": _ts_iso(last_poll),
            "lastError": last_error,
        }
        return True, payload, state

    async def apply_initial_state(self, target_state: str) -> None:
        normalized = target_state.upper()
        if normalized == STATE_TRANSMITTING:
            await self.resume("initial")
        elif normalized == STATE_PAUSED:
            await self.resume("initial")
            await self.pause("initial")
        elif normalized == STATE_STOPPED:
            await self.stop("initial")

    async def shutdown(self) -> None:
        await self.stop("shutdown")

    async def _ensure_client(self) -> None:
        if self._dry_run:
            return
        if self._client is not None:
            return

        assert ModbusAudioClient is not None and SerialSettings is not object  # for type checking
        settings = SerialSettings(
            port=self._config.port,
            method=self._config.method,
            baudrate=self._config.baudrate,
            parity=self._config.parity,
            stopbits=self._config.stopbits,
            bytesize=self._config.bytesize,
            timeout=self._config.timeout,
        )

        def _connect() -> ModbusAudioClient:
            client = ModbusAudioClient(settings=settings, unit_id=self._config.unit_id)
            client.connect()
            return client

        try:
            self._client = await asyncio.to_thread(_connect)
            self._logger.info("Modbus client connected (port=%s, unit=%s).", self._config.port, self._config.unit_id)
        except Exception as exc:  # pragma: no cover - depends on hardware
            raise ModbusAudioError(str(exc)) from exc  # type: ignore[misc]

    async def _ensure_poll_task(self) -> None:
        if self._poll_task is not None and not self._poll_task.done():
            return

        self._stop_event.clear()
        self._pause_event.set()
        self._poll_task = asyncio.create_task(self._poll_loop(), name="modbus-poll-loop")

    async def _poll_loop(self) -> None:
        self._logger.debug("Starting Modbus poll loop (dry_run=%s).", self._dry_run)
        try:
            while not self._stop_event.is_set():
                await self._pause_event.wait()
                if self._stop_event.is_set():
                    break

                try:
                    snapshot = await self._poll_once()
                    async with self._snapshot_lock:
                        self._last_snapshot = snapshot
                        self._last_poll = time.time()
                        self._last_error = None
                except Exception as exc:  # pragma: no cover - depends on hardware failures
                    self._logger.warning("Modbus polling failed: %s", exc)
                    async with self._snapshot_lock:
                        self._last_error = str(exc)

                await asyncio.sleep(self._config.poll_interval)
        finally:
            self._logger.debug("Modbus poll loop stopped.")

    async def _poll_once(self) -> dict[str, Any]:
        if self._dry_run:
            return {"statusRegister": "DRY_RUN", "timestamp": _ts_iso(time.time())}

        assert self._client is not None
        assert constants is not None

        def _read_status() -> tuple[int, int]:
            status = self._client.read_register(constants.STATUS_REGISTER)
            error = self._client.read_register(constants.ERROR_REGISTER)
            return status, error

        status_value, error_value = await asyncio.to_thread(_read_status)
        return {"statusRegister": status_value, "errorRegister": error_value}

    async def _drain_poll_task(self) -> None:
        task = self._poll_task
        self._poll_task = None
        if task is not None:
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task

    async def _close_client(self) -> None:
        if self._client is None:
            return

        client = self._client
        self._client = None

        def _close() -> None:
            try:
                client.close()
            except Exception as exc:  # pragma: no cover - depends on hardware
                self._logger.debug("Error closing Modbus client: %s", exc)

        await asyncio.to_thread(_close)
        self._logger.info("Modbus client connection closed.")


class ModbusSupervisor:
    """In-memory FSM that wraps the manager and records transition metadata."""

    def __init__(self, manager: ModbusManager, logger: logging.Logger) -> None:
        self._manager = manager
        self._logger = logger
        self._state = STATE_IDLE
        self._last_transition = time.time()
        self._last_reason: str | None = None

    @property
    def state(self) -> str:
        return self._state

    def last_transition_iso(self) -> str:
        return datetime.fromtimestamp(self._last_transition, tz=timezone.utc).isoformat()

    async def resume(self, reason: str | None = None) -> tuple[bool, dict[str, Any]]:
        ok, details, new_state = await self._manager.resume(reason)
        self._update_state(new_state, reason if ok else None)
        return ok, details

    async def pause(self, reason: str | None = None) -> tuple[bool, dict[str, Any]]:
        ok, details, new_state = await self._manager.pause(reason)
        self._update_state(new_state, reason if ok else None)
        return ok, details

    async def stop(self, reason: str | None = None) -> tuple[bool, dict[str, Any]]:
        ok, details, new_state = await self._manager.stop(reason)
        self._update_state(new_state, reason if ok else None)
        return ok, details

    async def status(self) -> tuple[bool, dict[str, Any]]:
        ok, details, new_state = await self._manager.status()
        self._update_state(new_state, None)
        details = dict(details)
        details.setdefault("state", self._state)
        details["lastTransition"] = self.last_transition_iso()
        details["lastReason"] = self._last_reason
        return ok, details

    async def apply_initial_state(self, target_state: str) -> None:
        await self._manager.apply_initial_state(target_state)
        ok, details = await self.status()
        if ok:
            self._logger.info("Initial Modbus state: %s (%s)", self.state, details.get("lastReason"))

    async def shutdown(self) -> None:
        await self._manager.shutdown()

    def _update_state(self, new_state: str, reason: str | None) -> None:
        if new_state == self._state:
            return
        self._state = new_state
        self._last_transition = time.time()
        if reason is not None:
            self._last_reason = reason


class ControlChannelServer:
    def __init__(self, config: WorkerConfig, supervisor: ModbusSupervisor, logger: logging.Logger) -> None:
        self._config = config
        self._supervisor = supervisor
        self._logger = logger
        self._server: asyncio.AbstractServer | None = None
        self._stopping = asyncio.Event()

    async def start(self) -> None:
        path = self._config.socket_path
        if path.exists():
            path.unlink()
        path.parent.mkdir(parents=True, exist_ok=True)

        self._server = await asyncio.start_unix_server(self._handle_client, path=str(path))
        os.chmod(path, 0o660)
        self._logger.info("Control channel listening on %s", path)

        async with self._server:
            await self._stopping.wait()

    async def stop(self) -> None:
        self._stopping.set()
        if self._server is not None:
            self._server.close()
            await self._server.wait_closed()
            self._server = None
        if self._config.socket_path.exists():
            self._config.socket_path.unlink()
        await self._supervisor.shutdown()
        self._logger.info("Control channel stopped.")

    async def _handle_client(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        start = time.monotonic()
        peer = writer.get_extra_info("peername")
        self._logger.debug("Client connected: %s", peer)

        try:
            writer.write(b"READY\n")
            await writer.drain()
            data = await asyncio.wait_for(reader.readline(), timeout=self._config.timeout)
            if not data:
                raise asyncio.TimeoutError("No data received")
            request = json.loads(data.decode("utf-8"))
        except asyncio.TimeoutError:
            self._logger.warning("Client timeout waiting for request.")
            await self._write_response(writer, self._build_response(False, self._supervisor.state, {"error": "timeout"}, start))
            return
        except json.JSONDecodeError as exc:
            self._logger.error("Invalid JSON from client: %s", exc)
            await self._write_response(writer, self._build_response(False, self._supervisor.state, {"error": "invalid_json"}, start))
            return
        except Exception as exc:  # pragma: no cover - defensive fallback
            self._logger.error("Unexpected control channel error: %s", exc)
            await self._write_response(writer, self._build_response(False, self._supervisor.state, {"error": "unexpected_error"}, start))
            return

        response = await self._apply_request(request, time.monotonic() - start)
        await self._write_response(writer, response)

    async def _apply_request(self, request: dict[str, Any], duration: float) -> dict[str, Any]:
        command = (request.get("command") or "").lower()
        reason = request.get("reason")
        self._logger.info("Control command %s reason=%s", command, reason)

        if command == "pause_modbus":
            ok, details = await self._supervisor.pause(reason)
        elif command == "resume_modbus":
            ok, details = await self._supervisor.resume(reason)
        elif command == "stop_modbus":
            ok, details = await self._supervisor.stop(reason)
        elif command == "status_modbus":
            ok, details = await self._supervisor.status()
        else:
            ok, details = False, {"error": f"Unknown command {command or '<empty>'}"}

        return self._build_response(ok, self._supervisor.state, details, duration)

    async def _write_response(self, writer: asyncio.StreamWriter, response: dict[str, Any]) -> None:
        encoded = json.dumps(response, ensure_ascii=False).encode("utf-8") + b"\n"
        writer.write(encoded)
        await writer.drain()
        writer.close()
        await writer.wait_closed()

    def _build_response(self, ok: bool, state: str, details: dict[str, Any], duration: float) -> dict[str, Any]:
        return {
            "ok": ok,
            "state": state,
            "details": details,
            "ts": datetime.utcnow().replace(tzinfo=timezone.utc).isoformat(),
            "latencyMs": int(duration * 1000),
        }


def parse_endpoint(value: str | None) -> Path:
    if not value:
        return Path("/var/run/jsvv-control.sock")
    if value.startswith("unix://"):
        return Path(value.replace("unix://", "", 1))
    return Path(value)


def configure_logging(config: WorkerConfig) -> logging.Logger:
    logger = logging.getLogger("control_channel.worker")
    level = getattr(logging, config.log_level.upper(), logging.INFO)
    logger.setLevel(level)
    formatter = logging.Formatter("%(asctime)s %(levelname)s %(message)s")
    handler: logging.Handler
    if config.log_file:
        config.log_file.parent.mkdir(parents=True, exist_ok=True)
        handler = logging.FileHandler(config.log_file, encoding="utf-8")
    else:
        handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.propagate = False
    return logger


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Control channel worker")
    parser.add_argument("--endpoint", default=os.getenv("CONTROL_CHANNEL_ENDPOINT"))
    parser.add_argument("--socket", default=os.getenv("CONTROL_CHANNEL_SOCKET"))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("CONTROL_CHANNEL_TIMEOUT", "0.5")))
    parser.add_argument("--log-file", default=os.getenv("CONTROL_CHANNEL_LOG"))
    parser.add_argument("--log-level", default=os.getenv("CONTROL_CHANNEL_LOG_LEVEL", "INFO"))
    parser.add_argument("--heartbeat-interval", type=float, default=float(os.getenv("CONTROL_CHANNEL_HEARTBEAT", "5")))
    parser.add_argument("--initial-state", default=os.getenv("CONTROL_CHANNEL_INITIAL_STATE", STATE_IDLE))

    default_poll = float(os.getenv("CONTROL_CHANNEL_POLL_INTERVAL", "0.25"))
    default_dry_run = _bool_env("CONTROL_CHANNEL_DRY_RUN", True)

    parser.add_argument("--modbus-port", default=os.getenv("MODBUS_PORT", getattr(constants, "DEFAULT_SERIAL_PORT", "/dev/ttyAMA3")))
    parser.add_argument("--modbus-method", default=os.getenv("MODBUS_METHOD", "rtu"))
    parser.add_argument("--modbus-baudrate", type=int, default=int(os.getenv("MODBUS_BAUDRATE", getattr(constants, "DEFAULT_BAUDRATE", 57600))))
    parser.add_argument("--modbus-parity", default=os.getenv("MODBUS_PARITY", getattr(constants, "DEFAULT_PARITY", "N")))
    parser.add_argument("--modbus-stopbits", type=int, default=int(os.getenv("MODBUS_STOPBITS", getattr(constants, "DEFAULT_STOPBITS", 1))))
    parser.add_argument("--modbus-bytesize", type=int, default=int(os.getenv("MODBUS_BYTESIZE", getattr(constants, "DEFAULT_BYTESIZE", 8))))
    parser.add_argument("--modbus-timeout", type=float, default=float(os.getenv("MODBUS_TIMEOUT", getattr(constants, "DEFAULT_TIMEOUT", 1.0))))
    parser.add_argument("--modbus-unit-id", type=int, default=int(os.getenv("MODBUS_UNIT_ID", getattr(constants, "DEFAULT_UNIT_ID", 1))))
    parser.add_argument("--poll-interval", type=float, default=default_poll)
    parser.add_argument("--dry-run", dest="dry_run", action="store_true", default=default_dry_run)
    parser.add_argument("--no-dry-run", dest="dry_run", action="store_false")

    return parser


async def heartbeat(logger: logging.Logger, supervisor: ModbusSupervisor, interval: float, stop_event: asyncio.Event) -> None:
    while not stop_event.is_set():
        ok, details = await supervisor.status()
        if ok:
            logger.debug(
                "Heartbeat state=%s last_transition=%s snapshot=%s",
                supervisor.state,
                details.get("lastTransition"),
                details.get("snapshot"),
            )
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=interval)
        except asyncio.TimeoutError:
            continue


async def run_worker(args: argparse.Namespace) -> None:
    endpoint = args.socket or args.endpoint
    socket_path = parse_endpoint(endpoint)

    modbus_config = ModbusConfig(
        port=args.modbus_port,
        method=args.modbus_method,
        baudrate=args.modbus_baudrate,
        parity=args.modbus_parity,
        stopbits=args.modbus_stopbits,
        bytesize=args.modbus_bytesize,
        timeout=args.modbus_timeout,
        unit_id=args.modbus_unit_id,
        poll_interval=max(0.1, args.poll_interval),
        dry_run=args.dry_run,
    )

    worker_config = WorkerConfig(
        socket_path=socket_path,
        timeout=max(0.1, args.timeout),
        log_file=Path(args.log_file).expanduser() if args.log_file else None,
        log_level=args.log_level,
        heartbeat_interval=max(0.5, args.heartbeat_interval),
        modbus=modbus_config,
    )

    logger = configure_logging(worker_config)
    manager = ModbusManager(modbus_config, logger)
    supervisor = ModbusSupervisor(manager, logger)
    server = ControlChannelServer(worker_config, supervisor, logger)

    stop_event = asyncio.Event()

    def handle_signal(signum, _frame):  # noqa: ANN001
        logger.info("Signal %s received, shutting down...", signum)
        stop_event.set()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    await supervisor.apply_initial_state(args.initial_state)

    heartbeat_task = asyncio.create_task(
        heartbeat(logger, supervisor, worker_config.heartbeat_interval, stop_event),
        name="control-channel-heartbeat",
    )
    server_task = asyncio.create_task(server.start(), name="control-channel-server")

    await stop_event.wait()

    heartbeat_task.cancel()
    with contextlib.suppress(asyncio.CancelledError):
        await heartbeat_task

    await server.stop()
    await server_task


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    asyncio.run(run_worker(args))


if __name__ == "__main__":  # pragma: no cover
    main()

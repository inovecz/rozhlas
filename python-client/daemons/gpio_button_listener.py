#!/usr/bin/env python3
"""Monitor a GPIO line and trigger JSVV siren test when the button is pressed."""

from __future__ import annotations

import argparse
import logging
import os
import signal
import subprocess
import sys
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Optional

import requests


DEFAULT_WEBHOOK = "http://127.0.0.1:8001/api/control-tab/events"
DEFAULT_CONSUMER = "gpio-button-listener"


def _env_flag(name: str, default: bool) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _env_float(name: str, default: float) -> float:
    value = os.getenv(name)
    if value is None:
        return default
    try:
        return float(value)
    except ValueError:
        return default


def _env_int(name: str, default: int) -> int:
    value = os.getenv(name)
    if value is None:
        return default
    try:
        return int(value)
    except ValueError:
        return default


class GPIOBackendError(RuntimeError):
    """Raised when the GPIO backend cannot be initialised or queried."""


def _import_gpiod() -> Optional[object]:
    """Import gpiod bindings, extending sys.path if necessary."""

    try:
        import gpiod  # type: ignore[import]

        return gpiod
    except ModuleNotFoundError:
        pass

    # Attempt to reuse the virtualenv paths shipped with the project.
    project_root = Path(__file__).resolve().parents[1]
    candidates: list[str] = []
    venv_dir = project_root / ".venv"
    if venv_dir.exists():
        for pattern in ("lib/python*/site-packages", "lib64/python*/site-packages"):
            for path in venv_dir.glob(pattern):
                candidates.append(str(path))

    extra_paths = os.environ.get("GPIO_BUTTON_PYTHONPATH", "")
    if extra_paths:
        candidates.extend(p for p in extra_paths.split(":") if p)

    appended = False
    for path_str in candidates:
        if path_str and path_str not in sys.path and Path(path_str).exists():
            sys.path.insert(0, path_str)
            appended = True

    if not appended:
        return None

    try:
        import gpiod  # type: ignore[import]

        return gpiod
    except ModuleNotFoundError:
        return None


class _BaseGpioReader:
    def read(self) -> int:
        raise NotImplementedError

    def close(self) -> None:
        pass


class _GpiodReader(_BaseGpioReader):
    """Read a GPIO line via libgpiod bindings (supports v1 and v2)."""

    def __init__(self, chip: str, line_offset: int, consumer: str) -> None:
        gpiod = _import_gpiod()
        if gpiod is None:
            raise GPIOBackendError("gpiod Python bindings are not available.")

        self._close_callbacks: list[Callable[[], None]] = []
        self._reader: Callable[[], int]

        if hasattr(gpiod, "request_lines"):
            from gpiod import line as line_mod  # type: ignore[import]

            settings = gpiod.LineSettings(direction=line_mod.Direction.INPUT)
            config = {line_offset: settings}
            request = gpiod.request_lines(chip, consumer=consumer, config=config)
            active_value = line_mod.Value.ACTIVE
            inactive_value = line_mod.Value.INACTIVE

            def reader() -> int:
                raw = request.get_value(line_offset)
                if raw == active_value:
                    return 1
                if raw == inactive_value:
                    return 0
                try:
                    return int(raw)
                except (TypeError, ValueError):
                    return 1 if str(raw).strip() in {"1", "True", "true"} else 0

            self._reader = reader
            self._close_callbacks.append(request.release)
        else:  # pragma: no cover - explicitly kept for legacy libgpiod v1 environments
            chip_obj = gpiod.Chip(chip)
            line_obj = chip_obj.get_line(line_offset)
            line_obj.request(consumer=consumer, type=gpiod.LINE_REQ_DIR_IN)
            self._reader = lambda: int(line_obj.get_value())

            def release() -> None:
                try:
                    line_obj.release()
                finally:
                    chip_obj.close()

            self._close_callbacks.append(release)

    def read(self) -> int:
        return self._reader()

    def close(self) -> None:
        for callback in self._close_callbacks:
            try:
                callback()
            except Exception:
                logging.getLogger(__name__).exception("Failed to release GPIO line cleanly.")
        self._close_callbacks.clear()


class _GpiogetReader(_BaseGpioReader):
    """Fallback reader that shells out to gpioget whenever gpiod is unavailable."""

    def __init__(self, chip: str, line_offset: int) -> None:
        self._command = ["gpioget", chip, str(line_offset)]

    def read(self) -> int:
        try:
            completed = subprocess.run(
                self._command,
                check=True,
                capture_output=True,
                text=True,
            )
        except FileNotFoundError as exc:
            raise GPIOBackendError("gpioget command not found in PATH.") from exc
        except subprocess.CalledProcessError as exc:
            stderr = exc.stderr.strip() if isinstance(exc.stderr, str) else ""
            stdout = exc.stdout.strip() if isinstance(exc.stdout, str) else ""
            raise GPIOBackendError(f"gpioget returned {exc.returncode}: {stderr or stdout}") from exc

        output = completed.stdout.strip() or completed.stderr.strip()
        if output == "":
            raise GPIOBackendError("gpioget produced no output.")

        tokens = output.replace("=", " ").split()
        for token in reversed(tokens):
            if token in {"0", "1"}:
                return int(token)

        # Fallback: attempt to interpret the last character.
        last_char = output.strip()[-1]
        if last_char in {"0", "1"}:
            return int(last_char)

        raise GPIOBackendError(f"Unable to parse gpioget output: {output!r}")


def build_reader(chip: str, line_offset: int, consumer: str, backend: str) -> _BaseGpioReader:
    backend = backend.lower()
    if backend == "gpiod":
        return _GpiodReader(chip, line_offset, consumer)
    if backend == "gpioget":
        return _GpiogetReader(chip, line_offset)
    if backend not in {"auto", ""}:
        raise GPIOBackendError(f"Unknown backend '{backend}'. Expected auto, gpiod, or gpioget.")

    try:
        return _GpiodReader(chip, line_offset, consumer)
    except GPIOBackendError:
        logging.getLogger(__name__).warning("Falling back to gpioget backend.")
        return _GpiogetReader(chip, line_offset)


@dataclass(slots=True)
class ButtonConfig:
    chip: str
    line_offset: int
    active_level: int
    debounce_seconds: float
    release_debounce_seconds: float
    poll_interval: float
    cooldown_seconds: float
    button_id: int
    screen: int
    panel: int
    webhook: str
    token: Optional[str]
    timeout: float
    session_prefix: str
    backend: str
    consumer: str
    require_release: bool


class ButtonWatcher:
    def __init__(self, reader: _BaseGpioReader, config: ButtonConfig, logger: logging.Logger) -> None:
        self._reader = reader
        self._config = config
        self._logger = logger

        self._last_state: Optional[int] = None
        self._last_change = time.monotonic()
        self._pressed = False
        self._last_trigger = 0.0

    def _debounced(self, now: float) -> bool:
        if self._last_state == self._config.active_level:
            return (now - self._last_change) >= self._config.debounce_seconds
        return (now - self._last_change) >= self._config.release_debounce_seconds

    def _trigger(self, now: float) -> None:
        payload = {
            "type": "button_pressed",
            "button_id": self._config.button_id,
            "screen": self._config.screen,
            "panel": self._config.panel,
            "sessionId": f"{self._config.session_prefix}-{uuid.uuid4().hex}",
        }
        headers = {"Content-Type": "application/json"}
        if self._config.token:
            headers["Authorization"] = f"Bearer {self._config.token}"

        self._logger.info(
            "Detected button press on %s:%d (buttonId=%d). Dispatching to %s",
            self._config.chip,
            self._config.line_offset,
            self._config.button_id,
            self._config.webhook,
        )

        try:
            response = requests.post(
                self._config.webhook,
                json=payload,
                headers=headers,
                timeout=self._config.timeout,
            )
            response.raise_for_status()
            self._logger.debug("Backend acknowledged button press: %s", response.text)
        except requests.RequestException:
            self._logger.exception("Failed to deliver button press to backend.")

        self._last_trigger = now
        if self._config.require_release:
            self._pressed = True

    def _maybe_reset(self, now: float) -> None:
        if not self._pressed:
            return
        if self._last_state != self._config.active_level and self._debounced(now):
            self._logger.debug("Button released; ready for next press.")
            self._pressed = False

    def poll(self) -> None:
        try:
            value = self._reader.read()
        except GPIOBackendError:
            raise
        except Exception as exc:  # pragma: no cover - defensive logging
            raise GPIOBackendError(f"Failed to read GPIO state: {exc}") from exc

        now = time.monotonic()
        if value != self._last_state:
            self._logger.debug("GPIO state changed: %s -> %s", self._last_state, value)
            self._last_state = value
            self._last_change = now

        if value == self._config.active_level:
            if self._config.require_release and self._pressed:
                return
            if not self._debounced(now):
                return
            if self._config.cooldown_seconds > 0.0 and (now - self._last_trigger) < self._config.cooldown_seconds:
                return
            self._trigger(now)
        else:
            self._maybe_reset(now)

    def close(self) -> None:
        self._reader.close()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="GPIO button listener triggering Control Tab events.")
    parser.add_argument("--chip", default=os.getenv("GPIO_BUTTON_CHIP", "gpiochip2"))
    parser.add_argument("--line", type=int, default=_env_int("GPIO_BUTTON_LINE", 0))
    parser.add_argument("--active-level", type=int, choices=[0, 1], default=_env_int("GPIO_BUTTON_ACTIVE_LEVEL", 1))
    parser.add_argument("--debounce-ms", type=float, default=_env_float("GPIO_BUTTON_DEBOUNCE_MS", 50.0))
    parser.add_argument(
        "--release-debounce-ms",
        type=float,
        default=_env_float("GPIO_BUTTON_RELEASE_DEBOUNCE_MS", _env_float("GPIO_BUTTON_DEBOUNCE_MS", 50.0)),
    )
    parser.add_argument("--poll-interval", type=float, default=_env_float("GPIO_BUTTON_POLL_INTERVAL", 0.05))
    parser.add_argument("--cooldown-ms", type=float, default=_env_float("GPIO_BUTTON_COOLDOWN_MS", 0.0))
    parser.add_argument("--button-id", type=int, default=_env_int("GPIO_BUTTON_ID", 1))
    parser.add_argument("--screen", type=int, default=_env_int("GPIO_BUTTON_SCREEN", 0))
    parser.add_argument("--panel", type=int, default=_env_int("GPIO_BUTTON_PANEL", 0))
    parser.add_argument("--webhook", default=os.getenv("GPIO_BUTTON_WEBHOOK", DEFAULT_WEBHOOK))
    parser.add_argument("--token", default=os.getenv("GPIO_BUTTON_TOKEN"))
    parser.add_argument("--timeout", type=float, default=_env_float("GPIO_BUTTON_TIMEOUT", 3.0))
    parser.add_argument("--session-prefix", default=os.getenv("GPIO_BUTTON_SESSION_PREFIX", "gpio"))
    parser.add_argument("--backend", default=os.getenv("GPIO_BUTTON_BACKEND", "auto"))
    parser.add_argument("--consumer", default=os.getenv("GPIO_BUTTON_CONSUMER", DEFAULT_CONSUMER))
    parser.add_argument(
        "--require-release",
        action=argparse.BooleanOptionalAction,
        default=_env_flag("GPIO_BUTTON_REQUIRE_RELEASE", True),
        help="Require the button to be released before triggering again.",
    )
    parser.add_argument(
        "--log-level",
        default=os.getenv("GPIO_BUTTON_LOG_LEVEL", "INFO"),
        help="Logging level (DEBUG, INFO, WARNING, ERROR).",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    logging.basicConfig(
        level=getattr(logging, str(args.log_level).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(name)s :: %(message)s",
    )
    logger = logging.getLogger("gpio_button_listener")

    config = ButtonConfig(
        chip=args.chip,
        line_offset=args.line,
        active_level=args.active_level,
        debounce_seconds=max(args.debounce_ms / 1000.0, 0.0),
        release_debounce_seconds=max(args.release_debounce_ms / 1000.0, 0.0),
        poll_interval=max(args.poll_interval, 0.01),
        cooldown_seconds=max(args.cooldown_ms / 1000.0, 0.0),
        button_id=args.button_id,
        screen=args.screen,
        panel=args.panel,
        webhook=args.webhook or DEFAULT_WEBHOOK,
        token=args.token,
        timeout=max(args.timeout, 0.5),
        session_prefix=args.session_prefix,
        backend=args.backend,
        consumer=args.consumer,
        require_release=bool(args.require_release),
    )

    try:
        reader = build_reader(config.chip, config.line_offset, config.consumer, config.backend)
    except GPIOBackendError as exc:
        logger.error("Failed to initialise GPIO backend: %s", exc)
        return 1

    watcher = ButtonWatcher(reader, config, logger)

    terminate = False

    def _handle_signal(signum: int, frame: object) -> None:  # pragma: no cover - signal handling
        nonlocal terminate
        logger.info("Received signal %s, shutting down.", signum)
        terminate = True

    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            signal.signal(sig, _handle_signal)
        except ValueError:
            # Signal handling not supported in some threading contexts.
            pass

    logger.info(
        "Listening for GPIO button presses on %s:%d (active=%d, debounce=%.0f ms, poll=%.0f ms).",
        config.chip,
        config.line_offset,
        config.active_level,
        config.debounce_seconds * 1000.0,
        config.poll_interval * 1000.0,
    )

    try:
        while not terminate:
            watcher.poll()
            time.sleep(config.poll_interval)
    except GPIOBackendError as exc:
        logger.error("Stopping due to GPIO error: %s", exc)
        return 1
    finally:
        watcher.close()
        logger.info("GPIO button listener stopped.")

    return 0


if __name__ == "__main__":  # pragma: no cover - CLI entrypoint
    sys.exit(main())

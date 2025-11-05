#!/usr/bin/env python3
"""Poll a GPIO line and trigger a JSVV sequence when a button is pressed."""

from __future__ import annotations

import argparse
import json
import logging
import os
import signal
import subprocess
import sys
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Optional


PROJECT_ROOT = Path(__file__).resolve().parents[2]
PYTHON_CLIENT_ROOT = PROJECT_ROOT / "python-client"
DEFAULT_CONSUMER = "gpio-button-listener"


class ButtonReaderError(RuntimeError):
    """Raised when the GPIO backend encounters an unrecoverable error."""


class ButtonReader:
    """Abstract reader that returns the current button value (0 or 1)."""

    def read(self) -> int:
        raise NotImplementedError

    def close(self) -> None:  # pragma: no cover - backends may not require cleanup
        pass


def _extend_sys_path_with_site_packages(base_dir: Path) -> None:
    venv_dir = base_dir / ".venv"
    if not venv_dir.exists():
        return
    for pattern in ("lib/python*/site-packages", "lib64/python*/site-packages"):
        for candidate in venv_dir.glob(pattern):
            candidate_path = str(candidate)
            if candidate.is_dir() and candidate_path not in sys.path:
                sys.path.insert(0, candidate_path)


def _import_gpiod() -> Optional[object]:
    try:
        import gpiod  # type: ignore[import]
        return gpiod
    except ModuleNotFoundError:
        pass

    _extend_sys_path_with_site_packages(PYTHON_CLIENT_ROOT)

    extra_paths: list[str] = []
    env_extra = os.environ.get("MODBUS_RS485_GPIO_PYTHONPATH", "")
    if env_extra:
        extra_paths.extend(path for path in env_extra.split(":") if path)

    env_extra = os.environ.get("GPIO_BUTTON_PYTHONPATH", "")
    if env_extra:
        extra_paths.extend(path for path in env_extra.split(":") if path)

    appended = False
    for path_str in extra_paths:
        if not path_str or path_str in sys.path:
            continue
        path_obj = Path(path_str)
        if path_obj.exists():
            sys.path.insert(0, path_str)
            appended = True

    if appended:
        try:
            import gpiod  # type: ignore[import]
            return gpiod
        except ModuleNotFoundError:
            return None

    return None


class GpiodButtonReader(ButtonReader):
    """GPIO reader using Python bindings for libgpiod."""

    def __init__(self, chip_name: str, line_offset: int, consumer: str) -> None:
        gpiod_module = _import_gpiod()
        if gpiod_module is None:
            raise ButtonReaderError("gpiod module not available")

        self._chip_name = chip_name
        self._line_offset = line_offset

        if hasattr(gpiod_module, "request_lines"):
            from gpiod import line as line_mod  # type: ignore[import]

            try:
                config = {
                    line_offset: gpiod_module.LineSettings(direction=line_mod.Direction.INPUT)
                }
                self._request = gpiod_module.request_lines(
                    chip_name,
                    consumer=consumer,
                    config=config,
                )
            except Exception as exc:  # pragma: no cover - environment specific
                raise ButtonReaderError(str(exc)) from exc

            self._reader = lambda: int(self._request.get_value(line_offset))
            self._closer = self._request.release
        else:  # pragma: no cover - legacy libgpiod v1 fallback
            try:
                chip = gpiod_module.Chip(chip_name)
                line_obj = chip.get_line(line_offset)
                line_obj.request(consumer=consumer, type=gpiod_module.LINE_REQ_DIR_IN)
            except Exception as exc:
                raise ButtonReaderError(str(exc)) from exc

            def closer() -> None:
                try:
                    line_obj.release()
                finally:
                    chip.close()

            self._request = None
            self._reader = lambda: int(line_obj.get_value())
            self._closer = closer

    def read(self) -> int:
        try:
            value = self._reader()
        except OSError as exc:
            raise ButtonReaderError(str(exc)) from exc

        if value not in (0, 1):
            raise ButtonReaderError(f"Unexpected GPIO value: {value}")
        return value

    def close(self) -> None:  # pragma: no cover - backend cleanup
        try:
            self._closer()
        except Exception:
            pass


class GpiogetButtonReader(ButtonReader):
    """GPIO reader that shells out to the gpioget binary."""

    def __init__(self, chip_name: str, line_offset: int) -> None:
        self._chip_name = chip_name
        self._line_offset = line_offset

    def read(self) -> int:
        command = ["gpioget", self._chip_name, str(self._line_offset)]
        completed = subprocess.run(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            text=True,
        )
        if completed.returncode != 0:
            message = completed.stderr.strip() or completed.stdout.strip() or "unknown error"
            raise ButtonReaderError(f"gpioget returned {completed.returncode}: {message}")

        value_str = completed.stdout.strip()
        if value_str not in {"0", "1"}:
            raise ButtonReaderError(f"Unexpected gpioget output: {value_str!r}")
        return int(value_str)


class DebouncedEdgeDetector:
    """Detect a rising edge that remains active for the configured debounce window."""

    def __init__(self, active_value: int, debounce_seconds: float, initial_value: Optional[int] = None) -> None:
        self._active_value = 1 if active_value else 0
        self._debounce_seconds = max(0.0, debounce_seconds)
        self._press_started_at: float | None = None
        self._armed = True
        if initial_value == self._active_value:
            # Prevent immediately treating a steady active level as a new press
            self._armed = False

    def update(self, value: int, now: Optional[float] = None) -> bool:
        now = now if now is not None else time.monotonic()

        if value == self._active_value:
            if not self._armed:
                return False
            if self._press_started_at is None:
                self._press_started_at = now
                return False
            if now - self._press_started_at >= self._debounce_seconds:
                self._armed = False
                return True
            return False

        self._press_started_at = None
        self._armed = True
        return False

    def reset(self) -> None:
        self._press_started_at = None
        self._armed = True


@dataclass(slots=True)
class ButtonConfig:
    chip: str
    line: int
    chip_hint: str | None
    active_value: int
    debounce_seconds: float
    poll_interval: float
    backend: str
    sequence: str
    priority: int | None
    remote: int | None
    repeat: int | None
    repeat_delay: float | None
    targets: list[str]
    python_bin: str
    python_path: str | None
    log_level: int
    consumer: str = DEFAULT_CONSUMER

    @property
    def modbus_control(self) -> Path:
        return PYTHON_CLIENT_ROOT / "modbus_control.py"


def parse_bool(value: str | None) -> Optional[bool]:
    if value is None:
        return None
    lowered = value.strip().lower()
    if lowered in {"1", "true", "yes", "on"}:
        return True
    if lowered in {"0", "false", "no", "off"}:
        return False
    return None


def parse_optional_int(value: Optional[str]) -> Optional[int]:
    if value is None or value.strip() == "":
        return None
    try:
        return int(value, 0)
    except ValueError as exc:
        raise SystemExit(f"Invalid integer value: {value}") from exc


def parse_targets(value: Optional[str]) -> list[str]:
    if not value:
        return []
    normalised = value.replace(",", " ").replace(";", " ")
    parts = [part for part in normalised.split() if part]
    return parts


def parse_log_level(value: Optional[str]) -> int:
    default_level = logging.INFO
    if not value:
        return default_level
    level = getattr(logging, value.strip().upper(), None)
    if isinstance(level, int):
        return level
    raise SystemExit(f"Invalid log level: {value}")


def load_config(args: argparse.Namespace) -> ButtonConfig:
    env = os.environ

    chip = args.chip or env.get("GPIO_BUTTON_CHIP") or env.get("GPIO_BUTTON_CHIP_HINT")
    if not chip:
        raise SystemExit("GPIO_BUTTON_CHIP must be configured (env or CLI).")

    chip_hint = args.chip_hint or env.get("GPIO_BUTTON_CHIP_HINT")

    line_value = args.line if args.line is not None else env.get("GPIO_BUTTON_LINE")
    if line_value is None:
        raise SystemExit("GPIO_BUTTON_LINE must be configured (env or CLI).")
    try:
        line = int(line_value, 0)
    except ValueError as exc:
        raise SystemExit(f"Invalid GPIO_BUTTON_LINE value: {line_value}") from exc

    debounce_ms = args.debounce_ms if args.debounce_ms is not None else env.get("GPIO_BUTTON_DEBOUNCE_MS", "75")
    try:
        debounce_ms_value = float(debounce_ms)
    except ValueError as exc:
        raise SystemExit(f"Invalid GPIO_BUTTON_DEBOUNCE_MS value: {debounce_ms}") from exc
    debounce_seconds = max(0.0, debounce_ms_value / 1000.0)

    poll_interval = args.poll_interval if args.poll_interval is not None else env.get("GPIO_BUTTON_POLL_INTERVAL", "0.05")
    try:
        poll_interval_seconds = max(0.01, float(poll_interval))
    except ValueError as exc:
        raise SystemExit(f"Invalid GPIO_BUTTON_POLL_INTERVAL value: {poll_interval}") from exc

    backend = (args.backend or env.get("GPIO_BUTTON_BACKEND") or "auto").strip().lower()

    sequence = args.sequence or env.get("GPIO_BUTTON_SEQUENCE")
    if not sequence:
        raise SystemExit("GPIO_BUTTON_SEQUENCE must be configured (env or CLI).")

    priority = args.priority if args.priority is not None else parse_optional_int(env.get("GPIO_BUTTON_PRIORITY"))
    remote = args.remote if args.remote is not None else parse_optional_int(env.get("GPIO_BUTTON_REMOTE"))
    repeat = args.repeat if args.repeat is not None else parse_optional_int(env.get("GPIO_BUTTON_REPEAT"))
    repeat_delay = args.repeat_delay if args.repeat_delay is not None else env.get("GPIO_BUTTON_REPEAT_DELAY")
    repeat_delay_value: Optional[float]
    if repeat_delay is None:
        repeat_delay_value = None
    else:
        try:
            repeat_delay_value = float(repeat_delay)
        except ValueError as exc:
            raise SystemExit(f"Invalid GPIO_BUTTON_REPEAT_DELAY value: {repeat_delay}") from exc

    if args.targets:
        targets = list(args.targets)
    else:
        targets = parse_targets(env.get("GPIO_BUTTON_TARGETS"))

    python_bin = args.python_bin or env.get("GPIO_BUTTON_PYTHON_BIN") or env.get("PYTHON_BIN") or sys.executable
    python_path = args.python_path or env.get("GPIO_BUTTON_PYTHONPATH") or env.get("PYTHONPATH")

    log_level = parse_log_level(args.log_level or env.get("GPIO_BUTTON_LOG_LEVEL"))

    active_value: int
    if args.active_value is not None:
        active_value = 1 if args.active_value else 0
    else:
        explicit = parse_optional_int(env.get("GPIO_BUTTON_ACTIVE_VALUE"))
        if explicit is not None:
            active_value = 1 if explicit else 0
        else:
            active_high = parse_bool(env.get("GPIO_BUTTON_ACTIVE_HIGH"))
            active_value = 1 if active_high is not False else 0

    return ButtonConfig(
        chip=chip,
        line=line,
        chip_hint=chip_hint,
        active_value=active_value,
        debounce_seconds=debounce_seconds,
        poll_interval=poll_interval_seconds,
        backend=backend,
        sequence=sequence,
        priority=priority,
        remote=remote,
        repeat=repeat,
        repeat_delay=repeat_delay_value,
        targets=targets,
        python_bin=python_bin,
        python_path=python_path,
        log_level=log_level,
    )


def setup_logging(level: int) -> logging.Logger:
    logging.basicConfig(
        level=level,
        format="%(asctime)s %(levelname)s %(name)s :: %(message)s",
    )
    return logging.getLogger("gpio_button_listener")


def build_reader(config: ButtonConfig, logger: logging.Logger) -> ButtonReader:
    backends: list[str]
    backend = config.backend.lower()
    if backend == "auto":
        backends = ["gpiod", "gpioget"]
    else:
        backends = [backend]

    chip_candidates = [config.chip]
    if config.chip_hint and config.chip_hint not in chip_candidates:
        chip_candidates.append(config.chip_hint)

    last_error: Optional[Exception] = None

    for backend_name in backends:
        for candidate_chip in chip_candidates:
            try:
                if backend_name == "gpiod":
                    reader = GpiodButtonReader(candidate_chip, config.line, config.consumer)
                    if candidate_chip != config.chip:
                        logger.warning("Primary chip %s unavailable, using hint %s", config.chip, candidate_chip)
                    return reader
                if backend_name == "gpioget":
                    completed = subprocess.run(
                        ["which", "gpioget"],
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE,
                        check=False,
                        text=True,
                    )
                    if completed.returncode != 0:
                        raise ButtonReaderError("gpioget binary not found in PATH")
                    reader = GpiogetButtonReader(candidate_chip, config.line)
                    if candidate_chip != config.chip:
                        logger.warning("Primary chip %s unavailable, using hint %s", config.chip, candidate_chip)
                    return reader
                raise SystemExit(f"Unsupported GPIO backend: {backend_name}")
            except Exception as exc:
                last_error = exc
                logger.warning("Failed to initialise %s backend on %s:%d: %s", backend_name, candidate_chip, config.line, exc)

    if last_error is None:
        raise SystemExit("Unable to initialise any GPIO backend.")
    raise SystemExit(f"Unable to initialise GPIO backend ({config.backend}): {last_error}")


def build_jsvv_command(config: ButtonConfig) -> list[str]:
    command = [config.python_bin, str(config.modbus_control), "jsvv-send", "--sequence", config.sequence]
    if config.priority is not None:
        command.extend(["--priority", str(config.priority)])
    if config.remote is not None:
        command.extend(["--remote", str(config.remote)])
    if config.repeat is not None:
        command.extend(["--repeat", str(config.repeat)])
    if config.repeat_delay is not None:
        command.extend(["--repeat-delay", str(config.repeat_delay)])
    if config.targets:
        command.append("--targets")
        command.extend(config.targets)
    return command


def dispatch_jsvv(command: Iterable[str], python_path: Optional[str], logger: logging.Logger) -> bool:
    env = os.environ.copy()
    if python_path:
        env["PYTHONPATH"] = python_path

    command_list = list(command)
    logger.info("Dispatching JSVV sequence %s", next(val for idx, val in enumerate(command_list) if command_list[idx - 1] == "--sequence"))

    completed = subprocess.run(
        command_list,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        text=True,
        env=env,
    )

    if completed.returncode != 0:
        stderr = completed.stderr.strip()
        logger.error("jsvv-send exited with %d: %s", completed.returncode, stderr or completed.stdout.strip())
        return False

    output = completed.stdout.strip()
    if not output:
        logger.warning("jsvv-send produced no output.")
        return True

    try:
        response = json.loads(output)
    except json.JSONDecodeError as exc:
        logger.error("Failed to parse jsvv-send output as JSON: %s", output)
        raise SystemExit("Invalid JSON returned by jsvv-send") from exc

    status = response.get("status", "unknown")
    logger.info("JSVV command status: %s", status)

    warnings = response.get("warnings") or []
    if warnings:
        logger.warning("modbus_control warnings: %s", " ".join(str(item) for item in warnings))

    errors = response.get("errors") or []
    if errors:
        logger.error("modbus_control errors: %s", " ".join(str(item) for item in errors))

    return status == "ok"


def wait_for_release(
    reader: ButtonReader,
    detector: DebouncedEdgeDetector,
    config: ButtonConfig,
    stop_event: threading.Event,
    logger: logging.Logger,
) -> None:
    release_logged = False
    while not stop_event.is_set():
        try:
            value = reader.read()
        except ButtonReaderError as exc:
            logger.warning("GPIO read failed while waiting for release: %s", exc)
            stop_event.wait(config.poll_interval)
            continue
        if value != config.active_value:
            detector.reset()
            if not release_logged:
                logger.debug("Button released, ready for next trigger.")
                release_logged = True
            return
        stop_event.wait(config.poll_interval)


def run_loop(config: ButtonConfig, reader: ButtonReader, logger: logging.Logger) -> None:
    stop_event = threading.Event()

    def _handle_signal(signum: int, _frame: object) -> None:
        logger.info("Received signal %s, shutting down.", signum)
        stop_event.set()

    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, _handle_signal)

    try:
        initial_value = reader.read()
    except ButtonReaderError as exc:
        logger.warning("Unable to read initial GPIO state: %s (assuming inactive)", exc)
        initial_value = 1 - config.active_value

    detector = DebouncedEdgeDetector(config.active_value, config.debounce_seconds, initial_value)
    if initial_value == config.active_value:
        logger.info(
            "GPIO button initialised in active state (%d); waiting for release before enabling triggers.",
            config.active_value,
        )
    else:
        logger.debug(
            "GPIO button initialised in inactive state (%d).",
            1 - config.active_value,
        )
    poll_seconds = config.poll_interval
    command = build_jsvv_command(config)

    logger.info(
        "Listening for GPIO button presses on %s:%d (active=%d, debounce=%d ms, poll=%d ms).",
        config.chip,
        config.line,
        config.active_value,
        int(config.debounce_seconds * 1000),
        int(poll_seconds * 1000),
    )
    targets_desc = config.targets if config.targets else ["default"]
    priority_desc = "default" if config.priority is None else str(config.priority)
    remote_desc = "default" if config.remote is None else str(config.remote)
    repeat_desc = "default" if config.repeat is None else str(config.repeat)
    delay_desc = "default" if config.repeat_delay is None else str(config.repeat_delay)

    logger.info(
        "Configured JSVV sequence: %s (priority=%s, remote=%s, repeat=%s, delay=%s, targets=%s)",
        config.sequence,
        priority_desc,
        remote_desc,
        repeat_desc,
        delay_desc,
        targets_desc,
    )

    value = initial_value
    while not stop_event.is_set():
        if detector.update(value):
            logger.info(
                "Detected button press on %s:%d. Dispatching JSVV sequence %s",
                config.chip,
                config.line,
                config.sequence,
            )
            try:
                dispatch_jsvv(command, config.python_path, logger)
            except SystemExit:
                stop_event.set()
                break
            wait_for_release(reader, detector, config, stop_event, logger)

        if stop_event.wait(poll_seconds):
            break

        try:
            value = reader.read()
        except ButtonReaderError as exc:
            logger.error("Stopping due to GPIO error: %s", exc)
            stop_event.set()
            break

    reader.close()
    logger.info("GPIO button listener stopped.")


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="GPIO button daemon for triggering JSVV sequences.")
    parser.add_argument("--chip", help="GPIO chip name (env: GPIO_BUTTON_CHIP)")
    parser.add_argument("--chip-hint", help="Optional fallback chip name (env: GPIO_BUTTON_CHIP_HINT)")
    parser.add_argument("--line", type=lambda value: int(value, 0), help="GPIO line offset (env: GPIO_BUTTON_LINE)")
    parser.add_argument("--active-value", type=int, choices=[0, 1], help="Button active value (env: GPIO_BUTTON_ACTIVE_VALUE / GPIO_BUTTON_ACTIVE_HIGH)")
    parser.add_argument("--debounce-ms", type=float, help="Debounce window in milliseconds (env: GPIO_BUTTON_DEBOUNCE_MS)")
    parser.add_argument("--poll-interval", type=float, help="Polling interval in seconds (env: GPIO_BUTTON_POLL_INTERVAL)")
    parser.add_argument("--backend", help="GPIO backend (auto, gpiod, gpioget) (env: GPIO_BUTTON_BACKEND)")
    parser.add_argument("--sequence", help="JSVV sequence to send (env: GPIO_BUTTON_SEQUENCE)")
    parser.add_argument("--priority", type=lambda value: int(value, 0), help="Priority register value (env: GPIO_BUTTON_PRIORITY)")
    parser.add_argument("--remote", type=lambda value: int(value, 0), help="Remote device address (env: GPIO_BUTTON_REMOTE)")
    parser.add_argument("--repeat", type=lambda value: int(value, 0), help="Repeat count (env: GPIO_BUTTON_REPEAT)")
    parser.add_argument("--repeat-delay", type=float, help="Delay between repeats in seconds (env: GPIO_BUTTON_REPEAT_DELAY)")
    parser.add_argument("--targets", nargs="+", help="Target nest addresses (env: GPIO_BUTTON_TARGETS)")
    parser.add_argument("--python-bin", help="Python interpreter for modbus_control.py (env: PYTHON_BIN / GPIO_BUTTON_PYTHON_BIN)")
    parser.add_argument("--python-path", help="PYTHONPATH while invoking modbus_control.py (env: PYTHONPATH / GPIO_BUTTON_PYTHONPATH)")
    parser.add_argument("--log-level", help="Logging level (env: GPIO_BUTTON_LOG_LEVEL)")
    return parser


def main(argv: Optional[list[str]] = None) -> int:
    parser = build_arg_parser()
    args = parser.parse_args(argv)

    config = load_config(args)
    logger = setup_logging(config.log_level)

    reader = build_reader(config, logger)
    run_loop(config, reader, logger)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

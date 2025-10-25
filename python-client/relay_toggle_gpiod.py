#!/usr/bin/env python3
"""Relay toggle that keeps control and monitoring inside a single Python process."""

from __future__ import annotations

import os
import select
import sys
import time
from pathlib import Path
from typing import Callable


CHIP = "gpiochip0"
PIN = 16
CONSUMER = "relay-toggle-gpiod"
POLL_INTERVAL = 0.5  # seconds


def _import_gpiod():
    try:
        import gpiod  # type: ignore[import]
        return gpiod
    except ModuleNotFoundError:
        pass

    project_root = Path(__file__).resolve().parents[1]
    venv_dir = project_root / ".venv"
    extra_paths: list[str] = []
    if venv_dir.exists():
        for pattern in ("lib/python*/site-packages", "lib64/python*/site-packages"):
            for candidate in venv_dir.glob(pattern):
                extra_paths.append(str(candidate))

    env_extra = os.environ.get("MODBUS_RS485_GPIO_PYTHONPATH", "")
    if env_extra:
        extra_paths.extend(path for path in env_extra.split(":") if path)

    appended = False
    for path_str in extra_paths:
        if path_str and path_str not in sys.path and Path(path_str).exists():
            sys.path.insert(0, path_str)
            appended = True

    if not appended:
        raise SystemExit(
            "gpiod module not found. Install it (e.g. `pip install gpiod`) or point MODBUS_RS485_GPIO_PYTHONPATH to it."
        )

    try:
        import gpiod  # type: ignore[import]
    except ModuleNotFoundError as exc:  # pragma: no cover - environment specific
        raise SystemExit(
            "gpiod module not found even after extending sys.path. Install it (e.g. `pip install gpiod`)."
        ) from exc

    return gpiod  # type: ignore[name-defined]


class RelayLine:
    """Wrap GPIO line control for both libgpiod v1 and v2."""

    def __init__(self, chip_name: str, line_offset: int, consumer: str) -> None:
        gpiod = _import_gpiod()
        self.chip_name = chip_name
        self.line_offset = line_offset

        self._set_value: Callable[[bool], None]
        self._get_value: Callable[[], int]
        self._release: Callable[[], None]

        if hasattr(gpiod, "request_lines"):
            from gpiod import line  # type: ignore[import]

            config = {
                line_offset: gpiod.LineSettings(direction=line.Direction.OUTPUT, output_value=line.Value.INACTIVE)
            }
            request = gpiod.request_lines(chip_name, consumer=consumer, config=config)
            self._active = line.Value.ACTIVE
            self._inactive = line.Value.INACTIVE
            self._set_value = lambda active: request.set_value(
                line_offset, self._active if active else self._inactive
            )
            self._get_value = lambda: int(request.get_value(line_offset) == self._active)
            self._release = request.release
        else:  # pragma: no cover - legacy libgpiod v1 fallback
            chip = gpiod.Chip(chip_name)
            line_obj = chip.get_line(line_offset)
            line_obj.request(consumer=consumer, type=gpiod.LINE_REQ_DIR_OUT)
            self._set_value = lambda active: line_obj.set_value(1 if active else 0)
            self._get_value = lambda: int(line_obj.get_value())

            def release() -> None:
                line_obj.release()
                chip.close()

            self._release = release

    def drive(self, active: bool) -> None:
        self._set_value(active)

    def read(self) -> int:
        return self._get_value()

    def close(self) -> None:
        self._release()


def _monitor_until_enter(line: RelayLine, interval: float) -> None:
    """Display live pin state while waiting for the user to hit Enter."""

    print(f"Sleduji {line.chip_name}:{line.line_offset}. Stiskni Enter pro rozepnutí (Ctrl+C pro ukončení).")
    last_print = time.monotonic() - interval
    while True:
        timeout = max(0.0, interval - (time.monotonic() - last_print))
        ready, _, _ = select.select([sys.stdin], [], [], timeout)
        if ready:
            sys.stdin.readline()
            break
        value = line.read()
        print(f"\rGPIO {line.chip_name}:{line.line_offset} = {value}", end="", flush=True)
        last_print = time.monotonic()
    print()  # finish the status line cleanly


def main() -> None:
    line = RelayLine(CHIP, PIN, CONSUMER)
    try:
        line.drive(True)
        print("Relé sepnuté.")
        _monitor_until_enter(line, POLL_INTERVAL)
    except KeyboardInterrupt:
        print("\nPřerušeno uživatelem, uvolňuji relé...")
    finally:
        try:
            line.drive(False)
            print(f"GPIO {line.chip_name}:{line.line_offset} = {line.read()} (relé rozepnuté)")
        finally:
            line.close()


if __name__ == "__main__":  # pragma: no cover - manual utility
    main()

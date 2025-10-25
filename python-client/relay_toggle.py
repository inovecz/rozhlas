#!/usr/bin/env python3
"""Simple helper that toggles a relay via gpioset shell commands."""

from __future__ import annotations

import subprocess


CHIP = "gpiochip0"
PIN = 16


def _run_gpioset(level: int) -> None:
    command = ["gpioset", CHIP, f"{PIN}={level}"]
    try:
        subprocess.run(command, check=True)
    except subprocess.CalledProcessError as exc:
        raise SystemExit(f"gpioset failed with exit code {exc.returncode}") from exc


def _start_gpioset(level: int) -> subprocess.Popen[bytes]:
    command = ["gpioset", "--mode=signal", CHIP, f"{PIN}={level}"]
    try:
        process = subprocess.Popen(command)
    except FileNotFoundError as exc:
        raise SystemExit("gpioset executable not found") from exc

    returncode = process.poll()
    if returncode is not None:
        raise SystemExit(f"gpioset failed to start (exit code {returncode})")
    return process


def _stop_gpioset(process: subprocess.Popen[bytes]) -> None:
    process.terminate()
    try:
        process.wait(timeout=2)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait()


def main() -> None:
    gpioset_process = _start_gpioset(1)
    try:
        input("Relé sepnuté. Stiskni Enter pro rozepnutí...")
    finally:
        _stop_gpioset(gpioset_process)
        _run_gpioset(0)


if __name__ == "__main__":  # pragma: no cover - manual utility
    main()

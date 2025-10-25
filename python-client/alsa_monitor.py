#!/usr/bin/env python3
"""Watch ALSA mixer changes in real time while tweaking live-broadcast settings."""

from __future__ import annotations

import argparse
import os
import select
import subprocess
import sys
import time
from pathlib import Path
from typing import Iterable


ROOT = Path(__file__).resolve().parent
SNAPSHOT_PATH = ROOT / "alsa_snapshot.py"
if ROOT.exists() and str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

try:
    from alsa_snapshot import snapshot  # type: ignore[import]
except Exception as exc:  # pragma: no cover - local import should succeed
    raise SystemExit(f"Unable to import alsa_snapshot helper: {exc}") from exc


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Continuously print ALSA mixer snapshots whenever controls change."
    )
    parser.add_argument(
        "--card",
        default=os.environ.get("ALSAMIXER_CARD", "0"),
        help="ALSA card index/name to monitor (default: env ALSAMIXER_CARD or 0).",
    )
    parser.add_argument(
        "--controls",
        nargs="+",
        help="Optional specific control names to snapshot (default: list all controls).",
    )
    parser.add_argument(
        "--debounce",
        type=float,
        default=0.2,
        help="Minimum seconds between snapshots to avoid flooding (default: 0.2).",
    )
    parser.add_argument(
        "--quiet-events",
        action="store_true",
        help="Suppress raw alsactl event lines; only print snapshots.",
    )
    return parser


def _spawn_monitor(card: str) -> subprocess.Popen[str]:
    command = ["alsactl", "monitor", card]
    try:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    except FileNotFoundError as exc:
        raise SystemExit("alsactl executable not found; install alsa-utils.") from exc
    if process.stdout is None:
        raise SystemExit("Failed to capture alsactl output.")
    return process


def _read_lines(process: subprocess.Popen[str]):
    """Yield lines from alsactl stdout until the process stops."""

    stdout = process.stdout
    assert stdout is not None

    poller = select.poll()
    poller.register(stdout, select.POLLIN)
    while True:
        if process.poll() is not None:
            break
        events = poller.poll(100)
        if not events:
            continue
        line = stdout.readline()
        if line == "":
            break
        yield line.rstrip()


def monitor(card: str, controls: Iterable[str] | None, debounce: float, quiet_events: bool) -> None:
    process = _spawn_monitor(card)
    try:
        print(f"Watching ALSA card '{card}'. Press Ctrl+C to stop.\n")
        last_snapshot = 0.0
        # Print initial snapshot.
        print(snapshot(card, controls))
        for line in _read_lines(process):
            if not quiet_events:
                print(f"[event] {line}")
            now = time.monotonic()
            if now - last_snapshot < debounce:
                continue
            last_snapshot = now
            print(snapshot(card, controls))
    except KeyboardInterrupt:
        print("\nStopping monitor...")
    finally:
        try:
            process.terminate()
            process.wait(timeout=1)
        except Exception:
            pass


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    monitor(args.card, args.controls, args.debounce, args.quiet_events)


if __name__ == "__main__":  # pragma: no cover - CLI helper
    main()

#!/usr/bin/env python3
"""Print a quick ALSA mixer snapshot so you can verify live-broadcast settings."""

from __future__ import annotations

import argparse
import os
import re
import shutil
import subprocess
from typing import Iterable


def _run_command(cmd: list[str]) -> str:
    try:
        completed = subprocess.run(cmd, check=True, capture_output=True, text=True)
    except FileNotFoundError as exc:
        raise SystemExit(f"Command not found: {cmd[0]} (install alsa-utils?)") from exc
    except subprocess.CalledProcessError as exc:
        raise SystemExit(f"Command {' '.join(cmd)} failed with exit code {exc.returncode}:\n{exc.stderr}") from exc
    return completed.stdout


def _list_cards() -> str:
    if shutil.which("aplay") is None:
        raise SystemExit("aplay executable not found; install alsa-utils to list cards.")
    return _run_command(["aplay", "-l"])


def _detect_controls(card: str) -> list[str]:
    output = _run_command(["amixer", "-c", card, "scontrols"])
    controls: list[str] = []
    pattern = re.compile(r"Simple mixer control '(.+?)'")
    for line in output.splitlines():
        match = pattern.search(line)
        if match:
            controls.append(match.group(1))
    if not controls:
        raise SystemExit(f"No simple controls reported for card '{card}'.")
    return controls


def _summarize_control(card: str, control: str) -> str:
    output = _run_command(["amixer", "-c", card, "sget", control])
    interesting: list[str] = []
    for raw_line in output.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("Simple mixer control") or line.startswith("Capabilities"):
            continue
        if line.startswith("Limits") or line.startswith("Playback channels") or line.startswith("Capture channels"):
            continue
        if any(keyword in line for keyword in ("Playback", "Capture", "Item0", "Item1", "Item2", "Item3")):
            interesting.append(f"  {line}")
    if not interesting:
        interesting.append("  (no playback/capture fields reported)")
    header = f"{control}"
    underline = "-" * len(header)
    return "\n".join((header, underline, *interesting))


def snapshot(card: str, controls: Iterable[str] | None = None) -> str:
    if shutil.which("amixer") is None:
        raise SystemExit("amixer executable not found; install alsa-utils.")

    control_list = list(controls) if controls else _detect_controls(card)
    lines = [f"# ALSA snapshot for card '{card}'", ""]
    for control in control_list:
        lines.append(_summarize_control(card, control))
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Show current ALSA mixer settings (volumes, switches, mux inputs).")
    parser.add_argument(
        "--card",
        default=os.environ.get("ALSAMIXER_CARD", "0"),
        help="Target ALSA card index or name (default: env ALSAMIXER_CARD or 0).",
    )
    parser.add_argument(
        "--controls",
        nargs="+",
        help="Optional list of control names to query (default: show every simple control).",
    )
    parser.add_argument("--list-cards", action="store_true", help="List ALSA cards and exit.")
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()

    if args.list_cards:
        print(_list_cards())
        return

    report = snapshot(args.card, controls=args.controls)
    print(report, end="")


if __name__ == "__main__":  # pragma: no cover - CLI helper
    main()

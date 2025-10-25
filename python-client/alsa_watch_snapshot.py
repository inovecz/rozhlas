#!/usr/bin/env python3
"""Compact ALSA mixer snapshot suitable for `watch` (shows volume/input/output states)."""

from __future__ import annotations

import argparse
import os
import re
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from typing import Iterable


CHANNEL_RE = re.compile(
    r"^(?P<name>[^:]+):\s*(?P<direction>Playback|Capture).*?\[(?P<pct>\d+%)\](?:.*?\[(?P<db>[-+0-9.]+dB)\])?.*?\[(?P<state>on|off)\]"
)
ITEM_RE = re.compile(r"Item\d+:\s*'(?P<value>[^']+)'")

CARD_PROBE_RANGE = range(0, 8)
DEFAULT_CARD = "default"


def _run(cmd: list[str]) -> str:
    try:
        completed = subprocess.run(cmd, check=True, capture_output=True, text=True)
    except FileNotFoundError as exc:
        raise SystemExit(f"Command not found: {cmd[0]} (install alsa-utils?)") from exc
    except subprocess.CalledProcessError as exc:
        raise SystemExit(f"Command {' '.join(cmd)} failed with exit code {exc.returncode}:\n{exc.stderr}") from exc
    return completed.stdout


def _ensure_amixer() -> None:
    if shutil.which("amixer") is None:
        raise SystemExit("amixer executable not found; install alsa-utils.")


DEFAULT_CONTROL_KEYWORDS = (
    "master",
    "pcm",
    "speaker",
    "playback",
    "headphone",
    "line out",
    "lineout",
    "capture",
    "mic",
    "input source",
    "output source",
    "line in",
    "aux",
)
INPUT_SELECTOR_KEYWORDS = (
    "input source",
    "capture source",
    "mic select",
    "recording source",
    "line in source",
    "input route",
)
OUTPUT_SELECTOR_KEYWORDS = (
    "output source",
    "playback route",
    "speaker source",
    "line out source",
    "dac source",
    "tx_audio",
)
AUTO_LIMIT = 6


@dataclass
class ChannelState:
    direction: str
    name: str
    pct: str
    db: str | None
    state: str

    def format(self) -> str:
        mute = " (muted)" if self.state == "off" else ""
        db = f" {self.db}" if self.db else ""
        return f"{self.name.strip()}={self.pct}{db}{mute}"


@dataclass
class ControlReport:
    name: str
    summary: str
    selection: str | None
    is_input_selector: bool
    is_output_selector: bool


def _format_card_label(card: str | None) -> str:
    if card is None or card.strip().lower() in {"", "default"}:
        return DEFAULT_CARD
    return card


def _build_amixer_command(card: str | None, *args: str) -> list[str]:
    cmd = ["amixer"]
    if card is not None:
        cmd.extend(["-c", card])
    cmd.extend(args)
    return cmd


def _is_selector(control: str, keywords: tuple[str, ...]) -> bool:
    lower = control.lower()
    return any(keyword in lower for keyword in keywords)


def _summarize_control(card: str | None, control: str) -> ControlReport:
    output = _run(_build_amixer_command(card, "sget", control))
    channels: list[ChannelState] = []
    items: list[str] = []

    for raw_line in output.splitlines():
        line = raw_line.strip()
        if not line or line.startswith(("Simple mixer control", "Capabilities", "Limits", "|", "numid")):
            continue
        channel_match = CHANNEL_RE.search(line)
        if channel_match:
            channels.append(
                ChannelState(
                    direction=channel_match.group("direction"),
                    name=channel_match.group("name"),
                    pct=channel_match.group("pct"),
                    db=channel_match.group("db"),
                    state=channel_match.group("state"),
                )
            )
            continue
        item_match = ITEM_RE.search(line)
        if item_match:
            items.append(item_match.group("value"))

    lines: list[str] = [f"{control}"]
    if channels:
        for direction in ("Playback", "Capture"):
            entries = [ch for ch in channels if ch.direction == direction]
            if not entries:
                continue
            formatted = ", ".join(entry.format() for entry in entries)
            lines.append(f"  {direction}: {formatted}")
    selection = items[-1] if items else None
    if selection:
        lines.append(f"  Selection: {selection}")
    if len(lines) == 1:
        lines.append("  (no playback/capture data)")
    return ControlReport(
        name=control,
        summary="\n".join(lines),
        selection=selection,
        is_input_selector=_is_selector(control, INPUT_SELECTOR_KEYWORDS),
        is_output_selector=_is_selector(control, OUTPUT_SELECTOR_KEYWORDS),
    )


def _select_controls(names: list[str], *, keywords: tuple[str, ...] = DEFAULT_CONTROL_KEYWORDS, limit: int = AUTO_LIMIT) -> list[str]:
    selected: list[str] = []
    used = set()
    lower_map = {name: name.lower() for name in names}

    for keyword in keywords:
        for name, lower in lower_map.items():
            if name in used:
                continue
            if keyword in lower:
                selected.append(name)
                used.add(name)
                break
        if len(selected) >= limit:
            break

    if not selected:
        selected = names[: min(len(names), max(3, limit))]

    return selected


def _list_simple_controls(card: str | None, timeout: float = 2.0) -> list[str]:
    cmd = _build_amixer_command(card, "scontrols")
    try:
        completed = subprocess.run(cmd, check=True, capture_output=True, text=True, timeout=timeout)
    except FileNotFoundError as exc:
        raise SystemExit(f"Command not found: {cmd[0]} (install alsa-utils?)") from exc
    except subprocess.TimeoutExpired:
        return []
    except subprocess.CalledProcessError:
        return []

    names: list[str] = []
    for line in completed.stdout.splitlines():
        text = line.strip()
        if not text or "Simple mixer control" not in text:
            continue
        start = text.find("'")
        end = text.rfind("'")
        if start != -1 and end != -1 and end > start:
            names.append(text[start + 1 : end])
    return names


def _normalise_card(value: str | None) -> str | None:
    if value is None:
        return None
    trimmed = value.strip()
    if not trimmed or trimmed.lower() == "default":
        return None
    return trimmed


def _candidate_cards(preferred: str | None) -> list[str | None]:
    candidates: list[str | None] = []
    seen: set[str] = set()

    def add(card_value: str | None) -> None:
        normalised = _normalise_card(card_value)
        key = DEFAULT_CARD if normalised is None else normalised
        if key in seen:
            return
        seen.add(key)
        candidates.append(normalised)

    add(preferred)
    add(None)
    for idx in CARD_PROBE_RANGE:
        add(str(idx))
    return candidates


def _resolve_card_and_controls(card: str | None, controls: Iterable[str] | None) -> tuple[str | None, list[str]]:
    controls_list = list(controls) if controls is not None else None
    last_error: str | None = None
    tried_cards: list[str | None] = []
    for candidate in _candidate_cards(card):
        tried_cards.append(candidate)
        if isinstance(candidate, str) and not candidate.isdigit():
            # Named cards often represent virtual devices (e.g. pulse); allow them even when no controls are reported.
            if controls_list is not None:
                return candidate, controls_list
            continue

        names = _list_simple_controls(candidate)
        if not names:
            last_error = f"No ALSA simple controls reported for card '{_format_card_label(candidate)}'"
            continue
        if controls_list is not None:
            return candidate, controls_list
        return candidate, names

    tried = ", ".join(_format_card_label(c) for c in tried_cards)
    message = last_error or "Unable to detect ALSA controls"
    raise SystemExit(f"{message}. Tried cards: {tried}")


def snapshot(card: str | None, controls: Iterable[str] | None) -> str:
    _ensure_amixer()
    effective_card, control_list = _resolve_card_and_controls(card, controls)
    card_label = _format_card_label(effective_card)
    timestamp = time.strftime("%H:%M:%S")
    parts = [f"ALSA card {card_label} @ {timestamp}"]
    reports = [_summarize_control(effective_card, control) for control in control_list]
    if not reports:
        parts.append("No ALSA controls found for the active card.")
        return "\n".join(parts)

    active_input = next((report.selection for report in reports if report.is_input_selector and report.selection), None)
    active_output = next((report.selection for report in reports if report.is_output_selector and report.selection), None)
    status_lines: list[str] = []
    if active_input:
        status_lines.append(f"Input source: {active_input}")
    if active_output:
        status_lines.append(f"Output route: {active_output}")
    if not status_lines:
        status_lines.append("Input source: (not reported)")
        status_lines.append("Output route: (not reported)")
    if status_lines:
        parts.append("\n".join(status_lines))

    parts.extend(report.summary for report in reports)
    return "\n\n".join(parts)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Print concise ALSA mixer state (ideal for running under `watch -n0.5 ...`)."
    )
    default_card = os.environ.get("ALSAMIXER_CARD")
    parser.add_argument(
        "--card",
        default=default_card,
        help="Target ALSA card index/name (default: env ALSAMIXER_CARD or auto-detect).",
    )
    parser.add_argument(
        "--controls",
        nargs="+",
        help="Simple controls to display (default: auto-detect relevant ones).",
    )
    return parser


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    try:
        print(snapshot(args.card, args.controls))
    except SystemExit:
        raise
    except Exception as exc:
        raise SystemExit(f"Unable to read ALSA settings: {exc}") from exc


if __name__ == "__main__":  # pragma: no cover - CLI helper
    main()

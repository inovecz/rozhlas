#!/usr/bin/env python3
"""ALSA helper for controlling TLV320AIC3107 cards via amixer."""

from __future__ import annotations

import argparse
import os
import re
import shlex
import subprocess
from dataclasses import dataclass
from typing import Iterable, Optional, Sequence


class AlsaControlError(RuntimeError):
    """Raised when ALSA control operations fail."""


def _lower(s: str) -> str:
    return s.lower()


def _normalize_control_name(name: str) -> str:
    return re.sub(r"\s+", " ", name.strip())


def _fuzzy_score(candidate: str, target: str) -> int:
    candidate_lower = candidate.lower()
    target_lower = target.lower()
    if target_lower in candidate_lower:
        return len(target)
    return 0


def _find_first_match(options: Iterable[str], values: Sequence[str]) -> Optional[str]:
    for value in values:
        for option in options:
            if option.lower() == value.lower():
                return option
        for option in options:
            if option.lower() == value.lower().replace("l", "left").replace("r", "right"):
                return option
    return None


@dataclass(slots=True)
class ControlMap:
    left_mux: str
    right_mux: str
    volume_ctl: str
    mute_ctl: Optional[str]
    volume_switch: bool


DEFAULT_OVERRIDES = {
    "INPUT_LEFT_CONTROL": "Left Line1L Mux",
    "INPUT_RIGHT_CONTROL": "Right Line1R Mux",
    "VOL_CONTROL": "HP",
    "MUTE_CONTROL": "HP",
    "VOL_SWITCH_CONTROL": "1",
}
for _env_key, _env_value in DEFAULT_OVERRIDES.items():
    os.environ.setdefault(_env_key, _env_value)


class AlsaCard:
    """Control TLV320AIC3107 ALSA card via amixer."""

    INPUT_VALUE_CANDIDATES = {
        "line1": ["Line1", "LINE1", "line1", "L1", "IN1", "Line1L", "Line1R"],
        "line2": ["Line2", "LINE2", "line2", "L2", "IN2", "Line2L", "Line2R"],
        "mic": ["Mic", "MIC", "mic", "Microphone", "Mic3L", "Mic3R"],
    }

    INPUT_CONTROL_CANDIDATES = [
        "Left Input Mux",
        "Right Input Mux",
        "Input Mux",
        "Left PGA Mux",
        "Right PGA Mux",
        "MIC Mux",
        "Line1 Mux",
        "Line2 Mux",
        "Left Line1L Mux",
        "Right Line1R Mux",
        "Left Line2L Mux",
        "Right Line2R Mux",
    ]

    VOLUME_CONTROL_CANDIDATES = [
        "Headphone Playback Volume",
        "HP Playback Volume",
        "PCM Playback Volume",
        "DAC Playback Volume",
        "LO Playback Volume",
        "LineOut Playback Volume",
        "HP",
        "Line",
    ]

    MUTE_CONTROL_CANDIDATES = [
        "Headphone Playback Switch",
        "HP Playback Switch",
        "PCM Playback Switch",
        "DAC Playback Switch",
        "LO Playback Switch",
        "LineOut Playback Switch",
        "HP",
        "Line",
    ]

    CARD_NAME_KEYWORDS = ("aic3x", "aic3107", "tlv320", "soundcard")
    INPUT_PROFILES: dict[str, list[tuple[str, str]]] = {
        "line1": [
            ("Left Line1L Mux", "Line1L"),
            ("Right Line1R Mux", "Line1R"),
            ("Left PGA Mixer Line1L", "on"),
            ("Right PGA Mixer Line1R", "on"),
            ("Left PGA Mixer Line2L", "off"),
            ("Right PGA Mixer Line2R", "off"),
            ("Left PGA Mixer Mic3L", "off"),
            ("Right PGA Mixer Mic3R", "off"),
            ("Left HP Mixer PGAL Bypass", "on"),
            ("Right HP Mixer PGAR Bypass", "on"),
            ("Left HP Mixer Line2L Bypass", "off"),
            ("Right HP Mixer Line2R Bypass", "off"),
            ("Left HP Mixer DACL1", "off"),
            ("Right HP Mixer DACR1", "off"),
        ],
        "line2": [
            ("Left Line2L Mux", "Line2L"),
            ("Right Line2R Mux", "Line2R"),
            ("Left PGA Mixer Line1L", "off"),
            ("Right PGA Mixer Line1R", "off"),
            ("Left PGA Mixer Line2L", "on"),
            ("Right PGA Mixer Line2R", "on"),
            ("Left PGA Mixer Mic3L", "off"),
            ("Right PGA Mixer Mic3R", "off"),
            ("Left HP Mixer PGAL Bypass", "on"),
            ("Right HP Mixer PGAR Bypass", "on"),
            ("Left HP Mixer Line2L Bypass", "on"),
            ("Right HP Mixer Line2R Bypass", "on"),
            ("Left HP Mixer DACL1", "off"),
            ("Right HP Mixer DACR1", "off"),
        ],
        "mic": [
            ("Left Line1L Mux", "Mic3L"),
            ("Right Line1R Mux", "Mic3R"),
            ("Left PGA Mixer Line1L", "off"),
            ("Right PGA Mixer Line1R", "off"),
            ("Left PGA Mixer Line2L", "off"),
            ("Right PGA Mixer Line2R", "off"),
            ("Left PGA Mixer Mic3L", "on"),
            ("Right PGA Mixer Mic3R", "on"),
            ("Left HP Mixer PGAL Bypass", "on"),
            ("Right HP Mixer PGAR Bypass", "on"),
            ("Left HP Mixer Line2L Bypass", "off"),
            ("Right HP Mixer Line2R Bypass", "off"),
            ("Left HP Mixer DACL1", "off"),
            ("Right HP Mixer DACR1", "off"),
        ],
        "system": [
            ("Left HP Mixer DACL1", "on"),
            ("Right HP Mixer DACR1", "on"),
            ("Left HP Mixer PGAL Bypass", "off"),
            ("Right HP Mixer PGAR Bypass", "off"),
            ("Left HP Mixer Line2L Bypass", "off"),
            ("Right HP Mixer Line2R Bypass", "off"),
            ("Left PGA Mixer Line1L", "off"),
            ("Right PGA Mixer Line1R", "off"),
            ("Left PGA Mixer Line2L", "off"),
            ("Right PGA Mixer Line2R", "off"),
            ("Left PGA Mixer Mic3L", "off"),
            ("Right PGA Mixer Mic3R", "off"),
        ],
    }

    def __init__(
        self,
        card: str | int | None = None,
        *,
        verbose: bool = False,
        detect_controls: bool = True,
    ) -> None:
        env_card = os.environ.get("SOUND_CARD")
        if card is None and env_card:
            card = env_card
        self._verbose = verbose or os.environ.get("ALSA_CTRL_VERBOSE", "").lower() in {"1", "true", "yes", "on"}
        self.card_index = self._detect_card_index(card)
        self._controls_cache = self._list_scontrols()
        self._controls: Optional[ControlMap] = None
        if detect_controls:
            self._controls = self._detect_controls(self._controls_cache)
        if self._verbose:
            print(
                "Selected card index:",
                self.card_index,
                "\nControls:",
                self._controls,
            )

    def _run(self, args: list[str]) -> str:
        command = ["amixer", "-c", str(self.card_index), *args]
        if self._verbose:
            print("Executing:", " ".join(shlex.quote(part) for part in command))
        try:
            completed = subprocess.run(
                command,
                check=False,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
        except OSError as exc:
            raise AlsaControlError(f"Failed to execute {' '.join(shlex.quote(a) for a in command)}: {exc}") from exc

        if completed.returncode != 0:
            raise AlsaControlError(
                f"amixer returned {completed.returncode} for {' '.join(shlex.quote(a) for a in command)}:\n{completed.stderr.strip()}"
            )
        return completed.stdout

    def _detect_card_index(self, card: str | int | None) -> int:
        if card is not None:
            try:
                index = int(card)
                self._run(["scontrols"])
                return index
            except (ValueError, AlsaControlError):
                pass
            stdout = subprocess.run(
                ["aplay", "-l"],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                check=False,
            ).stdout
            pattern = re.compile(r"card (\d+): ([^\[]+)")
            matches = pattern.findall(stdout)
            for idx_str, name in matches:
                if card.lower() in name.lower():
                    return int(idx_str)
            raise RuntimeError(f"ALSA card '{card}' not found.")

        stdout = subprocess.run(
            ["aplay", "-l"],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            check=False,
        ).stdout
        pattern = re.compile(r"card (\d+): ([^\[]+)")
        matches = pattern.findall(stdout)
        for idx_str, name in matches:
            lower_name = name.lower()
            if any(keyword in lower_name for keyword in self.CARD_NAME_KEYWORDS):
                return int(idx_str)
        raise RuntimeError("No suitable ALSA card found (searched for TLV320/AIC3x keywords).")

    def _list_scontrols(self) -> list[str]:
        output = self._run(["scontrols"])
        controls = []
        for line in output.splitlines():
            match = re.search(r"'(.+)'", line)
            if match:
                controls.append(_normalize_control_name(match.group(1)))
        return controls

    def _find_best_control(self, candidates: Sequence[str], controls: Sequence[str]) -> Optional[str]:
        best_score = 0
        best_match = None
        for control in controls:
            for candidate in candidates:
                score = _fuzzy_score(control, candidate)
                if score > best_score:
                    best_score = score
                    best_match = control
        return best_match

    def _detect_controls(self) -> ControlMap:
        controls = self._list_scontrols()
        left_override = os.environ.get("INPUT_LEFT_CONTROL")
        right_override = os.environ.get("INPUT_RIGHT_CONTROL")
        vol_override = os.environ.get("VOL_CONTROL")
        mute_override = os.environ.get("MUTE_CONTROL")
        vol_switch_override = os.environ.get("VOL_SWITCH_CONTROL")

        if left_override and left_override not in controls:
            raise RuntimeError(f"Configured INPUT_LEFT_CONTROL '{left_override}' not found.")
        if right_override and right_override not in controls:
            raise RuntimeError(f"Configured INPUT_RIGHT_CONTROL '{right_override}' not found.")
        if vol_override and vol_override not in controls:
            raise RuntimeError(f"Configured VOL_CONTROL '{vol_override}' not found.")
        if mute_override and mute_override not in controls:
            raise RuntimeError(f"Configured MUTE_CONTROL '{mute_override}' not found.")

        left_mux = left_override or self._find_best_control(self.INPUT_CONTROL_CANDIDATES, controls)
        right_mux = right_override
        if right_mux is None:
            if left_mux:
                left_lower = left_mux.lower()
                if "left" in left_lower:
                    mirror_name = left_lower.replace("left", "right")
                    right_mux = next((ctl for ctl in controls if ctl.lower() == mirror_name), None)
                if right_mux is None and "line1l" in left_lower:
                    mirror_name = left_lower.replace("line1l", "line1r")
                    right_mux = next((ctl for ctl in controls if ctl.lower() == mirror_name), None)
                if right_mux is None and "line2l" in left_lower:
                    mirror_name = left_lower.replace("line2l", "line2r")
                    right_mux = next((ctl for ctl in controls if ctl.lower() == mirror_name), None)
                if right_mux is None and "pgal" in left_lower:
                    mirror_name = left_lower.replace("pgal", "pgar")
                    right_mux = next((ctl for ctl in controls if ctl.lower() == mirror_name), None)
                if right_mux is None and "lop" in left_lower:
                    mirror_name = left_lower.replace("lop", "rop")
                    right_mux = next((ctl for ctl in controls if ctl.lower() == mirror_name), None)
            if right_mux is None:
                right_mux = self._find_best_control(self.INPUT_CONTROL_CANDIDATES, controls)
        if left_mux is None:
            left_mux = next((ctl for ctl in controls if "left" in ctl.lower() and "mux" in ctl.lower()), None)
        if right_mux is None:
            right_mux = next((ctl for ctl in controls if "right" in ctl.lower() and "mux" in ctl.lower()), None)

        if not left_mux or not right_mux:
            workaround = os.environ.get("ALLOW_MISSING_MUX", "").lower() in {"1", "true", "yes", "on"}
            if not workaround:
                suggestions = (
                    "\nControls scanned:\n" + "\n".join(f"  {ctl}" for ctl in controls) + "\n"
                    "Set INPUT_LEFT_CONTROL and INPUT_RIGHT_CONTROL env vars to override detection."
                )
                raise RuntimeError("Unable to detect input mux controls. " + suggestions)
            if not left_mux:
                left_mux = (right_override or right_mux)
            if not right_mux:
                right_mux = left_mux

        volume_ctl = vol_override or self._find_best_control(self.VOLUME_CONTROL_CANDIDATES, controls)
        if not volume_ctl:
            raise RuntimeError(
                "Unable to detect volume control. Available controls:\n" + "\n".join(f"  {ctl}" for ctl in controls)
            )

        mute_ctl: Optional[str] = None
        volume_switch = False
        if mute_override:
            mute_ctl = mute_override
        else:
            mute_ctl = self._find_best_control(self.MUTE_CONTROL_CANDIDATES, controls)
            if mute_ctl is None:
                mute_ctl = volume_ctl
                volume_switch = True
        if vol_switch_override:
            volume_switch = vol_switch_override.lower() in {"1", "true", "yes", "on"}

        return ControlMap(
            left_mux=left_mux,
            right_mux=right_mux,
            volume_ctl=volume_ctl,
            mute_ctl=mute_ctl,
            volume_switch=volume_switch,
        )

    def _ensure_controls(self) -> ControlMap:
        if self._controls is None:
            self._controls = self._detect_controls()
        return self._controls

    def _control_supports_value(self, control: str, value: str) -> bool:
        output = self._run(["sget", control])
        options = set()
        for line in output.splitlines():
            if "Items:" in line:
                items_part = line.split("Items:", 1)[1]
                items = re.findall(r"'([^']+)'", items_part)
                options.update(items)
            elif "Item0:" in line:
                match = re.search(r"Item0:\s*'([^']+)'", line)
                if match:
                    options.add(match.group(1))
        options_lower = {opt.lower() for opt in options}
        return value.lower() in options_lower

    def set_input(self, source: str) -> None:
        controls = self._ensure_controls()
        source_key = source.lower()
        if source_key not in self.INPUT_VALUE_CANDIDATES:
            raise ValueError(f"Unsupported source '{source}'. Expected one of: {', '.join(self.INPUT_VALUE_CANDIDATES)}")
        candidates = self.INPUT_VALUE_CANDIDATES[source_key]
        left_value = None
        right_value = None
        output_left = self._run(["sget", controls.left_mux])
        options_left = re.findall(r"'([^']+)'", output_left)
        output_right = self._run(["sget", controls.right_mux])
        options_right = re.findall(r"'([^']+)'", output_right)

        left_value = _find_first_match(options_left, candidates)
        right_value = _find_first_match(options_right, candidates)

        if left_value is None:
            if os.environ.get("ALLOW_MISSING_MUX", "").lower() in {"1", "true", "yes", "on"}:
                left_value = right_value
            else:
                raise ValueError(f"Control '{controls.left_mux}' does not support source '{source}'.")
        if right_value is None:
            if os.environ.get("ALLOW_MISSING_MUX", "").lower() in {"1", "true", "yes", "on"}:
                right_value = left_value
            else:
                raise ValueError(f"Control '{controls.right_mux}' does not support source '{source}'.")

        self._run(["sset", controls.left_mux, left_value])
        self._run(["sset", controls.right_mux, right_value])
        if self._verbose:
            print(f"Input set to {source_key} via {left_value}/{right_value}")

    def set_volume(self, percent: int) -> None:
        controls = self._ensure_controls()
        clamped = max(0, min(100, int(percent)))
        self._run(["sset", controls.volume_ctl, f"{clamped}%", "unmute"])
        if self._verbose:
            print(f"Volume set to {clamped}% using control '{controls.volume_ctl}'.")

    def mute(self, enable: bool) -> None:
        controls = self._ensure_controls()
        if controls.mute_ctl is None:
            raise RuntimeError("Mute control is not configured.")
        if controls.volume_switch:
            self._run(
                [
                    "sset",
                    controls.volume_ctl,
                    "mute" if enable else "unmute",
                ]
            )
        else:
            self._run(
                [
                    "sset",
                    controls.mute_ctl,
                    "off" if enable else "on",
                ]
            )
        if self._verbose:
            print(f"Mute {'enabled' if enable else 'disabled'} via control '{controls.mute_ctl}'.")


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Control TLV320AIC3107 ALSA mixer via amixer.")
    parser.add_argument("--card", help="ALSA card index or name. Defaults to SOUND_CARD env or auto-detect.")
    parser.add_argument("--input", choices=["line1", "line2", "mic"], help="Input source to select.")
    parser.add_argument("--volume", type=int, help="Playback volume percent (0-100).")
    parser.add_argument("--mute", choices=["on", "off"], help="Mute (on) or unmute (off) playback.")
    parser.add_argument("--list-controls", action="store_true", help="Show detected ALSA simple controls and exit.")
    parser.add_argument("--debug", action="store_true", help="Print detailed amixer commands.")
    parser.add_argument("--allow-missing-mux", action="store_true", help="Gracefully continue if only one mux control exists.")
    return parser.parse_args()


def main() -> None:
    args = _parse_args()
    if args.allow_missing_mux:
        os.environ.setdefault("ALLOW_MISSING_MUX", "1")
    try:
        card = AlsaCard(args.card, verbose=args.debug, detect_controls=not args.list_controls)
    except RuntimeError as exc:
        if not args.list_controls:
            raise
        card = AlsaCard(args.card, verbose=args.debug, detect_controls=False)
        print(exc)

    if args.list_controls:
        controls = card._list_scontrols()  # pylint: disable=protected-access
        print("Available controls:")
        for ctl in controls:
            print(" ", ctl)
        return

    if args.input:
        card.set_input(args.input)
    if args.volume is not None:
        card.set_volume(args.volume)
    if args.mute:
        card.mute(args.mute == "on")
    if args.debug and not any([args.input, args.volume is not None, args.mute]):
        print("No operation requested. Use --input/--volume/--mute.")


if __name__ == "__main__":
    if any(arg for arg in os.sys.argv[1:]):
        main()
    else:
        c = AlsaCard(verbose=True)
        c.set_input("line2")
        c.set_volume(35)
        c.mute(True)
        c.mute(False)
        c.set_volume(70)

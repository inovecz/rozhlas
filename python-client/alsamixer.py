#!/usr/bin/env python3
import argparse, shlex, subprocess, sys
from typing import Optional

# ---------- shell helpers ----------
def run(cmd):
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if p.returncode != 0:
        raise RuntimeError(f"Command failed: {' '.join(shlex.quote(c) for c in cmd)}\n{p.stderr.strip()}")
    return p.stdout

def sget(card, ctrl):  return run(["amixer", "-c", str(card), "sget", ctrl])
def sset(card, ctrl, *values):
    if not values:
        raise ValueError("sset vyžaduje alespoň jednu hodnotu.")
    cmd = ["amixer", "-c", str(card), "sset", ctrl, *values]
    return run(cmd)

def control_exists(card, ctrl):
    try: sget(card, ctrl); return True
    except Exception: return False

def is_percent_fader(card, ctrl):
    try:
        out = sget(card, ctrl)
        return "Capabilities:" in out and ("pvolume" in out or "volume" in out)
    except Exception:
        return False

# ---------- CLI helpers ----------
INPUT_ALIASES = {
    "line1": "line1",
    "line2": "line2",
    "mic": "mic",
    "microfon": "mic",
    "mikrofon": "mic",
    "microphone": "mic",
    "system": "system",
    "systemovy": "system",
    "systemový": "system",
    "soubor": "system",
    "souboru": "system",
    "soubor_v_ustredne": "system",
    "soubor-v-ustredne": "system",
    "souborzustredny": "system",
    "ustredna": "system",
    "fm": "line2",
    "fm_radio": "line2",
    "fmradio": "line2",
    "gsm": "system",
    "gsm_modul": "system",
    "vstup1": "line1",
    "vstup-1": "line1",
    "vstup_1": "line1",
    "vstup2": "line2",
    "vstup-2": "line2",
    "vstup_2": "line2",
}

VOLUME_CMD_ALIASES = {"volume", "vol", "hlasitost"}
STATUS_CMD_ALIASES = {"status", "stav"}


def normalize_input(name: str) -> str:
    key = name.lower()
    if key not in INPUT_ALIASES:
        raise ValueError(f"Neznámý vstup '{name}'. Očekávám: {', '.join(sorted(INPUT_ALIASES))}")
    return INPUT_ALIASES[key]


def _parse_simple_command(argv):
    if not argv:
        return None
    first = argv[0].lower()
    if first in INPUT_ALIASES:
        parser = argparse.ArgumentParser(
            prog=f"{sys.argv[0]} {argv[0]}",
            description="Přepni vstup a volitelně nastav hlasitost.",
        )
        parser.add_argument("input", choices=sorted(INPUT_ALIASES))
        parser.add_argument("volume_arg", nargs="?", type=int, help="Hlasitost 0-100 (volitelně).")
        parser.add_argument("--volume", "-v", type=int, dest="volume_opt", help="Hlasitost 0-100.")
        parser.add_argument("--card", "-c", type=int, default=2, help="ALSA karta (index).")
        args = parser.parse_args(argv)
        volume = args.volume_opt if args.volume_opt is not None else args.volume_arg
        return {
            "command": "select",
            "card": args.card,
            "input": normalize_input(args.input),
            "volume": volume,
        }
    if first in VOLUME_CMD_ALIASES:
        parser = argparse.ArgumentParser(
            prog=f"{sys.argv[0]} {argv[0]}",
            description="Nastav hlasitost pro aktuální výstup.",
        )
        parser.add_argument("command", choices=sorted(VOLUME_CMD_ALIASES))
        parser.add_argument("percent", type=int, help="Hlasitost 0-100.")
        parser.add_argument("--card", "-c", type=int, default=2, help="ALSA karta (index).")
        args = parser.parse_args(argv)
        return {
            "command": "volume",
            "card": args.card,
            "volume": args.percent,
        }
    if first in STATUS_CMD_ALIASES:
        parser = argparse.ArgumentParser(
            prog=f"{sys.argv[0]} {argv[0]}",
            description="Zobraz aktuální stav klíčových mixerů.",
        )
        parser.add_argument("command", choices=sorted(STATUS_CMD_ALIASES))
        parser.add_argument("--card", "-c", type=int, default=2, help="ALSA karta (index).")
        args = parser.parse_args(argv)
        return {
            "command": "status",
            "card": args.card,
        }
    return None

# ---------- auto-choose output path ----------
def detect_outputs(card: int) -> list[str]:
    outputs = []
    hp_probe = any(control_exists(card, c) for c in [
        "Left HP Mixer DACL1", "Right HP Mixer DACR1", "HP DAC"
    ])
    if hp_probe:
        outputs.append("hp")
    line_probe = any(control_exists(card, c) for c in [
        "Left Line Mixer DACL1", "Right Line Mixer DACR1", "Line DAC"
    ])
    if line_probe:
        outputs.append("line")
    if not outputs:
        raise RuntimeError("Nenašel jsem HP ani Line mixery. Zkontroluj ovladač karty.")
    return outputs

# ---------- volume ----------
VOLUME_CANDS = {
    "hp":   ["HP DAC", "PCM", "DAC Playback Volume"],
    "line": ["Line DAC", "PCM", "DAC Playback Volume"]
}

ANALOG_GAIN_CTRLS = {
    "hp": ["HP PGA Bypass", "HPCOM PGA Bypass"],
    "line": ["Line PGA Bypass"],
}

DEFAULT_DIGITAL_VOLUME = 60
DEFAULT_ANALOG_VOLUME = 60

HPCOM_DACL = ["Left HPCOM Mixer DACL1", "Right HPCOM Mixer DACR1"]
HPCOM_LINE_BYP = ["Left HPCOM Mixer Line2L Bypass", "Right HPCOM Mixer Line2R Bypass"]
HPCOM_PGA_BYP = ["Left HPCOM Mixer PGAL Bypass", "Right HPCOM Mixer PGAR Bypass"]

STATUS_CONTROLS = [
    # HP path
    "Left HP Mixer DACL1",
    "Right HP Mixer DACR1",
    "Left HP Mixer Line2L Bypass",
    "Right HP Mixer Line2R Bypass",
    "Left HP Mixer PGAL Bypass",
    "Right HP Mixer PGAR Bypass",
    # Line path
    "Left Line Mixer DACL1",
    "Right Line Mixer DACR1",
    "Left Line Mixer Line2L Bypass",
    "Right Line Mixer Line2R Bypass",
    "Left Line Mixer PGAL Bypass",
    "Right Line Mixer PGAR Bypass",
    # HPCOM path
    "Left HPCOM Mixer DACL1",
    "Right HPCOM Mixer DACR1",
    "Left HPCOM Mixer Line2L Bypass",
    "Right HPCOM Mixer Line2R Bypass",
    "Left HPCOM Mixer PGAL Bypass",
    "Right HPCOM Mixer PGAR Bypass",
    # PGA inputs
    "Left PGA Mixer Line1L",
    "Right PGA Mixer Line1R",
    "Left PGA Mixer Line2L",
    "Right PGA Mixer Line2R",
    "Left PGA Mixer Mic3L",
    "Right PGA Mixer Mic3R",
    # Analog gain
    "HP PGA Bypass",
    "Line PGA Bypass",
    "HPCOM PGA Bypass",
]

def set_digital_volume(card: int, out: str, percent: int) -> bool:
    percent = max(0, min(100, percent))
    applied = False
    for ctrl in VOLUME_CANDS[out]:
        if control_exists(card, ctrl) and is_percent_fader(card, ctrl):
            if percent <= 0:
                try:
                    sset(card, ctrl, "0%", "mute")
                except RuntimeError:
                    sset(card, ctrl, "0%")
            else:
                try:
                    sset(card, ctrl, f"{percent}%", "unmute")
                except RuntimeError:
                    sset(card, ctrl, f"{percent}%")
            applied = True
    if not applied and percent > 0:
        raise RuntimeError(f"Nenašel jsem vhodný digitální fader pro {out.upper()} (zkusím HP/Line/PCM).")
    return applied


def set_analog_volume(card: int, out: str, percent: int) -> bool:
    percent = max(0, min(100, percent))
    controls = ANALOG_GAIN_CTRLS.get(out, [])
    found = False
    for ctrl in controls:
        if control_exists(card, ctrl):
            sset(card, ctrl, f"{percent}%")
            found = True
    if not found and controls:
        raise RuntimeError(f"Nenašel jsem analogový gain pro {out.upper()} ({', '.join(controls)}).")
    return found

# ---------- routing helpers (disable list if exists) ----------
def _off_all(card, ctrls):
    for c in ctrls:
        if control_exists(card, c): sset(card, c, "off")

def route_system_to(card: int, out: str):
    # Systemový vstup = DAC (DACL1/DACR1) → HP/Line
    if out == "hp":
        _off_all(card, [
            "Left HP Mixer Line2L Bypass","Left HP Mixer Line2R Bypass",
            "Left HP Mixer PGAL Bypass","Left HP Mixer PGAR Bypass",
            "Right HP Mixer Line2L Bypass","Right HP Mixer Line2R Bypass",
            "Right HP Mixer PGAL Bypass","Right HP Mixer PGAR Bypass",
        ])
        for c in ["Left HP Mixer DACL1","Right HP Mixer DACR1"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    else:
        _off_all(card, [
            "Left Line Mixer Line2L Bypass","Left Line Mixer Line2R Bypass",
            "Left Line Mixer PGAL Bypass","Left Line Mixer PGAR Bypass",
            "Right Line Mixer Line2L Bypass","Right Line Mixer Line2R Bypass",
            "Right Line Mixer PGAL Bypass","Right Line Mixer PGAR Bypass",
        ])
        for c in ["Left Line Mixer DACL1","Right Line Mixer DACR1"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    if any(control_exists(card, c) for c in HPCOM_DACL):
        for ctrl in HPCOM_LINE_BYP + HPCOM_PGA_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "off")
        for ctrl in HPCOM_DACL:
            if control_exists(card, ctrl):
                sset(card, ctrl, "on")

def route_line2_to(card: int, out: str):
    if out == "hp":
        _off_all(card, [
            "Left HP Mixer DACL1","Left HP Mixer DACR1",
            "Left HP Mixer PGAL Bypass","Left HP Mixer PGAR Bypass",
            "Left HP Mixer Line2R Bypass",
            "Right HP Mixer DACL1","Right HP Mixer DACR1",
            "Right HP Mixer PGAL Bypass","Right HP Mixer PGAR Bypass",
            "Right HP Mixer Line2L Bypass",
        ])
        for c in ["Left HP Mixer Line2L Bypass","Right HP Mixer Line2R Bypass"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    else:
        _off_all(card, [
            "Left Line Mixer DACL1","Left Line Mixer DACR1",
            "Left Line Mixer PGAL Bypass","Left Line Mixer PGAR Bypass",
            "Left Line Mixer Line2R Bypass",
            "Right Line Mixer DACL1","Right Line Mixer DACR1",
            "Right Line Mixer PGAL Bypass","Right Line Mixer PGAR Bypass",
            "Right Line Mixer Line2L Bypass",
        ])
        for c in ["Left Line Mixer Line2L Bypass","Right Line Mixer Line2R Bypass"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    if any(control_exists(card, c) for c in HPCOM_DACL):
        for ctrl in HPCOM_DACL + HPCOM_LINE_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "off")
        for ctrl in HPCOM_PGA_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "on")

def route_line1_to(card: int, out: str):
    # Line1 nejdřív do PGA, potom do HP/Line
    for c in ["Left PGA Mixer Line1L","Right PGA Mixer Line1R"]:
        if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
        sset(card, c, "on")
    _off_all(card, [
        "Left PGA Mixer Line1R","Left PGA Mixer Line2L","Left PGA Mixer Mic3L","Left PGA Mixer Mic3R",
        "Right PGA Mixer Line1L","Right PGA Mixer Line2R","Right PGA Mixer Mic3L","Right PGA Mixer Mic3R",
    ])
    if out == "hp":
        _off_all(card, [
            "Left HP Mixer DACL1","Left HP Mixer DACR1",
            "Left HP Mixer Line2L Bypass","Left HP Mixer Line2R Bypass","Left HP Mixer PGAR Bypass",
            "Right HP Mixer DACL1","Right HP Mixer DACR1",
            "Right HP Mixer Line2L Bypass","Right HP Mixer Line2R Bypass","Right HP Mixer PGAL Bypass",
        ])
        for c in ["Left HP Mixer PGAL Bypass","Right HP Mixer PGAR Bypass"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    else:
        _off_all(card, [
            "Left Line Mixer DACL1","Left Line Mixer DACR1",
            "Left Line Mixer Line2L Bypass","Left Line Mixer Line2R Bypass","Left Line Mixer PGAR Bypass",
            "Right Line Mixer DACL1","Right Line Mixer DACR1",
            "Right Line Mixer Line2L Bypass","Right Line Mixer Line2R Bypass","Right Line Mixer PGAL Bypass",
        ])
        for c in ["Left Line Mixer PGAL Bypass","Right Line Mixer PGAR Bypass"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    if any(control_exists(card, c) for c in HPCOM_DACL):
        for ctrl in HPCOM_DACL + HPCOM_LINE_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "off")
        for ctrl in HPCOM_PGA_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "on")

def route_mic_to(card: int, out: str):
    # Mic3L/Mic3R do PGA → HP/Line
    for c in ["Left PGA Mixer Mic3L","Right PGA Mixer Mic3R"]:
        if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
        sset(card, c, "on")
    _off_all(card, [
        "Left PGA Mixer Line1L","Left PGA Mixer Line1R","Left PGA Mixer Line2L","Left PGA Mixer Mic3R",
        "Right PGA Mixer Line1L","Right PGA Mixer Line1R","Right PGA Mixer Line2R","Right PGA Mixer Mic3L",
    ])
    if out == "hp":
        _off_all(card, [
            "Left HP Mixer DACL1","Left HP Mixer DACR1",
            "Left HP Mixer Line2L Bypass","Left HP Mixer Line2R Bypass","Left HP Mixer PGAR Bypass",
            "Right HP Mixer DACL1","Right HP Mixer DACR1",
            "Right HP Mixer Line2L Bypass","Right HP Mixer Line2R Bypass","Right HP Mixer PGAL Bypass",
        ])
        for c in ["Left HP Mixer PGAL Bypass","Right HP Mixer PGAR Bypass"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    else:
        _off_all(card, [
            "Left Line Mixer DACL1","Left Line Mixer DACR1",
            "Left Line Mixer Line2L Bypass","Left Line Mixer Line2R Bypass","Left Line Mixer PGAR Bypass",
            "Right Line Mixer DACL1","Right Line Mixer DACR1",
            "Right Line Mixer Line2L Bypass","Right Line Mixer Line2R Bypass","Right Line Mixer PGAL Bypass",
        ])
        for c in ["Left Line Mixer PGAL Bypass","Right Line Mixer PGAR Bypass"]:
            if not control_exists(card, c): raise RuntimeError(f"Chybí ovladač: {c}")
            sset(card, c, "on")
    if any(control_exists(card, c) for c in HPCOM_DACL):
        for ctrl in HPCOM_DACL + HPCOM_LINE_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "off")
        for ctrl in HPCOM_PGA_BYP:
            if control_exists(card, ctrl):
                sset(card, ctrl, "on")

def apply_input(card: int, src: str, volume: Optional[int]):
    outs = detect_outputs(card)
    if src not in {"line1", "line2", "mic", "system"}:
        raise RuntimeError(f"Neznámý vstup '{src}'.")

    target_volume = volume if volume is not None else (DEFAULT_DIGITAL_VOLUME if src == "system" else DEFAULT_ANALOG_VOLUME)

    for out in outs:
        if src == "line2":
            route_line2_to(card, out)
        elif src == "line1":
            route_line1_to(card, out)
        elif src == "mic":
            route_mic_to(card, out)
        elif src == "system":
            route_system_to(card, out)
        if src == "system":
            try:
                set_digital_volume(card, out, target_volume)
            except RuntimeError as exc:
                if len(outs) == 1:
                    raise
                print(f"VAROVÁNÍ: {exc}", file=sys.stderr)
            set_analog_volume(card, out, 0)
        else:
            set_digital_volume(card, out, 0)
            try:
                set_analog_volume(card, out, target_volume)
            except RuntimeError as exc:
                if len(outs) == 1:
                    raise
                print(f"VAROVÁNÍ: {exc}", file=sys.stderr)

    msg = f"OK: {src} → {', '.join(o.upper() for o in outs)}"
    if target_volume is not None:
        msg += f", hlasitost {max(0, min(100, target_volume))}%"
    print(msg)


def _is_switch_on(card: int, ctrl: str) -> bool:
    try:
        out = sget(card, ctrl)
    except RuntimeError:
        return False
    return "[on]" in out.lower()


def detect_active_source(card: int) -> Optional[str]:
    if any(_is_switch_on(card, c) for c in [
        "Left HP Mixer DACL1",
        "Right HP Mixer DACR1",
        "Left Line Mixer DACL1",
        "Right Line Mixer DACR1",
        "Left HPCOM Mixer DACL1",
        "Right HPCOM Mixer DACR1",
    ]):
        return "system"
    if any(_is_switch_on(card, c) for c in [
        "Left HP Mixer Line2L Bypass",
        "Right HP Mixer Line2R Bypass",
        "Left Line Mixer Line2L Bypass",
        "Right Line Mixer Line2R Bypass",
        "Left HPCOM Mixer Line2L Bypass",
        "Right HPCOM Mixer Line2R Bypass",
    ]):
        return "line2"
    if any(_is_switch_on(card, c) for c in [
        "Left PGA Mixer Mic3L",
        "Right PGA Mixer Mic3R",
    ]):
        return "mic"
    if any(_is_switch_on(card, c) for c in [
        "Left PGA Mixer Line1L",
        "Right PGA Mixer Line1R",
    ]):
        return "line1"
    return None


def apply_volume(card: int, percent: int):
    outs = detect_outputs(card)
    src = detect_active_source(card)
    if src is None:
        raise RuntimeError("Nepodařilo se zjistit aktivní vstup, zkus nejprve přepnout konkrétní profil.")
    applied = max(0, min(100, percent))
    for out in outs:
        if src == "system":
            try:
                set_digital_volume(card, out, applied)
            except RuntimeError as exc:
                if len(outs) == 1:
                    raise
                print(f"VAROVÁNÍ: {exc}", file=sys.stderr)
            set_analog_volume(card, out, 0)
        else:
            set_digital_volume(card, out, 0)
            try:
                set_analog_volume(card, out, applied)
            except RuntimeError as exc:
                if len(outs) == 1:
                    raise
                print(f"VAROVÁNÍ: {exc}", file=sys.stderr)
    print(f"OK: hlasitost {applied}% ({src}) na {', '.join(o.upper() for o in outs)}")


def _summarize_ctrl(card: int, ctrl: str) -> Optional[str]:
    if not control_exists(card, ctrl):
        return None
    out = sget(card, ctrl)
    state = None
    for line in out.splitlines():
        text = line.strip()
        if text.startswith("Item0:"):
            state = text
            break
        if "values=" in text or " : " in text:
            state = text
    if state is None:
        lines = out.splitlines()
        if lines:
            state = lines[-1].strip()
    return f"{ctrl}: {state}" if state else f"{ctrl}: {out.strip()}"


def print_status(card: int):
    outs = detect_outputs(card)
    print(f"Karta {card}, dostupné výstupy: {', '.join(o.upper() for o in outs)}")
    seen = set()
    for ctrl in STATUS_CONTROLS + VOLUME_CANDS["hp"] + VOLUME_CANDS["line"]:
        if ctrl in seen:
            continue
        seen.add(ctrl)
        summary = _summarize_ctrl(card, ctrl)
        if summary:
            print(" -", summary)

# ---------- public command ----------
def main():
    argv = sys.argv[1:]
    simple = _parse_simple_command(argv)
    if simple:
        if simple["command"] == "select":
            apply_input(simple["card"], simple["input"], simple["volume"])
        elif simple["command"] == "volume":
            apply_volume(simple["card"], simple["volume"])
        elif simple["command"] == "status":
            print_status(simple["card"])
        return

    ap = argparse.ArgumentParser(description="Přepni vstup (line1/line2/mic/system) a nastav hlasitost přes ALSA (amixer).")
    ap.add_argument("--card", "-c", type=int, default=2, help="ALSA karta (index), výchozí 2")
    ap.add_argument("--input", "-i", choices=["line1","line2","mic","microfon","mikrofon","system","systemovy"], help="Zvolený vstup")
    ap.add_argument("--volume", "-v", type=int, help="Hlasitost 0–100 (volitelné)")
    args = ap.parse_args(argv)

    src = None
    if args.input:
        src = normalize_input(args.input)
        apply_input(args.card, src, args.volume)
    elif args.volume is not None:
        apply_volume(args.card, args.volume)
    else:
        ap.print_help()

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)

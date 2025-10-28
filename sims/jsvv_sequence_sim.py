#!/usr/bin/env python3
"""Simulace vytvoření více JSVV sekvencí s různou prioritou."""

from __future__ import annotations

import argparse
import json
import random
import time
from typing import Iterable, Sequence

try:
    import requests
except Exception as exc:  # pragma: no cover
    print("Knihovna 'requests' není nainstalována (pip install requests).")
    raise SystemExit(1) from exc


def parse_sequence_symbols(symbols: Iterable[str]) -> Sequence[dict[str, object]]:
    items: list[dict[str, object]] = []
    for symbol in symbols:
        symbol = symbol.strip()
        if not symbol:
            continue
        if symbol.isdigit():
            slot = int(symbol)
        else:
            # Fallback: extract first number
            numbers = "".join(ch for ch in symbol if ch.isdigit())
            if numbers:
                slot = int(numbers)
            else:
                raise ValueError(f"Symbol '{symbol}' neobsahuje číselný slot.")
        category = "siren" if slot in (1, 2, 3) and len(items) == 0 else "verbal"
        items.append({"slot": slot, "category": category})
    if not items:
        raise ValueError("Sekvence musí obsahovat alespoň jeden symbol.")
    return items


def plan_sequence(
    endpoint: str,
    priority: str,
    sequence_symbols: Sequence[str],
    use_fm: bool,
    fm_frequency: float | None,
    audio_input: str | None,
    audio_output: str | None,
    locations: Sequence[int],
    hold_seconds: float | None,
) -> str:
    payload: dict[str, object] = {
        "items": list(parse_sequence_symbols(sequence_symbols)),
        "priority": priority,
        "zones": [],
    }
    if locations:
        payload["locations"] = list(locations)
    if hold_seconds is not None:
        payload["holdSeconds"] = max(0.0, float(hold_seconds))
    if audio_input:
        payload["audioInputId"] = audio_input
    if audio_output:
        payload["audioOutputId"] = audio_output
    if use_fm:
        payload.setdefault("audioInputId", "fm")
        if fm_frequency is not None:
            payload["frequency"] = fm_frequency
    response = requests.post(f"{endpoint}/jsvv/sequences", json=payload, timeout=5)
    response.raise_for_status()
    data = response.json()["sequence"]
    return data["id"]


def trigger(endpoint: str, sequence_id: str) -> dict[str, object]:
    response = requests.post(f"{endpoint}/jsvv/sequences/{sequence_id}/trigger", timeout=5)
    response.raise_for_status()
    return response.json()["sequence"]


def fetch_status(endpoint: str) -> dict[str, object]:
    response = requests.get(f"{endpoint}/live-broadcast/status", timeout=5)
    response.raise_for_status()
    return response.json()


def main() -> None:
    parser = argparse.ArgumentParser(description="Simulace prioritních JSVV požadavků (včetně FM testu)")
    parser.add_argument("--endpoint", default="http://127.0.0.1/api", help="Base URL backendu")
    parser.add_argument("--count", type=int, default=3, help="Počet sekvencí")
    parser.add_argument("--mix", action="store_true", help="Promíchat priority P1-P3")
    parser.add_argument("--sequence", default="18", help="Sekvence symbolů, např. '18' nebo '1289'")
    parser.add_argument("--use-fm", action="store_true", help="Přesměruje přehrávání na FM vstup")
    parser.add_argument("--fm-frequency", type=float, help="Frekvence rádia v MHz (např. 104.3)")
    parser.add_argument("--audio-input", help="Vlastní audio vstup (např. system, mic)")
    parser.add_argument("--audio-output", help="Vlastní audio výstup (např. lineout)")
    parser.add_argument("--locations", help="Seznam ID lokalit oddělených čárkou")
    parser.add_argument("--hold-seconds", type=float, help="Prodleva po dokončení sekvence (holdSeconds)")
    parser.add_argument("--status", action="store_true", help="Po každém triggeru vytiskne live-broadcast status")
    args = parser.parse_args()

    if args.fm_frequency is not None and args.fm_frequency <= 0:
        parser.error("Frekvence musí být kladné číslo v MHz.")

    if args.use_fm and args.fm_frequency is None:
        print("⚠️  Varování: používáte FM vstup bez zadání frekvence – ponechá se aktuální nastavení přijímače.")

    locations = []
    if args.locations:
        try:
            locations = [int(value.strip()) for value in args.locations.split(",") if value.strip()]
        except ValueError as exc:  # pragma: no cover
            parser.error(f"Neplatný seznam lokalit: {exc}")

    priorities = ["P1", "P2", "P3"] if args.mix else ["P2"] * args.count
    results = []

    for idx in range(args.count):
        priority = priorities[idx % len(priorities)] if args.mix else priorities[idx]
        seq_id = plan_sequence(
            endpoint=args.endpoint,
            priority=priority,
            sequence_symbols=list(args.sequence),
            use_fm=args.use_fm,
            fm_frequency=args.fm_frequency,
            audio_input=args.audio_input,
            audio_output=args.audio_output,
            locations=locations,
            hold_seconds=args.hold_seconds,
        )
        status = trigger(args.endpoint, seq_id)
        results.append({"id": seq_id, "priority": priority, **status})
        print(f"[{idx+1}/{args.count}] priority={priority} -> {status['status']}")

        if args.status:
            snapshot = fetch_status(args.endpoint)
            session = snapshot.get("session")
            print("  ▸ Stav vysílání:", json.dumps(session, indent=2, ensure_ascii=False))

        time.sleep(random.uniform(0.2, 0.6))

    print(json.dumps(results, indent=2, ensure_ascii=False))


if __name__ == "__main__":  # pragma: no cover
    main()

#!/usr/bin/env python3
"""Simulace vytvoření více JSVV sekvencí s různou prioritou."""

from __future__ import annotations

import argparse
import json
import random
import time

try:
    import requests
except Exception as exc:  # pragma: no cover
    print("Knihovna 'requests' není nainstalována (pip install requests).")
    raise SystemExit(1) from exc


def plan_sequence(endpoint: str, priority: str) -> str:
    payload = {
        "items": [
            {"slot": 1, "category": "siren"},
            {"slot": 8, "category": "verbal"},
        ],
        "priority": priority,
        "zones": [],
    }
    response = requests.post(f"{endpoint}/jsvv/sequences", json=payload, timeout=5)
    response.raise_for_status()
    data = response.json()["sequence"]
    return data["id"]


def trigger(endpoint: str, sequence_id: str) -> dict[str, object]:
    response = requests.post(f"{endpoint}/jsvv/sequences/{sequence_id}/trigger", timeout=5)
    response.raise_for_status()
    return response.json()["sequence"]


def main() -> None:
    parser = argparse.ArgumentParser(description="Simulace prioritních JSVV požadavků")
    parser.add_argument("--endpoint", default="http://127.0.0.1/api", help="Base URL backendu")
    parser.add_argument("--count", type=int, default=3, help="Počet sekvencí")
    parser.add_argument("--mix", action="store_true", help="Promíchat priority P1-P3")
    args = parser.parse_args()

    priorities = ["P1", "P2", "P3"] if args.mix else ["P2"] * args.count
    results = []

    for idx in range(args.count):
        priority = priorities[idx % len(priorities)] if args.mix else priorities[idx]
        seq_id = plan_sequence(args.endpoint, priority)
        status = trigger(args.endpoint, seq_id)
        results.append({"id": seq_id, "priority": priority, **status})
        print(f"[{idx+1}/{args.count}] priority={priority} -> {status['status']}")
        time.sleep(random.uniform(0.2, 0.6))

    print(json.dumps(results, indent=2, ensure_ascii=False))


if __name__ == "__main__":  # pragma: no cover
    main()

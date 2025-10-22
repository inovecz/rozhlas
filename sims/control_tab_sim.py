#!/usr/bin/env python3
"""Simulace Control Tab komunikace vůči backendu."""

from __future__ import annotations

import argparse
import json
import sys

try:
    import requests
except Exception as exc:  # pragma: no cover - requests může chybět
    print("Knihovna 'requests' není nainstalována (pip install requests).", file=sys.stderr)
    raise SystemExit(1) from exc


def build_payload(args: argparse.Namespace) -> dict[str, object]:
    if args.button is not None:
        return {
            "type": "button_pressed",
            "button_id": args.button,
        }
    if args.text is not None:
        return {
            "type": "text_field_request",
            "field_id": args.text,
        }
    return {
        "type": "panel_loaded",
        "screen": args.screen,
        "panel": args.panel,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Simulace Control Tab požadavků")
    parser.add_argument("--endpoint", default="http://127.0.0.1/api/control-tab/events", help="URL endpointu")
    parser.add_argument("--button", type=int, help="ID tlačítka (button_pressed)")
    parser.add_argument("--text", type=int, help="ID textového pole (text_field_request)")
    parser.add_argument("--screen", type=int, default=2, help="Screen ID pro panel_loaded")
    parser.add_argument("--panel", type=int, default=1, help="Panel ID pro panel_loaded")
    args = parser.parse_args()

    payload = build_payload(args)
    try:
        response = requests.post(args.endpoint, json=payload, timeout=5)
    except requests.RequestException as exc:
        print(f"HTTP chyba: {exc}", file=sys.stderr)
        raise SystemExit(1) from exc

    print(f"HTTP {response.status_code}")
    try:
        print(json.dumps(response.json(), indent=2, ensure_ascii=False))
    except ValueError:
        print(response.text)


if __name__ == "__main__":  # pragma: no cover
    main()

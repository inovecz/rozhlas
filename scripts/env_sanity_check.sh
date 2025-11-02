#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${1:-.env}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "[ERROR] Env file '$ENV_FILE' not found." >&2
  exit 2
fi

python3 - "$ENV_FILE" <<'PY'
import re
import sys
from collections import defaultdict

if len(sys.argv) < 2:
    print("[ERROR] Missing env file argument", file=sys.stderr)
    sys.exit(2)

path = sys.argv[1]
pattern = re.compile(r"^[A-Za-z0-9_]+\s*=")

seen = {}
duplicates = []
empty = []
port_values = defaultdict(list)

with open(path, "r", encoding="utf-8") as handle:
    for raw_line in handle:
        line = raw_line.rstrip("\n\r")
        if not line or line.lstrip().startswith("#"):
            continue
        if "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        if not pattern.match(f"{key}="):
            continue

        if key in seen:
            duplicates.append(key)
        else:
            seen[key] = line

        stripped = value.strip().strip('"').strip("'")
        if stripped == "":
            empty.append(key)

        if key.endswith("_PORT") and stripped.isdigit():
            port_values[stripped].append(key)

status = 0
if duplicates:
    status = 1
    print("[ERROR] Duplicate keys detected:", file=sys.stderr)
    for key in duplicates:
        print(f"  - {key}", file=sys.stderr)

collisions = [f"{port} ({', '.join(keys)})" for port, keys in port_values.items() if len(keys) > 1]
if collisions:
    status = 1
    print("[ERROR] Conflicting numeric port values detected:", file=sys.stderr)
    for entry in collisions:
        print(f"  - {entry}", file=sys.stderr)

if empty:
    print("[WARN] Keys with empty values:", file=sys.stderr)
    for key in empty:
        print(f"  - {key}", file=sys.stderr)

sys.exit(status)
PY

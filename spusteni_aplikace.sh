#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cd "$ROOT_DIR"

./run.sh &
RUN_PID=$!

if command -v xdg-open >/dev/null 2>&1; then
  xdg-open "http://127.0.0.1:8001" >/dev/null 2>&1 || true
elif command -v sensible-browser >/dev/null 2>&1; then
  sensible-browser "http://127.0.0.1:8001" >/dev/null 2>&1 || true
else
  echo "Navštivte prosím adresu http://127.0.0.1:8001 ručně (prohlížeč nebyl nalezen)." >&2
fi

wait "$RUN_PID"

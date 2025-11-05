#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BROWSER_URL="http://127.0.0.1:8001"
DEFAULT_REPO_DIR="/home/ustredna/rozhlas-final/rozhlas"
BROWSER_DELAY="${SPUSTENI_BROWSER_DELAY:-5}"
SERVER_PORT="8001"
PORT_WAIT_SECONDS=5

# Prioritise the directory the script lives in, but allow override or fallback.
ROOT_DIR=""
for candidate in "$SCRIPT_DIR" "${SPUSTENI_REPO_DIR:-}" "$DEFAULT_REPO_DIR"; do
  if [ -n "$candidate" ] && [ -f "$candidate/run.sh" ]; then
    ROOT_DIR="$(cd "$candidate" && pwd)"
    break
  fi
done

if [ -z "$ROOT_DIR" ]; then
  echo "Nepodařilo se najít run.sh. Nastavte proměnnou SPUSTENI_REPO_DIR na adresář projektu." >&2
  exit 1
fi

cd "$ROOT_DIR"

gather_port_pids() {
  local port="$1"
  if command -v lsof >/dev/null 2>&1; then
    lsof -t -iTCP:"$port" -sTCP:LISTEN 2>/dev/null || true
    return
  fi

  if command -v fuser >/dev/null 2>&1; then
    fuser "${port}"/tcp 2>/dev/null | tr ' ' '\n' | sed 's/[^0-9]//g' | sed '/^$/d' || true
    return
  fi

  if command -v ss >/dev/null 2>&1; then
    ss -ltnp 2>/dev/null | awk -v port=":${port}" '
      $4 ~ port {
        if (match($0, /pid=([0-9]+)/, m)) {
          print m[1];
        }
      }
    ' || true
    return
  fi

  if command -v netstat >/dev/null 2>&1; then
    netstat -ltnp 2>/dev/null | awk -v port=":${port} " '
      $4 ~ port {
        split($NF, proc, "/");
        if (proc[1] ~ /^[0-9]+$/) {
          print proc[1];
        }
      }
    ' || true
    return
  fi

  # No inspection tool available.
  return 0
}

terminate_port_processes() {
  local port="$1"
  local attempt
  local pids_str
  local -a pid_array

  pids_str="$(gather_port_pids "$port" | tr '\n' ' ' | sed 's/[[:space:]]\+$//')"
  if [ -z "$pids_str" ]; then
    return 0
  fi

  read -r -a pid_array <<<"$pids_str"
  if [ "${#pid_array[@]}" -eq 0 ]; then
    return 0
  fi

  echo "Na portu $port běží procesy: ${pid_array[*]} – ukončuji je před spuštěním serveru." >&2
  kill "${pid_array[@]}" 2>/dev/null || true

  for attempt in $(seq 1 "$PORT_WAIT_SECONDS"); do
    sleep 1
    pids_str="$(gather_port_pids "$port" | tr '\n' ' ' | sed 's/[[:space:]]\+$//')"
    if [ -z "$pids_str" ]; then
      echo "Port $port je volný." >&2
      return 0
    fi
  done

  read -r -a pid_array <<<"$pids_str"
  if [ "${#pid_array[@]}" -eq 0 ]; then
    echo "Port $port je volný." >&2
    return 0
  fi

  echo "Procesy ${pid_array[*]} stále využívají port $port – odesílám SIGKILL." >&2
  kill -9 "${pid_array[@]}" 2>/dev/null || true
  sleep 1
}

terminate_port_processes "$SERVER_PORT"

./run.sh &
RUN_PID=$!

sleep "$BROWSER_DELAY"

if command -v chromium >/dev/null 2>&1; then
  chromium --new-window "$BROWSER_URL" >/dev/null 2>&1 &
elif command -v chromium-browser >/dev/null 2>&1; then
  chromium-browser --new-window "$BROWSER_URL" >/dev/null 2>&1 &
elif command -v xdg-open >/dev/null 2>&1; then
  xdg-open "$BROWSER_URL" >/dev/null 2>&1 || true
else
  echo "Navštivte prosím adresu $BROWSER_URL ručně (Chromium ani jiný prohlížeč nebyl nalezen)." >&2
fi

wait "$RUN_PID"

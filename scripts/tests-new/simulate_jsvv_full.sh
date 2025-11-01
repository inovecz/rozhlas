#!/usr/bin/env bash
# simulate_jsvv_full.sh
# Kompletní simulace příchozích JSVV zpráv (KPPS) přes virtuální sériový port.
# - Vytvoří PTY pár /tmp/ttyJSVV_APP a /tmp/ttyJSVV_SIM
# - Nastaví linku interně na 9600 8N1 (dle JSVV pokynů)
# - Volitelně vytvoří /dev/jsvv -> /tmp/ttyJSVV_APP (pokud spuštěno s --link)
# - Odešle sekvenci zpráv: READ_LOG (D1/D2), ACTIVATE, STOP, ACTIVATE, RESET
#
# Spuštění:
#   ./simulate_jsvv_full.sh
#   sudo ./simulate_jsvv_full.sh --link
#
set -euo pipefail

# --- konfigurace (není třeba nic měnit) ---
APP_PTY="/tmp/ttyJSVV_APP"
SIM_PTY="/tmp/ttyJSVV_SIM"
SOCAT_LOG="/tmp/socat_jsvv_full.log"
BACKUP_SYMLINK="/tmp/jsvv_symlink_backup"
SOCAT_PID=""
BAUD=9600        # dle dokumentace
LINK_DEV=0
BASE_DELAY=1.0   # základní prodleva mezi zprávami (sekundy)

usage(){
  cat <<EOF
simulate_jsvv_full.sh -- jednoduchá simulace JSVV (KPPS)

Volby:
  --link       vytvoří /dev/jsvv -> $APP_PTY (vyžaduje sudo)
  --delay N    základní prodleva mezi bloky v sekundách (výchozí ${BASE_DELAY})
  --help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --link) LINK_DEV=1; shift ;;
    --delay) BASE_DELAY="$2"; shift 2 ;;
    --help) usage; exit 0 ;;
    *) echo "Neznámý parametr: $1"; usage; exit 2 ;;
  esac
done

# závislosti
if ! command -v socat >/dev/null 2>&1; then
  echo "[ERROR] Chybi: socat. Instaluj: sudo apt update && sudo apt install -y socat"
  exit 3
fi
if ! command -v python3 >/dev/null 2>&1; then
  echo "[ERROR] Chybi: python3. Instaluj: sudo apt install -y python3"
  exit 4
fi

# úklid při ukončení
cleanup() {
  echo "[CLEANUP] zastavuji socat a obnovuji /dev/jsvv pokud bylo nutno..."
  if [[ -n "${SOCAT_PID:-}" ]] && ps -p "$SOCAT_PID" >/dev/null 2>&1; then
    kill "$SOCAT_PID" 2>/dev/null || true
  fi
  sleep 0.15
  if [[ -f "$BACKUP_SYMLINK" ]]; then
    OLD=$(cat "$BACKUP_SYMLINK")
    if [[ -n "$OLD" ]]; then
      sudo ln -sfn "$OLD" /dev/jsvv || true
    else
      sudo rm -f /dev/jsvv || true
    fi
    rm -f "$BACKUP_SYMLINK" || true
  else
    if [[ "$LINK_DEV" -eq 1 ]] && [[ -L /dev/jsvv ]]; then
      sudo rm -f /dev/jsvv || true
    fi
  fi
  rm -f "$APP_PTY" "$SIM_PTY" 2>/dev/null || true
  echo "[CLEANUP] hotovo."
}
trap 'echo; echo "[INT] přerušeno"; cleanup; exit 0' INT TERM EXIT

# vytvoření virt. PTY páru
echo "[INFO] vytvářím virtuální PTY párek..."
rm -f "$APP_PTY" "$SIM_PTY" 2>/dev/null || true
socat -d -d pty,raw,echo=0,link="$APP_PTY" pty,raw,echo=0,link="$SIM_PTY" >"$SOCAT_LOG" 2>&1 &
SOCAT_PID=$!

# čekej na PTY
for i in {1..30}; do
  [[ -e "$APP_PTY" && -e "$SIM_PTY" ]] && break
  sleep 0.1
done
if [[ ! -e "$APP_PTY" || ! -e "$SIM_PTY" ]]; then
  echo "[ERROR] PTY nebyly vytvořeny. Podívej se do $SOCAT_LOG"
  cleanup
  exit 5
fi

# oprávnění a nastavení linky (9600 8N1) — vše v skriptu, nic ručně
sudo chgrp "$(id -gn)" "$APP_PTY" "$SIM_PTY" >/dev/null 2>&1 || true
sudo chmod 660 "$APP_PTY" "$SIM_PTY" >/dev/null 2>&1 || true
stty -F "$APP_PTY" "$BAUD" cs8 -cstopb -parenb -ixon -ixoff -crtscts || true
stty -F "$SIM_PTY" "$BAUD" cs8 -cstopb -parenb -ixon -ixoff -crtscts || true
echo "[INFO] PTY nastaveny na ${BAUD} 8N1 (dle JSVV pokynů)."

# volitelně vytvoření /dev/jsvv pro aplikaci
if [[ "$LINK_DEV" -eq 1 ]]; then
  echo "[INFO] vytvářím /dev/jsvv -> $APP_PTY (vyžaduje sudo)..."
  if [[ -e /dev/jsvv || -L /dev/jsvv ]]; then
    ORIG=$(readlink -f /dev/jsvv || true)
    echo "${ORIG:-}" > "$BACKUP_SYMLINK"
  else
    echo "" > "$BACKUP_SYMLINK"
  fi
  sudo ln -sfn "$APP_PTY" /dev/jsvv
  sudo chown root:dialout /dev/jsvv >/dev/null 2>&1 || true
  sudo chmod 660 /dev/jsvv >/dev/null 2>&1 || true
  echo "[INFO] /dev/jsvv připraven."
fi

# bin-safe zápis stringu do SIM_PTY (UTF-8)
write_to_sim() {
  local s="$1"
  python3 - "$s" "$SIM_PTY" <<'PY'
import sys
data = sys.argv[1].encode('utf-8')
out = sys.argv[2]
with open(out, "ab", buffering=0) as f:
    f.write(data)
PY
}

write_ln() {
  write_to_sim "$1"
  write_to_sim $'\r\n'
}

# delay helper
delay() {
  python3 - "$1" <<'PY'
import sys, time
time.sleep(float(sys.argv[1]))
PY
}

echo "Restart daemons" 
./run_daemons.sh restart

echo
echo "[READY] Aplikace může číst: $APP_PTY (nebo /dev/jsvv pokud --link)."
echo "[INFO] Spouštím předdefinovanou sekvenci JSVV zpráv..."
echo

# ---------- Sekvence zpráv podle jsvv_requirements (textové ASCII rámce) ----------
# 1) READ_LOG + dva záznamy (D1, D2) — přesně tak, jak je v příkladu v docu
write_ln "READ_LOG"
# D1 — ukázkové pole (číslo záznamu, id operátora, způsob, timestamp, počet příkazů, příkazy,...)
write_ln "1,20,10,1,1620000000,4,1,A,C,B,11111100,1234,1"
# D2 — další záznam
write_ln "2,20,10,2,1620003600,1,20,0,0,0,00011100,1234,2"
delay "$BASE_DELAY"

# 2) Aktivace (blok ACTIVATE) — blok dat s více příkazy za sebou (počet příkazů + kódy)
write_ln "ACTIVATE"
write_ln "3,21,10,1,1620007200,3,1,A,C,11111100,5678,1"
delay "$BASE_DELAY"

# 3) STOP — prázdný blok DATA (ID=3H v dokumentu — semantika: stop přehrávání)
write_ln "STOP"
delay "$BASE_DELAY"

# 4) Další ACTIVATE během STOP (simulace: přijde aktivace po stopu)
write_ln "ACTIVATE"
write_ln "4,22,10,1,1620010800,2,10,30,11111100,9012,1"
delay "$BASE_DELAY"

# 5) RESET — prázdný blok DATA (ID=4H)
write_ln "RESET"
delay "$BASE_DELAY"

# (volitelně můžeš sem doplnit další bloky podle potřeby — stačí upravit řádky write_ln)
echo
echo "[DONE] Sekvence odeslána do simulovaného portu."
echo " - Aplikace čte: $APP_PTY (nebo /dev/jsvv pokud použito --link)."
echo " - Porty zůstanou aktivní pro inspekci. Stiskni Ctrl-C pro úklid."

# keep alive until Ctrl-C for inspection
while true; do sleep 1; done
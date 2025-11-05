#!/usr/bin/env bash
set -euo pipefail

########################
# KONFIGURACE
########################

# Sériový port (např. /dev/ttyAMA3 nebo /dev/ttyUSB0)
PORT="/dev/ttyAMA3"

# Baudrate
BAUD="${BAUD:-57600}"

# Slave adresa (dec)
SLAVE_ID="${SLAVE_ID:-55}"

# Registr v HEX (např. 0x4036)
REG_HEX="${REGISTER:-0x4036}"

# Počet registrů k přečtení
COUNT="${COUNT:-1}"

# Typ registru / výstup (např. 4, 4:hex, 3, 3:int ...)
REG_TYPE="${REG_TYPE:-4}"

# GPIO pin pro DE/RE
GPIO_LINE="${RS485_PIN:-16}"

# Zpoždění, než se přepne do RX (sekundy)
PRE_TX_DELAY="${PRE_TX_DELAY:-0.02}"

# Timeout pro mbpoll (sekundy)
MBPOLL_TIMEOUT="${MBPOLL_TIMEOUT:-1.0}"

# Cesta k binárce pinctrl
PINCTRL_BIN="${PINCTRL_BIN:-pinctrl}"

# Nástroj pro Modbus RTU (vyžaduje balíček `mbpoll`)
MBPOLL_BIN="${MBPOLL_BIN:-mbpoll}"

########################
# FUNKCE
########################

have_cmd() { command -v "$1" >/dev/null 2>&1; }

gpio_set() {
  local val="$1" # 0 = RX (dl), 1 = TX (dh)
  if ! have_cmd "$PINCTRL_BIN"; then
    echo "ERROR: Nenalezen příkaz '$PINCTRL_BIN'" >&2
    exit 1
  fi
  if [[ "$val" == "1" ]]; then
    "$PINCTRL_BIN" "$GPIO_LINE" op dh
  else
    "$PINCTRL_BIN" "$GPIO_LINE" op dl
  fi
}

########################
# KONTROLY
########################

if ! have_cmd "$MBPOLL_BIN"; then
  echo "ERROR: Nenalezen '$MBPOLL_BIN'. Nainstaluj 'mbpoll' (např. apt install mbpoll)." >&2
  exit 1
fi

if [[ ! -c "$PORT" ]]; then
  echo "ERROR: Port '$PORT' neexistuje." >&2
  exit 1
fi

# Převod HEX → DEC
REG_DEC=$((REG_HEX))

# Sestav argumenty pro mbpoll
MBPOLL_ARGS=(
  -m rtu
  -a "$SLAVE_ID"
  -b "$BAUD"
  -d 8
  -s 1
  -P none
  -0
  -t "$REG_TYPE"
  -r "$REG_DEC"
  -c "$COUNT"
  -1
  -o "$MBPOLL_TIMEOUT"
)

########################
# MODBUS ČTENÍ
########################

echo "📡 Čtu registr $REG_HEX (dec $REG_DEC) ze slave $SLAVE_ID..."

# Aktivuj TX
gpio_set 1
sleep "$PRE_TX_DELAY"

# Proveď čtení (přijímač přepneme zpět po dokončení)
set +e
OUT="$("$MBPOLL_BIN" "${MBPOLL_ARGS[@]}" "$PORT" 2>&1)"
RC=$?
set -e

# Pro jistotu vrať DE=0
gpio_set 0

if [[ $RC -ne 0 ]]; then
  echo "❌ Chyba při čtení Modbus registru:"
  echo "$OUT"
  exit $RC
fi

echo "✅ OK: Výsledek čtení z registru ${REG_HEX} (dec ${REG_DEC}):"
echo "$OUT"

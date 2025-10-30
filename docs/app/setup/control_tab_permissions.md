# Control Tab – přístup k sériovému portu

Vývojová úloha **control_tab_listener.py** vyžaduje přístup k zařízení definovanému v proměnné `CONTROL_TAB_SERIAL_PORT` (např. `/dev/tty.usbserial-110`). Pokud proces běží pod běžným uživatelem, macOS standardně omezuje čtení a zápis do tohoto zařízení na skupiny `_uucp` a `_serial`. Bez správného oprávnění skript skončí chybou „Operation not permitted“.

## 1. Kontrola stavu zařízení

```bash
ls -l /dev/tty.usbserial-110
```

V ideálním případě by měl výstup být podobný:

```
crw-rw----  1 root  wheel  0x0000000000000b  1 Jan 12:34 /dev/tty.usbserial-110
```

Pokud místo toho vidíte jiného vlastníka/skupinu nebo uživatel nemá právo číst/zapisovat, pokračujte krokem 2.

## 2. Přidání uživatele do povolené skupiny

Na macOS Sonoma a novějších jsou sériová zařízení typicky spravována skupinou `_uucp`. Přidejte svého uživatele:

```bash
sudo dseditgroup -o edit -a "$USER" -t user _uucp
sudo dseditgroup -o edit -a "$USER" -t user _serial
```

Poté se odhlaste a znovu přihlaste (nebo restartujte), aby se nové členství načetlo.

## 3. Jednorázová změna oprávnění

Pokud se jedná o jednorázový test a nechcete (nebo nemůžete) upravit skupiny, lze dočasně udělit práva přímo:

```bash
sudo chmod 660 /dev/tty.usbserial-110
sudo chown root:"$(id -gn)" /dev/tty.usbserial-110
```

Poznámka: macOS může při odpojení/připojení zařízení oprávnění resetovat, proto je trvalejší cestou členství ve správných skupinách.

## 4. Ověření

Po provedení změn spusťte:

```bash
run_daemons.sh start
```

Ve výstupu by se již neměla objevovat hláška „Operation not permitted“. V logu `storage/logs/daemons/control_tab_listener.log` uvidíte úspěšné spuštění nebo případné další chyby (např. token, webhook).

## 5. Automatická kontrola

`run_daemons.sh` nyní před spuštěním listeneru ověřuje existenci a přístupnost zařízení. Pokud se kontrola nezdaří, skript vypíše odkaz na tento dokument a nezkouší listener startovat, dokud nebude problém vyřešen.

## KPPS laboratorní test – příprava hardware a simulace

Tento návod popisuje dva scénáře:

1. **Test s reálnou KPPS** připojenou přes RS‑232/USB převodník.
2. **Simulace KPPS** na jediném počítači (virtuální sériový pár).

V obou případech platí stejné sériové parametry požadované HZS: 9600 bps, 8 datových bitů, žádná parita, 1 stop bit, žádné řízení toku, kabel typu null‑modem.

---

### 1. Hardwarový test s reálnou KPPS

1. Připojte KPPS k počítači pomocí USB‑RS232 převodníku (DE‑9 → USB). Ověřte výsledný port např. `ls /dev/tty.usbserial*`.
2. Spusťte stack s nastavením portu (můžete přepsat proměnnou při startu):

   ```bash
   JSVV_PORT=/dev/tty.usbserial-XXXX \
   JSVV_BAUDRATE=9600 \
   JSVV_PARITY=N \
   JSVV_STOPBITS=1 \
   JSVV_BYTESIZE=8 \
   ./run.sh
   ```

   (Alternativně zapište tyto hodnoty do `.env` a `run.sh` pustťe standardním způsobem.)

3. Pokud chcete port nejdřív otestovat ručně, než spustíte backend, použijte příkaz:

   ```bash
   ./scripts/jsvv_lab_test.sh device /dev/tty.usbserial-XXXX
   ```

   Příkaz vypíše checklist a nabídne možnost zkusit čtení přes `jsvv_control.py listen`.

4. Po spuštění `run.sh` sledujte `storage/logs/daemons/jsvv_listener.log` – měly by se objevovat řádky `[FRAME]` a příslušné JSONy v Laravel logu.

5. Audio vstup splňující požadavky HZS:
   - linková úroveň 316 mV RMS (±10 %),
   - impedance 600 Ω (±10 %),
   - přenosové pásmo 120 Hz – 8 kHz,
   - galvanicky oddělený, nesymetrický kanál,
   - konektor 3,5 mm jack.

   Pro laboratorní test využijte linkový výstup mixpultu nebo generátor, případně DI box pro přizpůsobení.

---

### 2. Simulace KPPS (virtuální porty)

1. Ujistěte se, že máte nainstalovaný `socat`.
2. Spusťte helper skript, který vytvoří virtuální pár portů a zobrazí další kroky:

   ```bash
   ./scripts/jsvv_lab_test.sh simulate
   ```

   Výstup skriptu obsahuje:

   - cestu k portu pro aplikaci (`JSVV_PORT=/tmp/kpps-app-XXXX`),
   - port pro simulátor (`/tmp/kpps-sim-XXXX`),
   - příkazy pro start `run.sh` s jednorázovým override,
   - ukázkový příkaz, jak poslat rámec `STATUS_KPPS`.

3. Spusťte stack s dočasným override proměnné `JSVV_PORT` (viz bod 2 ve výpisu skriptu).
4. V jiném terminálu odešlete testovací rámec:

   ```bash
   ./scripts/jsvv_lab_test.sh send-sample /tmp/kpps-sim-XXXX STATUS_KPPS
   ```

5. Do logu `storage/logs/daemons/jsvv_listener.log` by měl dorazit `[FRAME]` záznam. Můžete také pustit ruční poslouchání:

   ```bash
   python3 python-client/jsvv_control.py --port /tmp/kpps-app-XXXX listen --until-timeout
   ```

   (Tento listener vypněte, než spustíte `run.sh`, aby si procesy nepřekážely.)

6. Ukončete simulaci stiskem `Ctrl+C` v okně se spuštěným helper skriptem (pokud jste nepoužili volbu `--keep`).

---

### Poznámka ke kolizi na sériovém portu

Skript i backend používají sdílený zámek `modbus:serial`, nicméně v reálných testech se vyhněte souběžnému čtení stejného portu z více nástrojů (např. `listen` + běžící `run.sh`). Pokud potřebujete ruční diagnostiku, dočasně zastavte `jsvv_listener` (`run_daemons.sh stop`) a po testu jej znovu spusťte.

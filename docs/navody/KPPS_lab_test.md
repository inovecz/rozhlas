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

5. **Audio konektory pro externí zdroje** (požadavky HZS):

   - přístupný linkový vstup (316 mV RMS, 600 Ω, 120 Hz – 8 kHz) na konektoru **3,5 mm jack – F**; softwarově vede na logický vstup `jsvv_external_primary`,
   - přístupný vstup z VyC na stejném typu konektoru (mapuje se na `jsvv_remote_voice`),
   - pokud je k dispozici sekundární externí vstup, vyveďte jej také (mapuje se na `jsvv_external_secondary`).

   Pro laboratorní test lze použít linkový výstup mixpultu nebo generátor, případně DI box pro přizpůsobení impedancí.

6. **Inicializace ALSA/TLV320** – před zkouškou nastavte mixer na kartě 2:

   ```bash
   scripts/configure_tlv320.sh 2
   ```

   Skript zapne Line Out, Mic3/Line2 a uloží stav přes `alsactl`.

7. **Bezpotenciálové tlačítko „Zkouška sirén“** – připravte svorky dle schématu. Kontakt musí být bezpotenciálový; firmware očekává připojení na vybraný GPIO (`GPIO_BUTTON_CHIP`, `GPIO_BUTTON_LINE` v `.env`). Tlačítko vyvolá REST požadavek s přednastavenou VI č. 1 („Zkouška sirén“). Pro laboratorní simulaci lze použít REST volání:

   ```bash
   curl -X POST http://127.0.0.1:8001/api/jsvv/events \
     -H 'Content-Type: application/json' \
     -d '{"raw":"REMOTE","payload":{"networkId":1,"vycId":1,"kppsAddress":"0x0001","type":"ACTIVATION","command":"REMOTE","params":{},"priority":"P2","timestamp":'"$(date +%s)"',"rawMessage":"REMOTE"}}'
   ```

   (Slouží jako náhrada za fyzické tlačítko; upravte parametry dle potřeby.)

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

7. Pro plně automatický test (virtuální porty + listener + zápisy do logu) použijte skript:

   ```bash
   scripts/tests/jsvv_e2e.sh
   ```

   Skript vytvoří PTY pár, dočasně upraví `.env`, spustí `jsvv_listener.py`, přehraje sekvenci REMOTE/LOCAL/EXT1/STOP… a po dokončení vrátí konfiguraci do původního stavu. Výsledek sledujte v `/log` (nové položky „JSVV příkaz …“) a `storage/logs/daemons/jsvv_listener_test.log`.

---

### Poznámka ke kolizi na sériovém portu

Skript i backend používají sdílený zámek `modbus:serial`, nicméně v reálných testech se vyhněte souběžnému čtení stejného portu z více nástrojů (např. `listen` + běžící `run.sh`). Pokud potřebujete ruční diagnostiku, dočasně zastavte `jsvv_listener` (`run_daemons.sh stop`) a po testu jej znovu spusťte.

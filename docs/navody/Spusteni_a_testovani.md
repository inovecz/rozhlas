# Sarah V – Spuštění aplikace a kontrolní scénáře

Tento návod shrnuje kompletní proces uvedení backendu, webového rozhraní a podpůrných démonů do provozu. Druhá část popisuje, co a jak otestovat, aby bylo ověřeno správné fungování klíčových funkcí (JSVV, SMS notifikace, plánování vysílání…).

---

## 1. Požadavky na prostředí

- **Operační systém:** Debian/Ubuntu 22.04+ nebo macOS (vývoj). Na produkci doporučený Linux.
- **PHP 8.2** s rozšířeními: `mbstring`, `openssl`, `pdo`, `sqlite`, `fileinfo`, `curl`, `pcntl`.
- **Composer 2.x** a přístup ke Packagist.
- **Node.js 18+** (v produkci lze jen buildnout a nasadit `npm run build`).
- **Python 3.11** + `pip` (pro Modbus a JSVV klienta).
- **FFmpeg / ffprobe** (pro zjištění délek nahrávek).
- **Redis** (volitelné; default používá database cache/queue).
- **Homebrew (macOS)** – zajistit knihovny `icu4c`, příp. nastavit `DYLD_LIBRARY_PATH`.

---

## 2. První spuštění

### 2.1 Stažení repozitáře a bootstrap
```bash
git clone <repo-url> rozhlas
cd rozhlas
bash scripts/bootstrap.sh
```
Skript `bootstrap.sh` se pokusí doinstalovat požadované balíčky (PHP, Composer, Node.js/npm, python3, ffmpeg) přes Homebrew nebo apt-get, nainstaluje PHP/JS závislosti a připraví Python virtuální prostředí. Pokud v `python-client/` chybí `requirements.txt`, krok instalace Python balíčků se přeskočí. Stačí jej spustit jednou po klonování repozitáře.

### 2.2 Rychlá instalace (doporučeno)

Spusťte zabudovaný instalační příkaz, který provede migrace, seedery a založí administrátorský účet:

```bash
php artisan app:install
# nebo zkratka
./scripts/install.sh
```

Příkaz se zeptá na e-mail a heslo administrátora, případně vygeneruje silné heslo. Po dokončení vytiskne přihlašovací údaje do konzole.

### 2.3 Konfigurace `.env`
```bash
cp .env.example .env
```
V souboru `.env` nastavte:

- `APP_URL`, `APP_ENV`, `APP_KEY` (případně `php artisan key:generate`).
- `DB_CONNECTION` + parametry (SQLite/MariaDB/PostgreSQL).
- `QUEUE_CONNECTION` (základně `database`).
- `JSVV_SEQUENCE_MODE` (`local_stream` nebo `remote_trigger`).
- `JSVV_DEFAULT_*`, `JSVV_DURATION_CACHE_SECONDS` (ponechte default nebo upravte).
- `BROADCAST_DEFAULT_ROUTE` (hop adresa/e pro manuální vysílání; např. `1,116,225`).
- `SMS_GOSMS_CLIENT_ID`, `SMS_GOSMS_CLIENT_SECRET`, `SMS_GOSMS_CHANNEL`, `SMS_GOSMS_SENDER` (využije `SmsNotificationService`).
- `PYTHON_BINARY`, `MODBUS_SCRIPT`, `JSVV_SCRIPT` (pokud se liší cesty).
- Pro produkci doplňte SMTP (`MAIL_*`) a storage nastavení.

### 2.4 Migrace databáze
> Pokud byl spuštěn `php artisan app:install`, migrace jsou již aplikovány a tento krok lze vynechat.

```bash
php artisan migrate
```
Při chybě se starší verzí Laravelu přejděte do `app/Console/Commands/MonitorAlarmBuffer.php` a ověřte implementaci `Symfony\Component\Console\Command\SignalableCommandInterface`.

### 2.5 Build frontendů
- **Vývoj:** `npm run dev`
- **Produkce:** `npm run build` (výstup jde do `public/build` + Vite manifest)

### 2.6 Start aplikace
Možnosti:

1. **Integrovaný skript (vývoj):**
   ```bash
   ./run.sh
   ```
   Spustí `php artisan serve`, Vite dev server a `php artisan alarms:monitor`.

2. **Ruční start (produkce):**
   - Web server (nginx/apache) směrem na `public/index.php`.
   - Queue worker: `php artisan queue:work --queue=default,jsvv,notifications`.
   - Scheduler (cron): `* * * * * php /path/artisan schedule:run`.
   - Alarm monitor: `php artisan alarms:monitor --interval=5` (např. přes Supervisor).
   - Python démony: `./run_daemons.sh start` (GSM + JSVV listener). Zastavení `./run_daemons.sh stop`.

### 2.7 Kontrola logů
Logy se ukládají do `storage/logs`:

- `laravel.log` – aplikační logy, JSVV sekvence, chyby SMS.
- `run/backend.log`, `run/frontend.log`, `run/alarm-monitor.log` – pokud běží `run.sh`.
- `storage/logs/daemons/jsvv_listener.log`, `.../gsm_listener.log`.

---

## 3. Konfigurační checklist po nasazení

1. Vyplnit GoSMS přístup (Client ID + Secret) a otestovat přihlášení do GoSMS portálu.
2. Rozhodnout režim `JSVV_SEQUENCE_MODE`:
   - `local_stream` – ústředna vysílá, vhodné pro laboratorní testy.
   - `remote_trigger` – koncové přijímače si přehrají sekvenci; backend čeká dle odhadu délky.
3. Nastavit `JSVV_PORT`, `JSVV_BAUDRATE` v prostředí, aby Python klient komunikoval s reálnou linkou.
4. Nastavit standardní sekvence v modulu **Nastavení JSVV → Tlačítka** a uložit zvukové banky.
5. Přidat čísla a texty pro SMS/E-mail notifikace (JSVV Nastavení → Notifikace).
6. Ověřit, že `php artisan storage:link` existuje, pokud využíváme soubory.
7. Zkontrolovat, že Supervisor/cron spouští:
   - `queue:work`
   - `schedule:run`
   - `alarms:monitor`
   - `run_daemons.sh start` (lze dát do `rc.local` nebo `systemd`).

---

## 4. Testovací scénáře

### 4.1 Základní sanity check
1. `php artisan test` – spustí unit/feature testy backendu.
2. `npm run lint` / `npm run build` – ověřit, že frontend projde lintem/buildem.
3. Otevřít `https://<app>` → Přihlásit se testovacím účtem.
4. Zkontrolovat hlavní dashboard: aktivní zdroj, logy, modul JSVV.

### 4.2 JSVV – lokální stream
1. Nastavit `JSVV_SEQUENCE_MODE=local_stream`.
2. V UI → **Poplachy JSVV** → spustit tlačítko „Zkouška sirén“.
3. Ověřit:
   - V `broadcast_sessions` se vytvořil záznam se `source=jsvv`.
   - V `stream_telemetry_entries` existují položky `jsvv_sequence_started` a `jsvv_sequence_completed`.
   - Log `laravel.log` obsahuje zprávu o spuštění.
   - Ostatní streamy (např. přímé hlášení) jsou dočasně pozastavené.
4. Zastavit poplach pomocí tlačítka `Stop` nebo počkat na dokončení.

### 4.3 JSVV – remote trigger
1. V `.env` přepnout `JSVV_SEQUENCE_MODE=remote_trigger`, vymazat cache (`php artisan config:clear`).
2. Spustit vybranou sekvenci (ideálně krátkou).
3. Ověřit:
   - V logu je zpráva „playback_mode=remote_trigger“.
   - Backend čeká (běží smyčka) a `actual_duration_seconds` odpovídá odhadu (případně upravte defaulty).
   - V případě potřeby přepnout zpět na `local_stream`.

### 4.4 SMS notifikace
1. V **Nastavení JSVV → Notifikace** aktivovat SMS poplach, přidat testovací číslo.
2. Odeslat poplach.
3. Sledujte:
   - V logu: `SMS sending failed` nebo `GoSMS` v případě chyby.
   - Přímo v GoSMS portálu zkontrolovat doručení (pokud jsou produkční údaje).
4. Po testu číslo vyjměte nebo označte, aby nedošlo k nechtěnému rozesílání.

### 4.5 Monitor alarm bufferu
1. Spusťte `php artisan alarms:monitor --interval=2` (pokud již neběží).
2. Z generátoru (např. `python-client/simulators/jsvv_simulator.py`) poslat testovací data do Modbus bufferu.
3. Ověřit, že se v logu objeví `Alarm z hnízda …` a že SMS byla (ne)odeslána podle konfigurace.

### 4.6 Plánování vysílání
1. Vytvořit plán (např. echo hlášení za 10 minut).
2. Ověřit, že běží `queue:work` a `schedule:run`.
3. Po uplynutí času zkontrolovat `broadcast_sessions`, logy a frontu.
4. Pokud během čekání spustíte JSVV poplach, plán musí počkat – záznam by se měl automaticky requeue.

### 4.7 Ověření Control Tab / API
- Pokud je dostupný Control Tab, spustit testovací tlačítko („Zkouška sirén“) a sledovat reakci UI.
- Bez panelu lze použít `sims/control_tab_simulator.py`.
- Ověřit, že backend přijme požadavek, zařadí jej do fronty a odpoví JSON s výsledkem.

### 4.8 JSVV parser + Control Channel
1. Spusťte `./run_daemons.sh start` (spustí `control_channel_worker`, `jsvv_listener`, `gsm_listener`). Pro živý Modbus nastavte `.env` / systémovou proměnnou `CONTROL_CHANNEL_DRY_RUN=0` a zkontrolujte parametry `MODBUS_*`.
2. Zkontrolujte logy v `storage/logs/daemons/`:
   - `control_channel_worker.log` – měl by obsahovat `Control channel listening on ...`.
   - `jsvv_listener.log` – po přijetí rámců se zapisují stavy `RECEIVED/QUEUED/FORWARDED/DONE`.
3. Ověřte handshake control channelu: `socat - UNIX-CONNECT:/var/run/jsvv-control.sock` → očekávaný výstup `READY`.
4. V případě potřeby můžete statusově pingnout backend: `php artisan tinker` → `(app(App\Services\ControlChannelService::class))->status()`.
5. Zastavení démonů: `./run_daemons.sh stop` (uvolní socket a PID soubory).

### 4.9 Příjem rámce přes Artisan
1. Připravte ukázkový JSON (`sample.json`):
   ```json
   {
     "networkId": 5,
     "vycId": 12,
     "kppsAddress": "0x1A2B",
     "type": "ACTIVATION",
     "command": "SIREN_SIGNAL",
     "params": {"signalType": 1},
     "priority": "P2",
     "timestamp": 1696157700,
     "rawMessage": "SIREN 1"
   }
   ```
2. Spusťte `php artisan jsvv:process-message "$(cat sample.json)"`.
3. Ověřte tabulky:
   - `jsvv_messages` (status `VALIDATED`, `dedup_key` unikátní),
   - `jsvv_events` (`message_validated` a případně `duplicate_detected` při druhém spuštění),
   - `control_channel_commands` (`pause_modbus`/`stop_modbus` podle priority).
4. Sledujte log `laravel.log` – měla by se objevit zpráva o uloženém příkazu control channel (`Control channel command acknowledged`).

---

### 4.10 Playlist z nahrávek
1. Připravte JSON payload:
   ```json
   {
     "recordings": [
       {"id": "demo-track-1"},
       {"id": "demo-track-2", "gapMs": 500}
     ],
     "locations": [55],
     "route": [101]
   }
   ```
2. Zavolejte `POST /api/live-broadcast/playlist` s tímto payloadem. Odpověď obsahuje `playlist.id`.
3. Sledujte `stream_telemetry_entries` (typy `playlist_started`, `playlist_item_*`, `playlist_completed`) nebo `GET /api/live-broadcast/status` → sekce `control_channel` + aktuální session.
4. Pro okamžité ukončení playlistu použijte `POST /api/live-broadcast/stop` s `reason=manual_stop`; záznamy se označí jako `cancelled`/`failed` podle výsledku.
5. Chcete-li playlist zrušit, zavolejte `POST /api/live-broadcast/playlist/{id}/cancel` a ověřte, že telemetrie obsahuje `playlist_cancelled_runtime`.

---

### 4.11 GSM modul SIM7600G-H
Před startem démonů upravte `.env`: nastavte `GSM_ENABLED=true` a vyplňte `GSM_SIM_PIN` (např. `1234`) podle vložené karty. Mixer preset `gsm` tak zajistí, že se při hovoru aktivuje vstup z modemu.

1. Ověřte připojení modemu (`/dev/ttyUSB2` výchozí) a spusťte démony `./run_daemons.sh start`. Log `storage/logs/daemons/gsm_listener.log` musí hlásit úspěšnou inicializaci AT rozhraní.
2. Přidejte testovací číslo do whitelistu: `POST /api/gsm/whitelist` s JSON `{ "number": "+420123456789", "label": "Test" }`.
3. Zavolejte na modem – po přijetí události `ringing` backend vrátí `action=answer`, daemon provede `ATA` a vyšle `accepted`. Sledujte `stream_telemetry_entries` (`gsm_call_ringing`, `gsm_call_started`).
4. Ukončení hovoru (`NO CARRIER`) vytvoří `gsm_call_finished` a backend automaticky spustí `StreamOrchestrator->stop`. V logu se objeví `control_channel_pause`.
5. Pro neautorizované číslo backend vrátí `action=reject`; daemon provede `ATH` a telemetrie obsahuje `gsm_call_unauthorised`.

---

### 4.12 Control Tab (ESP32-P4)
1. Ověřte sériové rozhraní panelu (`/dev/ttyUSB3` dle `.env`). Spusťte démony `./run_daemons.sh start`; log `storage/logs/daemons/control_tab_listener.log` musí hlásit „Control Tab listening on …“.
2. Stisk tlačítka → `control_tab_listener` odešle JSON na `/api/control-tab/events`. Odpověď obsahuje `action=ack` a ESP obdrží `>>>:` zprávu se stavem 1/0. Sledujte tabulku `stream_telemetry_entries` (`control_tab_button_pressed`).
3. Textová pole: panel vyšle `text_field_request`; backend vrátí `action=text` a modul obdrží `>>>TEXT:...` s hodnotou. V DB je záznam `control_tab_text_field_request`.
4. Ladění: v simulaci spustíte `python-client/daemons/control_tab_listener.py --simulate --webhook http://127.0.0.1/api/control-tab/events`. V CLI uvidíte příchozí a odchozí rámce.

---

## 5. Doporučený provozní režim

- **Logging:** ponechat `LOG_LEVEL=info` (produkce). Sledovat `jsvv_sequence_failed` a `SMS sending failed`.
- **Zálohování:** pravidelně kopírovat složku `storage/app` (nahrávky) a databázi.
- **Aktualizace:** při upgradu composer balíčků vždy spustit `php artisan migrate`.
- **Monitoring:** přidat `queue:work` a `alarms:monitor` do Supervisoru (restart při pádu). V logu zjistit `shouldExit` signál.

---

Tento dokument lze doplnit o další specifika (např. mapování lokací, integraci FM modulu). Udržujte jej aktuální po každé významnější změně konfigurace.

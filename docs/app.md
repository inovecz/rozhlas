# Dokumentace aplikace rozhlasové ústředny

> **Cíl:** poskytnout detailní přehled o architektuře, požadavcích, nasazení, testování a provozu aplikace JSVV ústředny tak, aby nový vývojář nebo integrátor dokázal systém rychle pochopit a bezpečně s ním pracovat.

---

## 1. Přehled systému

Aplikace integruje webové rozhraní (Laravel + Vue.js), Python daemony pro komunikaci se sériovými zařízeními (Modbus, Control Tab, GSM) a podpůrné skripty pro testování pomocí virtuálních portů (PTY).

**Hlavní funkce:**
- Živé hlášení s volbou audio vstupu (mikrofon, soubor, FM, GSM) a cílových lokalit.
- Plánování playlistů/hlášení s prioritami a detekcí kolizí.
- JSVV poplachy, včetně ad-hoc sekvencí a integrace s Control Tab panelem.
- Modbus komunikace (spouštění/stav RF jednotky, čtení alarm bufferu, diagnostika).
- Integrace s GSM modemem (hovory whitelist/PIN, streamování audio hovoru).
- Diagnostika napájení, baterie, kabinetu (DeviceDiagnosticsService).
- Testovací sada PTY a shell skriptů.

---

## 2. Požadavky a jejich stav

Kompletní požadavky viz `docs/overall_summary.txt`, přehled stavu implementace `docs/overall_summary_status.md` a `docs/production_status.md`. Shrnutí:

| Oblast | Stav | Poznámka |
| --- | --- | --- |
| Živé vysílání & mix | ✅ | MixerService, StreamOrchestrator. |
| Plánování | ✅ / 🧪 | Implementováno; kolizní testy čekají na HW ověření. |
| JSVV poplachy | ✅ / 🛠️ | UI + backend hotové, chybí rozesílky/“hlas po tónu”. |
| Control Tab | 🛠️ / 🧪 | CRC/ACK fungují, mapa tlačítek je částečná, nutné ověřit HW. |
| GSM workflow | 🛠️ / 🧪 | Whitelist funguje, chybí DTMF PIN, reálné testy. |
| Diagnostika KPPS | 🧪 / ❌ | Modbus diagnostika částečně hotová, servisní UART chybí. |
| Tooling / CI | 🛠️ / ❌ | PTY testy hotové, chybí `run_full_validation.sh`, changelog, RF log. |

Detailní akční seznam: `docs/overall_summary_status_steps.md`.

---

## 3. Architektura

### 3.1 Backend (PHP – Laravel)
- **Controllers** (`routes/api.php`, `app/Http/Controllers`) – REST API pro webové UI, Control Tab, GSM.
- **Services** (`app/Services`) – business logika:
  - `StreamOrchestrator` – řídí živé vysílání, priority, auto-timeout job.
  - `MixerService` – ALSA profily, zachytávání záznamu, přepínání vstupů.
  - `JsvvSequenceService` – plánování/trigger poplachů, interakce s Python klientem.
  - `ControlTabService` – reakce na panel (button/text), integrace s JSVV.
  - `DeviceDiagnosticsService` – čtení Modbus registrů, logika diagnostiky.
  - `GsmStreamService` – whitelist, PIN, orchestrátor start/stop z GSM eventů.
- **Jobs** (`app/Jobs`) – `StartPlannedBroadcast`, `EnforceBroadcastTimeout` pro auto stop, `RunJsvvSequence`.
- **Models** – Eloquent pro `BroadcastSession`, `JsvvSequence`, `Recording`, `DeviceHealthMetric`, `GsmCallSession`…
- **Libraries** – `PythonClient` wrapper spouštějící CLI moduly (`modbus_control.py`, `jsvv_control.py`).

### 3.2 Frontend (Vue.js)
- Umístění: `resources/js/views`.
  - `LiveBroadcast.vue` – živé hlášení.
  - `Scheduler.vue`, `ScheduleTask.vue` – plánování.
  - `Jsvv.vue` – poplachy (custom builder, Test, STOP, FM preview).
  - `SystemStatus.vue` – telemetrie, diagnostika.
  - `Map.vue`, `Log.vue`, `Users.vue` – podpora sledování stavu.
- Router: `resources/js/router.js`.

### 3.3 Python klient / Daemony
- Umístění: `python-client/`.
- **CLI skripty** (`modbus_control.py`, `jsvv_control.py`) – volané přes `PythonClient`.
- **Daemons (`python-client/daemons/`)**:
  - `jsvv_listener.py` – parser/dispatch KPPS příkazů -> `jsvv:handle`.
  - `control_tab_listener.py` – komunikace s panelem (CRC, ack, progress animace).
  - `gsm_listener.py` – AT commands, HTTP hooks, whitelist/PIN.
  - `alarm_poller.py` – čtení Modbus alarm bufferu, volání `alarm:poll`.
- **Locking** – `_locks.py` poskytuje `PortLock` (flock) pro test kontrakt (bonus).

### 3.4 Testovací skripty
- Umístění: `scripts/tests/` a kopie v `scripts/tests-final/` (aktuální sada).
- `_pty.sh` – generuje PTY pary pro testy.
- Jednotlivé scénáře pro JSVV, GSM, Control Tab, Modbus RF.

---

## 4. Konfigurace & nasazení

### 4.1 Environment
- Hlavní soubor: `env-file.txt` (AUDIO_*). Staré `BROADCAST_*` ponechány jako fallback.
- Konfigurace:
  - `config/broadcast.php` – mix, playlist, auto-timeout, scheduling.
  - `config/rf.php` – Modbus unit, priority levels, RS-485 driver volba.
  - `config/control_tab.php` – serial port, button akce, text fields.
  - `config/gsm.php` (pokud použit) – modem port, PIN, webhook.
  - `config/logging.php` – log kanály (doplnit CLI kanál!).

### 4.2 Instalace závislostí
- PHP 8.x, Laravel (viz `composer.json`) + Redis (cache/queue) doporučený.
- Node.js/Tailwind/Vite (`package.json`) pro frontend.
- Python 3.10+ s `pyserial`, `pymodbus`, `requests` (viz `python-client/requirements.txt` pokud existuje).
- Systémové balíčky:
  - `libgpiod`, `socat`, `ffmpeg`, `arecord`, `supervisor`.
  - Přístup k sériovým portům (/dev/ttyUSB*, /dev/ttyAMA*).

### 4.3 Nasazení
- Laravel deploy: `composer install`, nastavit `.env`, `php artisan migrate --seed`.
- Frontend: `npm install && npm run build`.
- Python daemony:
  - Spuštění ručně `./run_daemons.sh` (logy do `storage/logs/daemons`).
  - Supervisor konfigurace v `deploy/supervisor/*.conf` (queue worker, control tab, jsvv, gsm, alarm).
- Queue worker (Redis) – `php artisan queue:work`.
- V případě auto-timeout jobu zkontrolovat queue `monitoring`.

### 4.4 Logging & Monitoring
- Laravel logy (`storage/logs/laravel.log`, `mixer.log`).
- Doporučeno: vytvořit `cli.log` pro artisan/CLI.
- Python logy – Daemons logují do souborů nebo stdout (nastavit v supervisoru).
- Device diagnostics – `DeviceHealthMetric` tabulka + UI (`SystemStatus.vue`).
- Nutné přidat monitorování Modbus/TX/RX registrů (plánováno).

---

## 5. Komunikace a protokoly

### 5.1 Modbus
- Servery: RF jednotka (REG 0x3000–0x4037).
- Operace:
  - `RfBus::txStart/txStop` -> Modbus `writeTxControl`.
  - `readStatus()` -> TxControl/RxControl/Status/Error/Frequency.
  - `readBuffersLifo()` -> alarm buffer.
- Python CLI `modbus_control.py` – modulární příkazy (start-stream, status, read-alarms, set-route).
- Priority queue (Cache) zajišťuje STOP > JSVV > GSM > plán > polling.

### 5.2 Control Tab
- ASCII protokol s `<<<` delim.
- Listener – `python-client/daemons/control_tab_listener.py`.
- Service – `ControlTabService` reaguje na `panel_loaded`, `button_pressed`, `text_field_request`. Vrací ack/text/animations.
- TEST animace – `buildTestControlTabPayload` + `progress_text` frames.
- Zatím neimplementováno kompletní mapování (ID 19/20) a servisní scénáře.

### 5.3 JSVV (KPPS)
- `jsvv_listener.py` – přijímá ASCII frames, dedup, priority, volá `php artisan jsvv:handle`.
- `JsvvMessageService` – validace, uložení, duplicate detection.
- `JsvvSequenceService` – plan/trigger, remote vs local playback, integration s `StreamOrchestrator`.
- Modbus programování sekvencí (pokud se používá remote DTRX).
  
### 5.4 GSM
- `gsm_listener.py` – AT commands (CLIP/COLP/CLCC), HTTP webhook (Laravel).
- `GsmStreamService` – whitelist/PIN, orchestrátor start/stop, telemetrie.
- Zbývá implementovat DTMF a audio bridging do mixéru.

---

## 6. Datový model

| Tabulka | Účel | Poznámka |
| --- | --- | --- |
| `broadcast_sessions` | Historie živých vysílání (source, route, status). | Používá `StreamOrchestrator`. |
| `broadcast_playlists`, `broadcast_playlist_items` | Plánovaná hlášení/playlisty. | Runbook krok 4. |
| `jsvv_sequences`, `jsvv_sequence_items` | JSVV poplachy + varianty. |
| `jsvv_messages`, `jsvv_events` | Příchozí KPPS rámce a log jejich zpracování. |
| `recordings` | Metadata záznamů. |
| `device_health_metrics` | Diagnostika (stav napájení, baterie…). |
| `gsm_call_sessions`, `gsm_whitelist_entries`, `gsm_pin_verifications` | Telefonní workflow. |

Migrace se spouští standardně `php artisan migrate`. Před nasazením do produkce ověřte, že jsou tabulky kompletní (viz `docs/production_status.md`, bod 11).

---

## 7. Testování

### 7.1 Automatické testy (PTY / shell)
Skripty v `scripts/tests-final/` (kopie aktuální sady):
- `jsvv_roundtrip.sh`, `jsvv_alarm_test.sh`, `jsvv_e2e.sh` – JSVV plan/trigger.
- `control_tab_crc_and_events.sh`, `control_tab_serial_probe.sh` – Panel CRC/serial check.
- `gsm_incoming_call_whitelist.sh` – whitelist handshake; POZOR: neřeší DTMF.
- `rf_tx_start_stop.sh`, `rf_read_buffers_lifo.sh` – Modbus start/stop, LIFO buffer.
- `modbus_alarm_e2e.sh`, `alarm_tests.sh` – alarm buffer workflow.
- `_pty.sh` – helper: `APP_TTY`/`FEED_TTY` virtuální port pár.

Běh: `cd scripts/tests-final && ./jsvv_roundtrip.sh` atd. Vyžaduje `socat`. Pro CI se očekává meta skript `scripts/ci/run_full_validation.sh` (nutno doplnit).

### 7.2 Ruční / integrační testy
- **ALSA** – ověřit přepínání vstupů (MixerService log).
- **Control Tab** – připojit reálný panel, vyzkoušet mapování, TEST animaci.
- **GSM** – reálné volání, whitelist/PIN, audio stream do vysílání.
- **Modbus** – ověřit priority, LIFO buffer, diagnostiku registru status/error.
- **Diagnostika** – simulovat bity ve status registru, zkontrolovat UI (SystemStatus).
- **Planner** – spustit plánované hlášení během aktivního testu (priority).

Zaznamenat logy + časování pro SLA (≤3 s).

---

## 8. Operativní úkoly a TODO

Viz `docs/overall_summary_status_steps.md`. Krátké shrnutí:
1. Dokončit GSM workflow (DTMF, audio bridging, HW test).
2. Servisní UART/diag KPPS + monitoring.
3. Rozšířit Control Tab (mapování tlačítek, retransmise, testy).
4. Rozšíření JSVV workflow (SMS/email, hlas po tónu).
5. Doplnit playFile/stopFile a CLI log channel.
6. Vytvořit CI skript, changelog, předávací dokumentaci.
7. Provest hardware integrační testy (ALSA, Modbus, Control Tab, GSM).
8. Dodat RF logging/identifikaci registrů (D13–D20).

---

## 9. Nejčastější problémy a doporučení

- **Queue worker nebeží** – auto-timeout/Planned Broadcast zůstane viset. Kontrola `php artisan queue:failed` + supervisor.
- **Sériové porty** – ověřit oprávnění (dialout), správné `/dev/tty*` v `.env`.
- **Python knihovny** – `pyserial`, `pymodbus` – bez nich daemony spadnou. V test režimu fallback loguje, ale akce se nevykoná.
- **Prioritní konflikty** – JSVV STOP (P0/P1) má preemptovat – zkontrolujte `config/rf.php` aliasy. Při úpravách zachovat mapování STOP/ABORT aliasů.
- **Diagnostika** – pro reálné senzory je potřeba znát mapování bitů (dle dokumentace). Standardně bit 0–3.
- **Testy + CI** – bez `scripts/tests-final`/`_pty.sh` neproběhnou PTY testy. Připravit meta skript.

---

## 10. Příprava pro další vývoj

1. Nastudovat `docs/requirements/final/`, `docs/production_status.md`.
2. Přečíst `docs/overall_summary_status.md` a `docs/overall_summary_status_steps.md` – akt. backlog.
3. Zprovoznit lokální prostředí: PHP (s Redisem), Node/Vite, Python 3.x + balíčky.
4. Spustit `php artisan migrate`, `npm run build`, `./run_daemons.sh`.
5. Otestovat klíčové flows (živé vysílání, JSVV poplach, Control Tab s PTY).
6. Před nasazením na HW:
   - Zajistit sériová rozhraní k RF modulu, Control Tab panelu, GSM modemu.
   - Spustit `make test-scripts` (po doplnění).
   - Dokumentovat výsledky, aktualizovat `docs/app.md` dle zkušeností.

---

## 11. Reference a další zdroje

- `docs/runbook.txt`, `docs/runbook_status.md` – implementační kroky.
- `docs/overall_summary.txt` – kompletní požadavky.
- `docs/production_status.txt`, `docs/production_status.md` – checklisty a pokrytí.
- `docs/requirements/` – technické specifikace (JSVV, Control Tab, DTRX).
- `docs/navody/` – uživatelské návody a manuály.
- `docs/daemons.md` (pokud existuje) – popis daemons.
- `README.md` – quick-start info.

Tento dokument by měl sloužit jako startovní příručka pro nové členy týmu i jako validační checklist před předáním systému zákazníkovi. V případě dotazů nebo doplnění doporučujeme zapisovat změny do tohoto dokumentu a souvisejících status souborů.

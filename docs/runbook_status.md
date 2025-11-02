# Runbook Implementation Status

This report cross-checks the repository against the runbook (steps 1–12), the bonus serial-port contract, and the related documentation under `docs/requirements/`. Each section lists the evidence in the codebase and highlights residual manual checks or discrepancies.

## Krok 1 — Živé vysílání (ALSA okamžité přemapování vstupů)
- ✅ `MixerService::selectInput()` restores stored ALSA profiles and falls back to dynamic `amixer` commands, logging every action to the dedicated `mixer` channel (`app/Services/Audio/MixerService.php:63`, `config/logging.php:33`).
- ✅ API endpoints exist for source selection and live control (`routes/api.php:38`, `app/Http/Controllers/Api/LiveAudioController.php:18`).
- ✅ UI exposes the input picker, source radio options, and live controls (`resources/js/views/live-broadcast/LiveBroadcast.vue:1` with helpers in `resources/js/services/LiveBroadcastService.js:1`).
- ⚠️ Poznámka: Runbook odkazuje na `BROADCAST_*` env proměnné; konfigurace byla sjednocena na `AUDIO_*` (viz krok 11). Dokumentaci v případě potřeby aktualizujte na nové názvy.

## Krok 2 — ALSA profily a instalační skript
- ✅ Profily lze exportovat skriptem `scripts/alsa/install_profiles.sh` (uloží mic/file/fm) a `MixerService` je automaticky načítá (`scripts/alsa/install_profiles.sh:1`, `app/Services/Audio/MixerService.php:205`).
- ✅ Logování všech ALSA operací do `storage/logs/mixer.log` je aktivní (`app/Services/Audio/MixerService.php:379`).

## Krok 3 — Záznam vysílání
- ✅ `MixerService::startCapture()`/`stopCapture()` spouští `arecord`, sledují PID, ukládají wav do `storage/app/public/recordings` a zapisují metadata (`app/Services/Audio/MixerService.php:183`).
- ✅ REST API `/api/recording/start|stop` vrací detaily záznamu (`app/Http/Controllers/Api/RecordingController.php:14`, `routes/api.php:62`).
- ✅ Eloquent model a migrace existují (`app/Models/Recording.php:8`, `database/migrations/2025_10_31_080000_create_recordings_table.php:8`).

## Krok 4 — Plán vysílání (queue)
- ✅ Job `StartPlannedBroadcast` načte plán, přepne vstup, přehraje playlist a resetuje nastavení (`app/Jobs/StartPlannedBroadcast.php:21`).
- ✅ Konfigurace vstupů/queue čte `config/broadcast.php:193` (navázáno na `AUDIO_SCHEDULE_*` proměnné).
- ⚠️ Deployment musí zajistit běžící queue worker (viz supervisor `deploy/supervisor/queue.conf:1`); automatický check v kódu není možný.

## Krok 5 — JSVV z UI
- ✅ API `/api/jsvv/command` podporuje `STOP` i ad-hoc sekvence včetně validací (`app/Http/Controllers/Api/JsvvCommandController.php:17`).
- ✅ `JsvvSequenceService` zvládá plánování, trigger, stop všech sekvencí, včetně priority s RF bus (`app/Services/JsvvSequenceService.php:269`).
- ✅ UI komponenta `resources/js/views/jsvv/Jsvv.vue:1` nabízí STOP tlačítko, custom builder a FM doplňky.

## Krok 6 — RF Service + Modbus / RS‑485
- ✅ `RfBus` implementuje všechny požadované metody, priority a cache-locky (`app/Services/RF/RfBus.php:94`).
- ✅ Drivery pro GPIO a RTS existují (`app/Services/RF/Driver/DriverRs485Gpio.php`, `DriverRs485Rts.php`).
- ✅ Konfigurace `config/rf.php:1` mapuje `MODBUS_*`, `RS485_*`, derivuje režim z env a definuje priority.

## Krok 7 — Artisan příkazy
- ✅ RF příkazy (`app/Console/Commands/Rf/*.php`) využívají `RfBus` a podporují parametry priority.
- ✅ Port helpers (`app/Console/Commands/Port/*.php`) nyní zamykají porty a spadají pod bonus kontrakt.
- ✅ Další příkazy pro JSVV, GSM, Modbus atd. jsou přítomné (`app/Console/Commands/Jsvv/TestSendCommand.php:10`, `app/Console/Commands/Gsm/TestSendCommand.php:10`).

## Krok 8 — Python daemony a listenery
- ✅ Hlavní listenery se nachází v `python-client/daemons/` a přijímají `--port`, `--baudrate`, `--once`, `--timeout-ms` volby (např. `jsvv_listener.py:315`).
- ✅ Wrappery v `daemons/*.py` přesměrovávají na sdílené implementace.
- ✅ `run_daemons.sh` spouští všechny služby, načte `.env`, nastaví logy a PID (`run_daemons.sh:1`).

## Krok 9 — Priority a kolize
- ✅ `RfBus::pushRequest()` udržuje priority queue s per-request tokeny (`app/Services/RF/RfBus.php:200`).
- ✅ Python daemony přidávají `priority` do payloadů (např. `python-client/daemons/jsvv_listener.py:208`, `gsm_listener.py:133`).
- ✅ Konfigurace úrovní/aliasů je centralizovaná (`config/rf.php:43`).

## Krok 10 — PTY testy
- ✅ Helper `scripts/tests/_pty.sh` vytváří párové PTY (`scripts/tests/_pty.sh:1`).
- ✅ Všechny požadované scénáře jsou pokryty (`scripts/tests/jsvv_roundtrip.sh`, `control_tab_crc_and_events.sh`, `gsm_incoming_call_whitelist.sh`, `rf_tx_start_stop.sh`, `rf_read_buffers_lifo.sh`).
- ✅ `Makefile:test-scripts` spouští celou sadu (`Makefile:1`). Vyžaduje `socat`, `sqlite3`, `python3`.

## Krok 11 — Revize .env
- ✅ Nové kanonické proměnné jsou v `env-file.txt:1`, `.env` i `.env.example` používají `AUDIO_*` a zachovávají staré `BROADCAST_*` jako legacy.
- ✅ Kontrolní skript `scripts/env_sanity_check.sh` hlásí duplicity / kolize portů (`scripts/env_sanity_check.sh:1`).
- ⚠️ Dokumentace by měla reflektovat přejmenování env klíčů (README i `docs/production/audio-setup.md` již aktualizovány; runbook zatím odkazuje na staré názvy).

## Krok 12 — Supervisor & README
- ✅ Supervisor konfigurace pro všechny démony + queue worker jsou v `deploy/supervisor/*.conf`.
- ✅ README doplněn o RS‑485 režimy, použití `run_daemons.sh` a `make test-scripts` (`README.md:24`).
- ✅ `run_daemons.sh` zůstává hlavním runnerem pro lokální prostředí.

## Bonus — Kontrakt pro testovací porty
- ✅ Python listenery obalují přístup k sériovým portům pomocí `PortLock` (např. `python-client/daemons/jsvv_listener.py:403`, `control_tab_listener.py:558`, `gsm_listener.py:594`, `alarm_poller.py:96`).
- ✅ Artisan příkazy `port:send` a `port:expect` používají `flock` k exkluzivnímu přístupu (`app/Console/Commands/Port/SendCommand.php:23`, `Port/ExpectCommand.php:21`).

## Akceptační body / Otevřené otázky
- Živé přepnutí ALSA vstupů, záznam a priority logika jsou implementovány, ale hardware ověření (ALSA karta, Modbus rádio, GSM modem) vyžaduje nasazení na cílovém zařízení.
- Queue worker a Python daemony mají supervisor konfigurace; jejich běh je nutné po nasazení potvrdit (`supervisorctl status`).
- Testovací skripty simulují komunikaci bez HW; doporučeno spustit `make test-scripts` na cílovém serveru, aby se ověřila integrace CLI kontra Python démonů.

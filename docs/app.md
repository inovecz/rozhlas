# Technická dokumentace aplikace Rozhlas

## 1. Architektura a běhové prostředí

Aplikace kombinuje Laravel backend, frontend ve Vue 3 (Vite) a sadu Python daemonů, které zajišťují integraci se sériovými zařízeními (JSVV, Control Tab, GSM, GPIO). Orchestrace jednotlivých služeb probíhá skrze skripty `run.sh` (spouští PHP služby, fronty, Redis, Python watchery) a `run_daemons.sh` (Python démoni odděleně).

### Klíčové komponenty
- **Laravel**: business logika, REST API, queue worker (`php artisan queue:work`), CLI příkazy (`artisan`).
- **Vue + Vite**: SPA klient (`resources/js`), runtime buď přes `npm run dev` nebo produkční build (`public/build`).
- **Python-client**: démoni pro Modbus/JSVV, Control Tab, GSM, GPIO; běží v `.venv` s instalovanými balíčky (`python-client/requirements.txt`).
- **Databáze**: Laravel podporuje PostgreSQL/MySQL (podle `.env`), modely definovány v `app/Models`.
- **Fronta**: Laravel queue (např. Redis) pro prioritní úlohy (`activations-high`, `default`).
- **Systémové služby**: Redis (pokud `RUN_REDIS=true`), `python-control-channel` pro koordinaci s Modbus workerem.

### Procesní orchestrace
- `./run.sh`:
  - Načte `.env`, vytvoří log adresáře (`storage/logs/run`).
  - Ověří volné porty (Laravel, Vite), nabídne kill konfliktů (`--force`).
  - Spustí Vite (pokud `APP_ENV != production`), Laravel `artisan serve`, Python démony, queue worker, Redis (pokud `RUN_REDIS=true`), další watchers (alarms, two-way monitor, atd.).
  - Vytváří virtuální seriové porty přes `socat`, pokud nejsou nastaveny reálné (`GSM_SERIAL_PORT`, `CONTROL_TAB_SERIAL_PORT`).
  - Správa PID souborů v `storage/logs/run`.

- `run_daemons.sh start|stop|status`:
  - Spouští Python démony (`python-client/daemons`) – `control_channel_worker`, `gsm_listener`, `control_tab_listener`, `jsvv_listener`, `gpio_button_listener`.
  - Před spuštěním Control Tab listeneru kontroluje dostupnost serial portu (`ensure_serial_port_access`).
  - Exportuje `PYTHONPATH`, respektuje `.env`.

## 2. Backend – Laravel

### Broadcast / Stream
- **`App\Services\StreamOrchestrator`**:
  - Metoda `start(array $payload)` – zpracování požadavku na vysílání (manuální route, volba zón, nestů, playlistu).
  - Řeší priority (JSVV P1/P2), modbus lock (`withModbusLock`), interakci s Python klientem (`App\Libraries\PythonClient`).
  - Aktivuje mixér (`MixerController`) a audio presety, spouští playlisty, monitoruje session (`BroadcastSession`).
  - `stop($reason)` – ukončení běžícího vysílání, modbus unlock, volání `PythonClient->stopStream()`.
  - Integrace s Control Channel (`CoordinateControlChannel` listener) – P1 STOP → `stop_modbus`, P2 → `pause_modbus`.

### JSVV integrace
- **`/api/jsvv/events` → `App\Services\JsvvListenerService`**:
  - Přijímá HTTP payload (parser nebo testy).
  - `JsvvMessageService::ingest()` – validace (Laravel validator), deduplikace (cache store), ukládání do `jsvv_messages`, logování událostí (`jsvv_events`).
  - Firing `event(new JsvvMessageReceived($message, $duplicate))` → posluchači:
    - `CoordinateControlChannel` – modbus preempce.
    - `HandleJsvvFaultNotifications` – notifikace SMS/e-mail při alarmových stavech.
  - Mapování příkazů (`SIREN_SIGNAL`, `VERBAL_INFO`, `PLAY_SEQUENCE`, `STOP`) na sekvence (sloty) a volání `JsvvSequenceService::plan()` + `trigger()`.
  - STOP příkaz → `StreamOrchestrator->stop('jsvv_stop_command')`.

### Modbus / Alarm buffer
- **`php artisan alarms:monitor` → `App\Console\Commands\MonitorAlarmBuffer`**:
  - Běží periodicky (`--interval`), získává lock (`Cache::lock('modbus:serial')`).
  - `PythonClient->readAlarmBuffer()` → rozhodování podle `success`.
  - `App\Services\Modbus\AlarmDecoder` – dekódování registrů 0x3002–0x3009 na strukturované alarmy.
  - Logování (`Log::info`), záznam telemetry (`StreamTelemetryEntry` s typem `alarm_event`), SMS (GoSMS) a e-mail (templates v `JsvvSettings`), placeholders (`{alarm}`, `{voltage}` atd.).

### Control Tab
- Serial listener (Python) posílá events na `/api/control-tab/events`.
- **Controller** – validace `type ∈ {panel_loaded, button_pressed, text_field_request}`, mapuje camelCase → snake_case.
- **`App\Services\ControlTabService`**:
  - Vyhledá konfigurovanou akci (`config/control_tab.php`).
  - JSVV alarmy – automatická mapování podle tabulky `jsvv_alarms` (`JsvvSeeder`).
  - `trigger_jsvv_alarm`, `select_jsvv_alarm`, `start_or_trigger_selected_jsvv_alarm`, `stop_stream`, `lock_panel`, atd.
  - Text fields (`status_summary`, `running_duration`…) → generování stringů pro panel.
  - Cache (`control_tab:selected_jsvv_alarm`) – volba alarmu před stiskem START.
  - Vytváří kontext pro `StreamOrchestrator` (`source`, `route`, `locations`, `options`).

### Audio a směrování
- **`config/audio.php`** – definice vstupů/výstupů (ALSA controls), presety (`microphone`, `system_audio`).
- **`AudioIoService`** – enumerace vstupů, nastavení mixeru (`amixer`), fallback device (když routing deaktivován).
- **`AudioRoutingService`** – mapování `audioInputId`/`audioOutputId` na příkazy (PulseAudio, custom).
- **`MixerController`** – spouštění `artisan audio:preset`, `audio:input`, `audio:output` na základě configu (`config/broadcast.php`).
- `.env` flagy `BROADCAST_AUDIO_ROUTING_ENABLED`, `BROADCAST_MIXER_ENABLED` ovlivňují front-end i back-end logiku.

### Frontend API endpoints
- `/api/live-broadcast/*` – start/stop/status, playlist, audio devices.
- `/api/audio/*` – status vstupů/výstupů/hlasitosť.
- `/api/jsvv/*` – plánování/trigger sekvencí, assets.
- `/api/control-tab/events` – Control Tab handshake.
- `/api/manual-control/events`, `/api/fm/frequency`, `/api/stream/telemetry` – doplňkové služby.

### Telemetrie a logování
- `StreamTelemetryEntry` (DB) – ukládání event payloadů (JSVV, Control Tab, alarms).
- Laravel logy: `storage/logs/laravel.log`, plus specifické run logy (`storage/logs/run/*.log`).
- Python logy: `storage/logs/daemons/*.log` (každý proces samostatně).

## 3. Python démoni

### Struktura
- Zdroj v `python-client`:
  - `daemons/control_channel_worker.py` – UniX socket pro `pause_modbus` / `resume` / `stop`, handshake s parserem.
  - `daemons/control_tab_listener.py` – čtení seriového portu, protokol dle spec, HTTP POST na backend (token).
  - `daemons/jsvv_listener.py` – JSVV Modbus worker (preferuje serial, fallback HTTP).
  - `daemons/gsm_listener.py`, `daemons/gpio_button_listener.py` – GSM a GPIO integrace.
  - `scripts`, `simulators` – smoke testy, CLI utility.
  - `tests` – `test_scheduler.py` (priority queue), `test_gpio_button_listener.py` (mock backend).

### Virtualenv
- `.venv` – Python 3.13 (v repo), instalace `pip install -r python-client/requirements.txt`.
- `run_daemons.sh` nastavuje `PYTHONNOUSERSITE=1`, aby se ignorovala uživatelská site-packages (`charset_normalizer` konflikt).

### Control Channel
- Bidirectional IPC: backend posílá JSON příkazy (UUID, command, reason, message_id).
- Parser odpovídá `ok`, `state` (FSM: IDLE/TRANSMITTING/PAUSED/STOPPED), detail.
- Timeout, retry, fallback (viz `docs/requirements_docs/final/jsvv/05_integrace_modbus.md`).

### GPIO tlačítko
- Debounce, cooldown, require-release (`ButtonConfig`).
- Webhook = Control Tab endpoint, token volitelný.
- Testy simulují gpiod/gpioget backend (DummyReader).

### Permission check
- `run_daemons.sh` → `ensure_serial_port_access`: existence, RW oprávnění, odkaz na dokument `docs/setup/control_tab_permissions.md`.
- Zamezí spuštění listeneru bez přístupu na `/dev/tty.usbserial-110`.

## 4. Frontend (Vue 3)

### Klíčové moduly
- `resources/js/views/live-broadcast/LiveBroadcast.vue`
  - Řídicí panel živého vysílání (volba zdrojů, playlist, zóny, nesty).
  - Computed `routingEnabled = mixerStatus.value?.enabled !== false` → volba audio vstupů/výstupů.
  - Watchery: vyrovnávají stav s backendem, brání update během `syncingForm`, `liveUpdateInProgress`.
  - `buildStartPayload()` – generuje `options` s `audioInputId`/`audioOutputId`, fallback (`default`) pokud routing disabled.
  - Spotřebovává služby (`LiveBroadcastService`, `AudioService`, `VolumeService`), toasty pro notifikace.

- `resources/js/services/*.js` – abstrakce API (HTTP klient je Axios – `resources/js/services/http.js`).
- `resources/js/components/layouts` – layouty (Auth/Default), nav menu, systémový stav.

### Build & dev
- `npm install`, `npm run dev` – Vite server (port 5173 default).
- `npm run build` – produkční assets (`public/build/manifest.json`).
- Node >= 18, NPM >= 8.

### Komunikace s backendem
- REST JSON, tokeny (např. Control Tab) se přidávají v requestech.
- Websockety nepoužíváme; watchers pollují přes HTTP.

## 5. Konfigurace (.env)

### Základní proměnné
- `APP_ENV`, `APP_DEBUG`, `APP_URL` – standard Laravel.
- **Ports**: `LARAVEL_PORT` (default 8001), `VITE_PORT` (5173).
- **Audio**: `BROADCAST_AUDIO_ROUTING_ENABLED=true`, `BROADCAST_MIXER_ENABLED=true`.
- **JSVV**: `JSVV_ENABLED=true`, `JSVV_SEQUENCE_MODE=remote_trigger`, `JSVV_PORT=serial:/tmp/jsvv.sock` (socat nebo fyzický port), `CONTROL_CHANNEL_ENDPOINT=unix://storage/run/jsvv-control.sock`.
- **Control Tab**: `CONTROL_TAB_ENABLED=true`, `CONTROL_TAB_SERIAL_PORT=/dev/tty.usbserial-110`, `CONTROL_TAB_TOKEN=...`, `CONTROL_TAB_DEFAULT_LOCATION_GROUP_ID`.
- **GPIO button**: `GPIO_BUTTON_ENABLED=true`, `GPIO_BUTTON_CHIP=gpiochip2`, `GPIO_BUTTON_LINE=0`, `GPIO_BUTTON_REQUIRE_RELEASE=true`, `GPIO_BUTTON_WEBHOOK`.
- **GSM/Modbus**: `GSM_ENABLED`, `GSM_SERIAL_PORT`, `MODBUS_PORT`.
- **SMS**: `SMS_GOSMS_CLIENT_ID`, `SMS_GOSMS_CLIENT_SECRET`, `SMS_GOSMS_CHANNEL`, `SMS_GOSMS_SENDER`.
- V `docs/testing/test_env_checklist.md` je seznam povinných hodnot pro testování.

### Serial port oprávnění
- Viz `docs/setup/control_tab_permissions.md` – macOS skupiny `_uucp`, `_serial`; alternativně `chmod`, ale resetuje se.

## 6. Testování

### PHP (Laravel)
- `vendor/bin/phpunit` – test suite:
  - `tests/Feature/ControlTabApiTest.php` – panel_loaded, text_field_request, button press, JSVV siréna.
  - `tests/Feature/Jsvv/JsvvWorkflowTest.php` – HTTP JSVV eventy (queue, STOP).
  - `tests/Feature/AlarmMonitorTest.php` – simulace čtení alarm bufferu, telemetry, notifikace.
  - `tests/Unit/AlarmDecoderTest.php` – dekódování alarmů.
- Problém s dyld (libicu) řešit `brew reinstall php` nebo přidat `DYLD_LIBRARY_PATH`.

### Python
- `python -m pytest python-client/tests` – obsahuje scheduler a GPIO testy.
- Virtuální prostředí `.venv` musí být aktivní (`source .venv/bin/activate`).

### E2E scénáře
Založeno na testech výše + ruční test:
- Spuštění `run.sh`, ověření logů (Control Tab, Alarm monitor).
- Simulace JSVV requestu (`http post /api/jsvv/events`).
- Simulace alarmu (mock `PythonClient->readAlarmBuffer`).
- Control Tab (serial → button press) – nutné reálné zařízení nebo testový script, front-end reaguje (text fields).
- GPIO tlačítko – `python-client/daemons/gpio_button_listener.py` nasimulovat (DummyReader) + watchers.

## 7. Rozšíření a údržba

### Nové alarmy
- Přidat pravidla do `config/modbus_alarms.php` (conditions, metrics, message tokens).
- Rozšířit testy (`AlarmDecoderTest`, `AlarmMonitorTest`).

### Control Tab
- `config/control_tab.php` – mapování tlačítek, textových polí; `ControlTabService` přidat nové akce (nezapomenout test).
- Serial listener – token, port, permise (dokumentace).

### JSVV příkazy
- Úprava `JsvvListenerService::buildSequenceItemsFromCommand()` pro nové typy.
- V `docs/requirements_docs/final/jsvv/06_referencni_tabulky.md` držet tabulky aktuální.
- Seeder (`JsvvSeeder`) a `config/jsvv.php` pro defaulty.

### Audio/mixer integrace
- `config/audio.php`, `config/broadcast.php` – přidat vstupy/výstupy, path mapy, prahové hodnoty.
- Frontend – upravit `LiveBroadcast.vue` (souhlas se `sourceToMixerInputMap`).

### Logování a telemetry
- Používat `StreamTelemetryEntry` pro nové eventy (snadné sledování v UI / dashboardu).
- Laravel `Log::info/warning/error` → logrotate řeší `storage/logs`.

### Dokumentace
- `docs/app.md` – udržovat aktuální.
- `docs/setup/control_tab_permissions.md`, `docs/testing/test_env_checklist.md`.
- Oficiální požadavky v `docs/requirements_docs/final` (RTF/MD).

### Deployment
- Připravit systemd nebo supervisord skript, který spouští `run.sh` a monitoruje procesy.
- Prozradit `NODE_ENV`, `APP_ENV`, `queue` config; watchers (Redis, Python).
- Backups DB, `.env` versioning.

## 8. Referenční soubory a složky

| Cesta | Popis |
|-------|-------|
| `app/Services/StreamOrchestrator.php` | Orchestrace vysílání, interakce s Python klientem. |
| `app/Services/JsvvListenerService.php` | HTTP příjem JSVV, dispatch sekvencí. |
| `app/Services/Modbus/AlarmDecoder.php` | Dekódování alarm registrů. |
| `app/Console/Commands/MonitorAlarmBuffer.php` | Polling alarm bufferu, notifikace. |
| `config/control_tab.php` | Mapování tlačítek a textových polí Control Tabu. |
| `config/audio.php`, `config/broadcast.php` | Audio směrování, presety, routing. |
| `python-client/daemons/*` | Python démoni (Modbus, Control Tab, GPIO, GSM). |
| `resources/js/views/live-broadcast/LiveBroadcast.vue` | Frontend panel živého vysílání. |
| `docs/setup/control_tab_permissions.md` | Návod pro oprávnění Control Tab portu. |
| `docs/testing/test_env_checklist.md` | Co nastavit před testováním. |
| `docs/requirements_docs/final/*` | Oficiální specifikace JSVV, Modbus, test pokyny. |

## 9. Spuštění a řešení problémů

1. Zkontrolovat `.env` (viz checklist).
2. `composer install`, `npm install`, `python -m venv .venv && .venv/bin/pip install -r python-client/requirements.txt`.
3. `./run.sh --force` (killne porty 8001, 5173 pokud blokované).
4. Sledujte logy (`tail -f storage/logs/run/*.log`).
5. Jestli Control Tab listener hlásí „Operation not permitted“, upravte oprávnění (dokument).
6. Při `dyld` chybě (libicu) → `brew reinstall php` nebo nastavit `DYLD_LIBRARY_PATH`.
7. PHPUnit / pytest spouštět manuálně (CI integrace).

## 10. Další kroky

- Vytvořit systémové služby (systemd) pro `run.sh`.
- Přidat CI pipeline (GitHub Actions/GitLab) – composer/npm/pytest/phpunit, linting.
- Vybudovat monitoring (Grafana/Prometheus) pro logy a telemetry.
- Rozšířit testy o plnou simulaci JSVV Modbus přes `simulators/system_smoke_test.py`.
- Udržovat dokumentaci v tomto souboru (`docs/app.md`) – každá podstatná změna architektury musí být popsaná.

## 11. Požadavky, rozhraní a specifikace subsystémů

### 11.1 Control Tab (ovládací panel)

- **Hardware**: Externí panel s dotykovým displejem a fyzickými tlačítky (viz „Protokol pro komunikaci za pomoci Control Tabu.md“).
- **Sériová komunikace**: konfigurovatelný port v `.env` (`CONTROL_TAB_SERIAL_PORT`, default `/dev/tty.usbserial-110`), parametry 115200/8/N/1, timeouty definované v `.env`.
- **Protokol**:
  - Panel posílá rámce `<<<:S:P:T=PAYLOAD>>CRC<<<` (screen/panel/eventType/payload).
  - Python démon `control_tab_listener.py` validuje CRC, transformuje na JSON, doplní `sessionId`.
  - HTTP POST na `CONTROL_TAB_WEBHOOK` (default `http://127.0.0.1/api/control-tab/events`) s Bearer tokenem (`CONTROL_TAB_TOKEN`).
- **Backend**:
  - `ControlTabController` → `ControlTabService`.
  - Typy událostí: `panel_loaded`, `button_pressed`, `text_field_request`.
  - Textová pole (`control_tab.text_fields`) – mapování fieldId → callback (`status_summary`, `running_duration`…).
  - Tlačítka (`config/control_tab.php`): action `trigger_jsvv_alarm`, `select_jsvv_alarm`, `start_or_trigger_selected_jsvv_alarm`, `stop_stream`, atd.
  - Automatická vazba na `jsvv_alarms` (podle `button`).
- **Požadavky / požadavky testovacího protokolu**:
  - Zkouška sirén – tlačítko `2` (Zkouška sirén) -> JSVV sekvence.
  - Panel musí zobrazovat stav (text field `status_summary`, `running_duration`).
  - FE/BE reaguje i při zámku panelu (`lock_panel`).
  - Token nutné validovat (aktuálně není middleware – nutno doplnit).

### 11.2 GSM

- **Účel**: Monitoring/ovládání přes GSM modul (viz „Požadavky… zařízení JSVV“, kapitola 5.6.12/5.6.13).
- **Démon**: `python-client/daemons/gsm_listener.py` – neaktivní v default `.env` (nutno nastavit `GSM_ENABLED`, `GSM_SERIAL_PORT`).
- **Funkce**:
  - Přijímá AT příkazy / volání; odesílá eventy na `GSM_WEBHOOK` (`/api/gsm/events`).
  - Ověření PIN (`/api/gsm/pin/verify`), whitelist čísel (`/api/gsm/whitelist`).
- **Požadavky**:
  - Zajištění fallback komunikace JSVV přes GSM (přesné specifikace v „Požadavky_na_zarizeni…“).
  - Telefonní operátor Vodafone (doporučení v pokynech).
  - V test scénáři – moci příjmout/pustit audio, logovat volání.
- **Nastavení `.env`**:
  - `GSM_ENABLED=true`, `GSM_SERIAL_PORT=/dev/ttyUSB2` (např.), `GSM_WEBHOOK`.
  - Volitelně autop odpověď (`GSM_AUTO_ANSWER_DELAY_MS`).

### 11.3 JSVV (Jednotný systém varování a vyrozumění)

- **Specifikace**: viz `docs/requirements_docs/final/jsvv/*` (tabulky, integrace Modbus, priority).
- **Komunikace**:
  - Parser (Python) přijímá zprávy ze seriové linky/Modbus; fallback HTTP endpoint (`/api/jsvv/events`).
  - Zprávy obsahují ID sítě, VyC, adresu KPPS, typ příkazu, prioritu, timestamp.
  - Příkazy P1 (STOP/RESET) – okamžitá preempce, P2 (sirény) – blokující, P3 – standardní.
  - Modbus worker (Python) realizuje zápisy/čtení registrů, respektuje `pause_modbus`/`resume`/`stop`.
- **Backend**:
  - `JsvvMessageService` – validace, dedup, uložení.
  - `JsvvListenerService` – mapování na sekvence `JsvvSequenceService`.
  - `StreamOrchestrator` – spustí audio/hlas, `PythonClient` – volání `startStream`, `stopStream`.
  - Kontrolní kanál (`control_channel_worker.py`) – FSM (IDLE/TRANSMITTING/PAUSED/STOPPED).
  - `JsvvSettings` – SMS/email kontakty (fallback) při alarmech.
- **Požadavky**:
  - Zahájení aktivace ≤ 3 s, akustika ≤ 10 s.
  - P1 STOP do 200 ms.
  - Prioritní queue `activations-high`.
  - Obsluha DUPLICATE (cache TTL default 600 s).

### 11.4 Alarmy (Modbus buffer 0x3000–0x3009)

- **Shromáždění**:
  - Zprávy ze vzdálených hnízd – LIFO buffer (auto zasílání 3×).
  - Struktura: `nest_address`, `repeat`, `data[0..7]` – interpretace dle „DTRX PŘIJÍMAČ… Popis registrů v6“.
  - Příklad: podpětí baterie – `0x3002 status zdroje`, `0x3003 status baterie`, `0x3004 napětí`, `0x3005 proud`.
- **Dekódování**:
  - `config/modbus_alarms.php` – definice podmínek (např. slabá baterie < 11.5 V), scaling.
  - `AlarmDecoder` vrací `code`, `label`, `category`, `severity`, `metrics`.
- **Notifikace**:
  - SMS (GoSMS) – `SMS_GOSMS_*`, test message can use placeholders `{nest}`, `{repeat}`, `{voltage}`.
  - E-mail – `JsvvSettings->allowEmail`, `emailContacts`.
  - Telemetrie – `StreamTelemetryEntry` (type `alarm_event`), payload `nest`, `repeat`, `alarm`.
- **Požadavky**:
  - Při testu MIMOŇ – reagovat na slabou baterii, zaprotokolovat, odeslat SMS.
  - Alarm buffer nulovat po čtení (LIFO) – Python worker to zajišťuje.

### 11.5 Fyzické tlačítko (GPIO) pro poplach

- **Účel**: Hardwarové tlačítko (např. pro sirénovou zkoušku).
- **Démon**: `python-client/daemons/gpio_button_listener.py`.
  - Čte `GPIO_BUTTON_CHIP`, `GPIO_BUTTON_LINE`, `GPIO_BUTTON_ACTIVE_LEVEL`.
  - Debounce (`GPIO_BUTTON_DEBOUNCE_MS`), cooldown, require-release.
  - Při stisku -> HTTP POST na Control Tab webhook (typ `button_pressed`, `button_id` definován v `.env`).
- **Konfigurace**:
  - `.env`: `GPIO_BUTTON_ENABLED=true`, `GPIO_BUTTON_ID` (typicky `99` pro test), `GPIO_BUTTON_SESSION_PREFIX`.
  - Backend reaguje stejně jako na Control Tab tlačítko (mapování v `config/control_tab.php`).
- **Požadavky**:
  - Tlačítko „Zkouška sirén“ (pokyny KPV) – mapovat na JSVV alarm (bude spouštět stejnou sekvenci).
  - Bezpečnost – require release, logování v `storage/logs/daemons/gpio_button_listener.log`.

### 11.6 FM rádio

- **Požadavky**:
  - Zajistit příjem FM pro kontrolu připojení rozhlasu (88.8 MHz, 102 MHz, 104.5 MHz).
  - Přístup k audio vstupu (jack 3.5 mm) pro externí zdroj.
- **Aplikace**:
  - `FmController` (`/api/fm/frequency`) – získání/aktualizace frekvence.
  - Konfigurace `.env`: `FM_FREQUENCY`, `FM_TOLERANCE`, atd. (pokud použito).
  - Vysílání přes `StreamOrchestrator` (`source` = `fm_radio`, `sourceToMixerInputMap` v front-endu).
  - Kontrola, že `BROADCAST_AUDIO_ROUTING_ENABLED=true`, aby se směrování provádělo správně.
- **Test**:
  - Přijímač FM naladěn, ověřit signál, pro testy stačí emulace (audio soubor).

---

Tato sekce navazuje na detaily ve složce `docs/requirements_docs/final`. Implementace by měla být průběžně porovnávána s oficiálními specifikacemi a výsledky laboratorních testů.

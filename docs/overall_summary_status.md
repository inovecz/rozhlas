# Overall Summary Status

Porovnání požadavků z `docs/overall_summary.txt` se stavem implementace (viz `docs/runbook_status.md`, `docs/production_status.md`) a aktuálním kódem. Legenda: ✅ hotovo, 🛠️ rozpracováno/částečně splněno, 🧪 čeká na test nebo HW ověření, ❌ chybí.

## Web & Broadcast Control
- ✅ Živé vysílání (výběr zdroje, route, hlasitost) odpovídá runbooku (`docs/runbook_status.md:5`) a UI logice ve `resources/js/views/live-broadcast/LiveBroadcast.vue:1`.
- ✅ Záznam hlášení (start/stop + metadata) pokrytý v `app/Services/Audio/MixerService.php:183` a `docs/runbook_status.md:15`.
- 🛠️ Přehrávání uloženého souboru probíhá přes playlist/stream orchestrátor, ale chybí explicitní `playFile()`/`stopFile()` helpery popsané v checklistu (`docs/production_status.md:23`), což může komplikovat scénáře očekávané v celkovém zadání.

## GSM Call-In Broadcast
- 🧪 Backend i daemon umí přijmout/ukončit hovory, drží whitelist a priority (`app/Services/GsmStreamService.php:26`, `python-client/daemons/gsm_listener.py:1`), ale vše je dosud neotestované na modemovém HW (`docs/production_status.md:40`).
- ❌ Specifikované DTMF workflow pro zadání PIN a přenos audio streamu z hovoru není implementováno – v daemonu ani službě není parsování DTMF rámců a mapování na mixážní vrstvu (`python-client/daemons/gsm_listener.py:1`), takže části požadavku „Telefonem spustit hlášení s PIN“ zůstávají nedokončené.

## Scheduler, Priority & Timeouty
- ✅ Plánovač úloh a priority fronty odpovídají runbooku (`docs/runbook_status.md:20`, `docs/runbook_status.md:45`) a `app/Services/RF/RfBus.php:200`.
- 🧪 Kolizní kontrola/cron závisí na reálném prostředí a dosud není e2e ověřena (`docs/production_status.md:20`).
- 🧪 Automatické ukončení živého vstupu je implementováno novým jobem (`app/Jobs/EnforceBroadcastTimeout.php:16`) a konfigurovatelné přes `config/broadcast.php:162`, ale chování v praxi vyžaduje validační běh (`docs/production_status.md:94`).

## JSVV & Messaging
- ✅ UI builder, ad-hoc sekvence, STOP / Test animace jsou dodané (`docs/runbook_status.md:25`, `config/control_tab.php:24`, `app/Services/ControlTabService.php:224`).
- 🛠️ E-mail/SMS notifikace existují jen částečně – alarm listener posílá SMS při FAULT (`app/Listeners/HandleJsvvFaultNotifications.php:41`), ale širší požadavky na automatické textové rozesílky z overall summary nebyly prokázány (v production status nejsou pokryté).
- 🛠️ Přepnutí na mikrofon po vyhlášení poplachu se opírá o ruční zásah v UI; není k dispozici samostatné tlačítko „hlas po tónu“, které summary explicitně zmiňuje.

## Control Tab & Panel
- 🛠️ CRC/ACK logika i animace TESTu fungují (`python-client/daemons/control_tab_listener.py:300`, `app/Services/ControlTabService.php:224`), nicméně kompletní mapování tlačítek z tabulky v souhrnu chybí – např. ID 19/20 pouze vrací textovou hlášku bez změny znělky/lokality (`config/control_tab.php:126`). Reálné ověření s panelem stále čeká (`docs/production_status.md:63`).
- 🛠️ Povel TEST je nyní blokován během aktivního vysílání (`app/Services/ControlTabService.php:224`), ale scénáře přicházející z KPPS požadavků (servisní UART, diagnostické hodnoty) nejsou napojené (`docs/production_status.md:65`).

## Mapy, Logy, Uživatelské role
- ✅ Základní UI komponenty pro mapu (`resources/js/views/map/Map.vue:1`), logy a správu uživatelů existují (`docs/production_status.md:22`).
- 🧪 Soulad s požadovanou dokumentací a monitoringem stavů ještě nebyl potvrzen – kontrola proti finálním specifikacím je otevřená (`docs/production_status.md:22`, `docs/production_status.md:60`).
- ❌ Dedikovaný logovací kanál pro CLI utilitu, zmíněný v souhrnu, chybí (`docs/production_status.md:28`).

## Diagnostika & KPPS
- 🧪 Nové Modbus diagnostiky pro kabinet/baterii naplňují část požadavků (`app/Services/DeviceDiagnosticsService.php:16`) a jsou viditelné v UI (`resources/js/views/status/SystemStatus.vue:1`), ale závisí na skutečných senzorech (`docs/production_status.md:93`).
- ❌ Servisní rozhraní KPPS, autodiagnostické odpovědi a SLA reakční doby nejsou implementovány (`docs/production_status.md:65`, `docs/production_status.md:66`, `docs/production_status.md:82`, `docs/production_status.md:84`).
- ❌ KPPS monitoring/export stavů požadovaný v souhrnu stále chybí (`docs/production_status.md:84`).

## DevOps & Testy
- ✅ PTY integrační testy a helper skripty jsou k dispozici (`docs/runbook_status.md:50`).
- ❌ Chybí agregovaný CI skript `scripts/ci/run_full_validation.sh` uvedený v souhrnu (`docs/production_status.md:44`).
- ❌ Repo stále postrádá `CHANGELOG.md` a část předávací dokumentace (`docs/production_status.md:50`, `docs/production_status.md:78`).
- 🛠️ RF log kanál, reset utility a HW závislé testy vyžadují dovyřešení před předáním (`docs/production_status.md:25`, `docs/production_status.md:75`).

## Shrnutí neuzavřených priorit
1. Dokončit GSM telefonní workflow (DTMF PIN, audio bridge, reálný modem test).
2. Dodat servisní a diagnostické funkce KPPS (servisní UART, autodiagnostika, SLA reporting).
3. Doplnit provozní tooling (CLI log kanál, CI validační skript, changelog/předávací dokumentaci).
4. Rozšířit Control Tab mapování a ověřit scénáře s HW (tlačítka 19/20, retransmise, TEST z KPPS).

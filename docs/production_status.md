# Production Readiness Summary

Tento dokument porovnává repozitář se dvěma kontrolními seznamy, které jsou vedené v `docs/production_status_check.txt` a `docs/production_status.txt`. Hodnocení je orientační – položky označené ⚠️ nebo ❌ vyžadují buď manuální ověření na reálném hardware, nebo doplnění implementace/dokumentace.

---

## Checklist: `production_status_check.txt`

| Sekce | Požadavek | Stav | Poznámka |
| --- | --- | --- | --- |
| **1.1** | `.env` odpovídá `env-file.txt`, bez duplicit/kolizí | ✅ Nové proměnné `AUDIO_*`, validace přes `scripts/env_sanity_check.sh` |
| **1.1** | `.env` obsahuje všechny požadované `BROADCAST_*`, CONTROL_TAB, GSM, MODBUS, RS485, QUEUE, JSVV | ⚠️ Repozitář používá `AUDIO_*` aliasy; legacy `BROADCAST_*` zůstávají jen jako fallback |
| **1.1** | `database.sqlite` odpovídá schema dumpu | ⚠️ Migrace existují (`database/migrations/**`), dump není součástí repozitáře – nutné ověření mimo repo |
| **1.1** | Struktura adresářů (Services, scripts, daemons, deploy) | ✅ Všechny složky existují |
| **1.1** | Laravel aplikace spustitelná (`php artisan serve`) | ⚠️ Předpokládá funkční PHP prostředí – perlu ověřit na cílovém stroji |
| **1.1** | `composer install` bez varování | ⚠️ Nutno spustit v cílovém prostředí |
| **1.1** | `scripts/env_sanity_check.sh` validuje `.env` | ✅ Skript je přítomen a běží |
| **2.1** | Živé vysílání – přepínání vstupů, spuštění/stop, logování | ⚠️ Frontend + backend (`LiveBroadcast.vue`, `MixerService::selectInput`) implementované; chování ALSA/ logy vyžadují test s HW |
| **2.2** | Záznamy – nahrávání, přehrávání, metadata, CRUD | ✅ Implementováno v `RecordList.vue`, `RecordingController`, `MixerService` |
| **2.3** | Plán vysílání – úlohy, opakování, kolize | ⚠️ `ScheduleTask.vue` + `StartPlannedBroadcast` pokrývají logiku, ale kolize/cron je nutné otestovat |
| **2.4** | JSVV rychlá tlačítka + STOP | ⚠️ UI a API existují, ale vazba na dokumentaci HZS vyžaduje integrační test |
| **2.5** | Protokoly a správa uživatelů podle dokumentace | ⚠️ Log view (`Log.vue`) a `UserList.vue` existují; je třeba prověřit proti aktuální dokumentaci |
| **3.1** | `MixerService` má metody `selectInput`, `playFile`, `stopFile`, `startCapture`, `stopCapture` | ❌ `playFile`/`stopFile` v servisní vrstvě nejsou; přehrávání řeší samostatné služby |
| **3.1** | `RfBus` metody + locky + driver GPIO/RTS | ✅ Implementováno (`app/Services/RF/**`) |
| **3.1** | Logy do `storage/logs/rf.log` a `mixer.log` | ⚠️ `mixer.log` existuje; dedikovaný RF kanál chybí (loguje se do defaultu) |
| **3.2** | Artisan příkazy (`rf:*`, `jsvv:handle`, `alarm:poll`…) | ✅ Všechny příkazy dostupné |
| **3.2** | Testovací příkazy (`port:send/expect`, `jsvv:test-send` …) | ✅ Implementované + port locking |
| **3.2** | Logování do `storage/logs/cli.log` | ❌ Samostatný kanál pro CLI není definován |
| **3.3** | API `/api/live/*`, `/api/recording/*`, `/api/jsvv/command` | ✅ End-pointy existují (viz `routes/api.php`) |
| **3.3** | Response dle JSON API standardu + logování | ⚠️ Odezvy jsou projektem definované, ne striktně JSON API; logování probíhá částečně |
| **4.1** | Python daemony – soubory, argumenty, `--once` | ✅ `python-client/daemons/*.py` všechny požadavky splňují |
| **4.1** | Daemony otvírají port, čtou, volají Artisan, zavřou | ✅ `PortLock` + `try/finally` bloky |
| **4.1** | Chyby komunikace → log, daemon nespadne | ✅ Výjimky se logují, smyčka pokračuje |
| **4.2** | Logy python listenerů ve `storage/logs/daemons/` | ⚠️ `run_daemons.sh` používá tuto cestu, supervisor směruje do `/var/log`; sjednocení podle potřeby |
| **4.3** | Supervisor konfigurace + `run_daemons.sh` | ✅ `deploy/supervisor/*.conf`, skript existuje |
| **5.1** | RS-485 DE/RE ověřeno (GPIO16/RTS) | ⚠️ Podporováno drivery, vyžaduje HW test |
| **5.2** | DTRX registry (0x3000…, FC03/FC06) | ⚠️ Python client podporuje, ale vyžaduje ověření modbusem |
| **5.3** | Polling hnízd + pauza při JSVV | ⚠️ Priority implementované, funkci nutno potvrdit |
| **5.4** | Alarm listener ukládá, posílá SMS | ⚠️ Listener (`alarm_poller.py`) existuje; SMS integrace zatím není |
| **6** | GSM modul – whitelist, PIN, přehrávání | ⚠️ Logika (`GsmListener`, `GsmStreamService`) připravená, nutné testovat s modemem |
| **7** | Control Tab hardware | ⚠️ Listener + služby existují, ale vyžadují HW ověření |
| **8.1** | PTY testy (`_pty.sh` + skripty) | ⚠️ Skripty jsou, ale běh musí potvrdit provoz |
| **8.2** | Artisan test commands | ✅ `jsvv:test-send`, `gsm:test-send`, `ctab:test-send` |
| **8.3** | `scripts/ci/run_full_validation.sh` | ❌ Skript není v repozitáři |
| **9** | Logy mixer/rf/daemons/tests, ISO čas | ⚠️ Mixer/daemons/tests ano; chybí RF log dedicated |
| **10** | Stavové obrazovky (UI) | ⚠️ `SystemStatus.vue` existuje; obsah diagnostiky vyžaduje napojení na data |
| **11** | DB tabulky `recordings`, `alarms`, `broadcast_logs` | ⚠️ `recordings` je; `alarms`/`broadcast_logs` v repu nejsou |
| **12** | Instalace závislostí (`libgpiod`, `socat`, supervisor, …) | ⚠️ Závisí na nasazení, v repo nejsou skripty |
| **13** | Bezpečnost: retry, offline log, rotace | ⚠️ Supervisor restartuje procesy; log rotace a offline monitoring nutné doplnit |
| **14** | Dokumentace + `CHANGELOG.md` | ❌ `CHANGELOG.md` chybí, některé dokumenty je třeba aktualizovat |
| **15** | Celkové chování systému (auto-start, resilience) | ⚠️ Vyžaduje end-to-end test na ústředně |

## Checklist: `production_status.txt`

| Sekce | Kritéria | Stav | Poznámka |
| --- | --- | --- | --- |
| **A1** | Přímé hlášení – výběr zdroje, cíle, spuštění/ukončení | ⚠️ UI a backend existují, ale ALSA/route fungování nutno ověřit na HW |
| **A2** | Záznamy – pořízení, správa, náhled | ✅ Plně implementováno (`RecordList.vue`, `RecordingController`) |
| **A3** | Plán vysílání – úlohy, opakování, kolize | ⚠️ Funkce implementována, ale kolize/cron testy nejsou zdokumentovány |
| **A4** | Poplachy JSVV – konfigurovatelná tlačítka | ✅ `JSVVSettings.vue` + API pokrývají požadavky |
| **A5** | Protokoly a role | ⚠️ UI existuje, soulad s dokumentací je třeba potvrdit |
| **B6** | Control Tab protokol (CRC, retry) | ⚠️ `control_tab_listener.py` implementuje CRC/ACK, retransmise potřeba ověřit |
| **B7** | Mapování UI ↔ Control Tab eventy | ⚠️ `ControlTabService` překládá události, ale chybí test s reálným panelem |
| **C8** | KPPS RS-232 napojení | ⚠️ SW připraven, závisí na HW instalaci |
| **C9** | Servisní rozhraní KPPS | ❌ Podpora servisního UART není implementovaná |
| **C10** | Časování a priority JSVV | ⚠️ Priority zajištěny (`RfBus`), měření SLA nutné |
| **C11** | Nouzové STOP (SW/HW) | ✅ `/api/jsvv/command` + `JsvvSequenceService::stopAll`; HW tlačítko nutno napojit |
| **C12** | Autodiagnostika KPPS | ❌ Diagnostické odpovědi chybí |
| **D13** | Adresace a RF identita | ⚠️ Python client umí nastavit, ale není vystaveno v UI |
| **D14** | Režim Tx/Rx + kmitočet | ⚠️ `setFrequency` dostupný přes Python client, workflow chybí |
| **D15** | Zónování cílového vysílání | ⚠️ `StreamOrchestrator` pracuje s route/zones; mapování na RFDest registr nutno ověřit |
| **D16** | Start/Stop vysílání a přehrávání | ✅ `RfBus` implementuje Tx/Rx povely, CLI příkazy je volají |
| **D17** | Stavy/Chyby/Ogg bitrate | ⚠️ `readStatus()` získává data, prezentace v UI omezená |
| **D18** | Alarm buffer LIFO 0x3000–0x3009 | ⚠️ Logika `readBuffersLifo()` existuje; potřeba HW test |
| **D19** | Resety zařízení (0x0666/0x0667) | ⚠️ Možné přes `PythonClient::writeRegister`, ale chybí dedikovaný endpoint |
| **D20** | Identifikace FW/HW | ⚠️ Čtení registrů dostupné v Python clientu, UI/report chybí |
| **E21** | Příprava polygonu (termín, kontakty) | ❌ Organizační požadavek mimo repo |
| **E22** | Dokumentace pro předání | ❌ V repu není; nutné dodat externě |
| **E23** | Připojení KPPS, FM, audio vstupy | ⚠️ Závisí na fyzické instalaci |
| **E24** | Test STOP a délka příkazu TEST | ⚠️ SW STOP existuje, ale časování musí zkontrolovat HW tým |
| **E25** | Profil hlásičů a hlasitosti | ❌ HW vybavení/parametrize není součástí repo |
| **F26** | Reakční doby KP ≤3 s | ❌ Bez implementované diagnostiky |
| **F27** | Řazení příkazů a priority | ✅ `RfBus::pushRequest` + integrace v `StreamOrchestrator`/`JsvvSequenceService` |
| **F28** | Diagnostika a monitoring KPPS | ❌ Rozhraní pro KPPS diagnostiku chybí |

*Legenda:* ✅ implementováno, ⚠️ částečně implementováno / vyžaduje ověření, ❌ zatím chybí nebo mimo rozsah repozitáře.

## Dodatečné e-mailové požadavky

| Bod | Popis | Stav | Poznámka |
| --- | --- | --- | --- |
| **a** | Animace průběhu příkazu TEST na Control Tabu | ⚠️ Backend vrací pokyny pro animaci (`ControlTabService::buildTestControlTabPayload`), listener je vykreslí jako textovou progresi; je nutné ověřit vzhled na reálném panelu |
| **b** | Test VP (dvířka, baterie, reproduktor) a odeslání do KPPS | ⚠️ `DeviceDiagnosticsService` dekóduje Modbus status, ukládá do `device_health_metrics` a při poruše odesílá KPPS `FAULT`; nutné ověřit na reálných senzorech |
| **c** | Automatické ukončení audio vstupů po ~10 minutách | ⚠️ `EnforceBroadcastTimeout` plánuje stop po 9:55 min pro živé vstupy (konfigurovatelné v `broadcast.auto_timeout`); čeká na potvrzení chování front |
| **d** | Tlačítko „Zpět“ při výběru poplachu | ✅ Přidán navigační prvek v `Jsvv.vue` (`goBack()` s fallbackem na `LiveBroadcast`) |
| **e** | Povel TEST se vykoná jen v klidu, jinak se zahodí | ⚠️ `ControlTabService` blokuje TEST při aktivním vysílání; potřebné prověřit i scénáře z KPPS rámců |
| **f** | Stav napájení, baterie, VP na tabletu | ⚠️ Stránka `SystemStatus.vue` zobrazuje diagnostické metriky; zobrazení/refresh závisí na HW datech |

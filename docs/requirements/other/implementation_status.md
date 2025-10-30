# Implementační status – Sarah V (stav k 2025-10-30)

Tento dokument shrnuje, které části zadání z *requirements_docs* (JSVV specifikace, Control Tab, Modbus apod.) jsou již implementované a které je potřeba dopracovat. Slouží jako jednotný backlog pro vývoj.

---

## 1. JSVV – příjem a zpracování zpráv

| Funkce | Stav | Poznámka / další kroky |
| --- | --- | --- |
| Tabulky `jsvv_messages`, `jsvv_events`, `control_channel_commands` | ✅ Hotovo | Migrace `2025_10_30_130000_create_jsvv_message_tables.php` |
| Artisan příkaz `jsvv:process-message` + deduplikace | ✅ Hotovo | `app/Console/Commands/ProcessJsvvMessage.php`, fronta `activations-high` |
| Listener `JsvvListenerService`→`JsvvMessageService` | ✅ Hotovo | Zatím ingest přes API – budoucnu nahradit parserem |
| Audit eventů (`jsvv_events`) | ✅ Hotovo | Zaznamenává validaci, duplicity, další eventy přidat podle potřeby |
| Control Channel koordinátor (pauza/stop/resume) | ✅ Hotovo | IPC přes Unix socket (`ControlChannelService` + `control_channel_worker.py`), retry & audit eventy |
| SLA měření (čas začátku, akustika) | ⚠️ Částečně | `ControlChannelService` ukládá latency do `jsvv_events`; akustická metrika čeká na stream orchestrátor |

### Akční body
1. Doplňující posluchače eventů (`RouteActivationCommands`, `HandleStatusAndFaults`…) dle požadavků v `04_backend_laravel.md`.
2. Doplnit akustické SLA metriky (start/akustika) a export do telemetrie.

---

## 2. Python parser a démony

| Funkce | Stav | Poznámka / další kroky |
| --- | --- | --- |
| JSVV listener (`python-client/daemons/jsvv_listener.py`) | ✅ Hotovo | Priority queue + retry/backoff, logování dle kap. 2, volá Artisan přes STDIN |
| Parser zpráv (CRC, priority, log) | ✅ Hotovo | `ParserDaemon` loguje RECEIVED/QUEUED/FORWARDED/DONE, respektuje deduplikaci a priority |
| Volání Artisanu (single/batch) | ✅ Hotovo | `ArtisanInvoker` používá `php artisan jsvv:process-message`, retry + timeout |
| Control Channel worker | ✅ Hotovo | `control_channel_worker.py` obsluhuje Unix socket, FSM + Modbus polling (dry-run fallback) |

### Akční body
1. Vyladit watchdog & telemetry export z control channel workeru (např. stav registrů, pády).
2. Rozšířit logování o SLA metriky (latence parser→Artisan, heartbeat, CRC statistiky).

---

## 3. Modbus & živé vysílání

| Oblast | Stav | Poznámka |
| --- | --- | --- |
| StreamOrchestrator (JSVV) | ✅ Základ | JSVV sekvence (local_stream / remote_trigger) fungují |
| Přímé vysílání (mikrofon, vstupy) | ⚠️ Částečně | StreamOrchestrator napojen na control channel; chybí mixer UI a health checks |
| Playlisty/recordings | ✅ Hotovo (backend) | `ProcessRecordingPlaylist` spouští stream, přehrává nahrávky přes ffmpeg pipeline, telemetrie & cancel |
| GSM modul & whitelisty | ⚠️ Částečně | Backend daemon (SIM7600), whitelist, PIN & stream orchestrace hotová; chybí UI a monitoring |
| Manual control panel (vendor protokol) | ❌ Chybí | Zatím jen placeholder v TODO |

### Akční body
1. Dokončit `/api/live-broadcast/start|stop` UI + mixer health checks (backend hotovo).
2. Dokončit GSM frontend/monitoring (signál, PIN workflow) + hardware watchdog.
3. Rozšířit playlist o UI, logování do runbooku a archiv přehrávek.

---

## 4. Control Tab a další integrace

| Funkce | Stav | Poznámka |
| --- | --- | --- |
| Control Tab protokol (`docs/requirements_docs/Protokol…`) | ⚠️ Částečně | Python daemon + backend API připraveny; chybí UI a dlouhodobý monitoring |
| Propojení Control Tab → backend fronta | ⚠️ Částečně | StreamOrchestrator napojen; zbývá websocket/UI vrstva |
| Mapování lokací/zón (frontend mapa) | ⚠️ Základ | Zobrazení existuje, ale chybí plné naplnění dat z requirements (SLA, stavy hnízd) |
| Telemetrie & monitoring | ⚠️ Částečně | JSVV sekvence logujeme, ale chybí centralizované metriky (je nutné navázat na budoucí streaming) |

---

## 5. Dokumentace a testy

| Oblast | Stav | Poznámka |
| --- | --- | --- |
| Runbook `Spusteni_a_testovani.md` | ✅ Aktualizováno | Obsahuje nový scénář pro `jsvv:process-message` |
| Uživatelská dokumentace (`Sarah_V_User_Manual.md`) | ⚠️ Částečně | Popisuje JSVV moduly; chybí nové kapitoly pro přímé vysílání, GSM, Control Tab, až budou implementovány |
| Testovací plán (KPV Bártek) | ❌ Chybí implementace | Dokument v `requirements_docs` vyžaduje test cases – zatím bez pokrytí |
| Automatické testy (PHP/JS/python) | ⚠️ Částečně | Přidány unit testy pro `ControlChannelService` a `JsvvMessageService`; chybí integrační scénáře a Python coverage |

---

## 6. Shrnutí priorit

1. **Control Channel telemetrie** – dořešit watchdog, export metrik a akustickou SLA.  
2. **Completní streaming (mikrofon/playlist/GSM)** – klíčová funkcionalita původní aplikace.  
3. **Control Tab & API** – hardware panel je součástí zadání.  
4. **Testovací scénáře dle MV ČR (KPV)** – připravit test cases pro certifikaci.  
5. **Dokumentace** – průběžně doplňovat seznam splněných/nesplněných požadavků po implementaci (instalaci pokrývá `php artisan app:install`).

Tento dokument aktualizujte po každém větším milníku, aby bylo jasné, co je hotové a co zbývá dopracovat.

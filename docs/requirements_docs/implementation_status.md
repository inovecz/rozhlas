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
| Control Channel koordinátor (pauza/stop/resume) | ⚠️ Částečně | `ControlChannelService` zatím jen loguje (NOT_IMPLEMENTED). Nutné doplnit IPC + potvrzení dle kap. 5 (pause_modbus, stop_modbus…) |
| SLA měření (čas začátku, akustika) | ❌ Chybí | Po integraci control channelu doplnit metriky do `jsvv_events`/telemetrie |

### Akční body
1. Implementovat skutečnou komunikaci s parserem/control kanálem (Unix socket / ZeroMQ), včetně potvrzení, timeoutů, retry a stavového automatu.
2. Doplňující posluchače eventů (`RouteActivationCommands`, `HandleStatusAndFaults`…) dle požadavků v `04_backend_laravel.md`.
3. Přidat sekundární cache pro deduplikační klíče (Redis) – viz kap. 4.5.

---

## 2. Python parser a démony

| Funkce | Stav | Poznámka / další kroky |
| --- | --- | --- |
| JSVV listener (`python-client/daemons/jsvv_listener.py`) | ⚠️ Částečně | Přeposílá payload do API, ale neimplementuje priority frontu ani volání Artisanu |
| Parser zpráv (CRC, priority, log) | ❌ Chybí | Podle `02_python_parser.md` je nutné doplnit PriorityQueue, logování (RECEIVED/DECODED/DONE…), retry |
| Volání Artisanu (single/batch) | ❌ Chybí | V parseru zavolat `php artisan jsvv:process-message` s JSON daty |
| Control Channel worker | ❌ Chybí | Zpracovat příkazy `pause_modbus`/`stop_modbus` a zajišťovat potvrzení stavu FSM |

### Akční body
1. Vybudovat parser modul podle kapitoly 2/3 – nejlépe samostatný servis v `python-client/`.
2. Přidat logování podle SLA (čas příjmu, priorita, výsledky CRC).
3. Zajistit heartbeat/modbus worker (watchdog) dle kap. 5.

---

## 3. Modbus & živé vysílání

| Oblast | Stav | Poznámka |
| --- | --- | --- |
| StreamOrchestrator (JSVV) | ✅ Základ | JSVV sekvence (local_stream / remote_trigger) fungují |
| Přímé vysílání (mikrofon, vstupy) | ❌ Chybí | Viz `todo.md` → controller, orchestrator, mixer integrace |
| Playlisty/recordings | ❌ Chybí | Backend job `RecordingBroadcastJob`, UI playlist builder zatím neexistuje |
| GSM modul & whitelisty | ❌ Chybí | Viz `todo.md` sekce “Stream triggered from GSM module” |
| Manual control panel (vendor protokol) | ❌ Chybí | Zatím jen placeholder v TODO |

### Akční body
1. Implementovat `/api/live-broadcast/start|stop`, real-time status, protokol.
2. Napojit playlist mechanismus s ffmpeg pipeline.
3. Připravit GSM daemon + backend management UI.

---

## 4. Control Tab a další integrace

| Funkce | Stav | Poznámka |
| --- | --- | --- |
| Control Tab protokol (`docs/requirements_docs/Protokol…`) | ❌ Chybí | Potřeba vytvořit listener/dekóder, mapování tlačítek, odpovědi |
| Propojení Control Tab → backend fronta | ❌ Chybí | Inspirace v TODO sekci „Manual control panel“ |
| Mapování lokací/zón (frontend mapa) | ⚠️ Základ | Zobrazení existuje, ale chybí plné naplnění dat z requirements (SLA, stavy hnízd) |
| Telemetrie & monitoring | ⚠️ Částečně | JSVV sekvence logujeme, ale chybí centralizované metriky (je nutné navázat na budoucí streaming) |

---

## 5. Dokumentace a testy

| Oblast | Stav | Poznámka |
| --- | --- | --- |
| Runbook `Spusteni_a_testovani.md` | ✅ Aktualizováno | Obsahuje nový scénář pro `jsvv:process-message` |
| Uživatelská dokumentace (`Sarah_V_User_Manual.md`) | ⚠️ Částečně | Popisuje JSVV moduly; chybí nové kapitoly pro přímé vysílání, GSM, Control Tab, až budou implementovány |
| Testovací plán (KPV Bártek) | ❌ Chybí implementace | Dokument v `requirements_docs` vyžaduje test cases – zatím bez pokrytí |
| Automatické testy (PHP/JS/python) | ⚠️ Základ | Doplnit unit/integration testy pro `JsvvMessageService`, control channel, parser |

---

## 6. Shrnutí priorit

1. **Control Channel + Parser** – bez skutečné IPC integrace nelze garantovat SLA a preempci.  
2. **Completní streaming (mikrofon/playlist/GSM)** – klíčová funkcionalita původní aplikace.  
3. **Control Tab & API** – hardware panel je součástí zadání.  
4. **Testovací scénáře dle MV ČR (KPV)** – připravit test cases pro certifikaci.  
5. **Dokumentace** – průběžně doplňovat seznam splněných/nesplněných požadavků po implementaci (instalaci pokrývá `php artisan app:install`).

Tento dokument aktualizujte po každém větším milníku, aby bylo jasné, co je hotové a co zbývá dopracovat.

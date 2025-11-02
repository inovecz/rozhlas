# Overall Summary – Actionable Steps

Seznam kroků vycházející ze `docs/overall_summary.txt`, současného stavu (`docs/overall_summary_status.md`) a související dokumentace v `docs/requirements/`. Každý krok obsahuje krátký popis a odkazy na klíčové oblasti, které je nutné dokončit nebo ověřit.

1. **Dokončit GSM telefonní workflow**
   - Implementovat zpracování DTMF PIN (parser + validace) a propojit audio stream hovoru do mixážní vrstvy (`python-client/daemons/gsm_listener.py`, `app/Services/GsmStreamService.php`).
   - Ověřit automatické zvednutí hovoru, přerušení nižších priorit a návrat do pohotovosti dle požadavků v `docs/overall_summary.txt` (sekce „Automatické hlášení z telefonu“).
   - Provedení testů na reálném modemu (Vodafone SIM) a doplnění postupů do dokumentace nasazení.

2. **Servisní rozhraní a diagnostika KPPS**
   - Implementovat servisní UART rozhraní, autodiagnostické odpovědi a SLA reakční doby popsané v `docs/production_status.txt` (sekce C8–C12) a v požadavcích `docs/requirements/final/jsvv_requirements.txt`.
   - Napojit diagnostické hodnoty do backendu (rozšířit `DeviceDiagnosticsService`, API a UI) a zajistit export stavů pro KPPS.
   - Připravit testy/skripty, které ověří měření napájení, BZ, audio cesty a reakce do 3 s.

3. **Rozšířit Control Tab scénáře**
   - Doplnit mapování tlačítek 19/20 (volba znělky/lokality) a ostatních položek ze specifikace (`docs/overall_summary.txt` – „Control Tab – UART protokol“).
   - Simulovat retransmise, chybné CRC a běh TEST při aktivním vysílání podle scénářů v dokumentaci.
   - Uzavřít end-to-end test s reálným panelem (ověřit CRC/ACK, animace, priority).

4. **Upevnit JSVV workflow**
   - Dodat automatické rozesílky (SMS/e-mail) a tlačítko „hlas po tónu“ / přepnutí voice kanálu tak, jak je popsáno v overall summary.
   - Revize dokumentace k JSVV spojená s výsledky testů (např. `docs/requirements/final/jsvv`).

5. **Doplnit přehrávací helpery a CLI logování**
   - Přidat `MixerService::playFile()/stopFile()` nebo ekvivalentní servisní vrstvu pro jednoduché přehrávání, aby checklist (3.1) byl kompletní.
   - Nakonfigurovat samostatný logovací kanál pro CLI (`storage/logs/cli.log`) a aktualizovat logging config + dokumentaci.

6. **Dokumentace, tooling a CI**
   - Vytvořit `CHANGELOG.md`, doplnit předávací dokumentaci a organizační podklady (E21–E22).
   - Připravit skript `scripts/ci/run_full_validation.sh`, který spustí PTY testy, lint a sanity check dle produkčních požadavků.

7. **Hardware integrační a SLA testy**
   - Pro každý živý subsystém (ALSA, Modbus, GSM, Control Tab) spustit nasazení na cílovém HW, zaznamenat výsledky (`supervisorctl status`, `make test-scripts`).
   - Zvalidovat reakční doby (≤3 s), délky TEST signálu, priority STOP/JSVV/GSM a pořizovat logy pro audit.

8. **Follow-up na diagnostiku + monitoring**
   - Doladit RF logování (dedikovaný kanál), reset utility (0x0666/0x0667) a monitoring registrů 0x4036/0x4037 v UI.
   - Zpřístupnit identifikaci FW/HW a adresace RF v administraci, aby bylo možné plnit požadavky D13–D20 z `docs/production_status.txt`.

Tyto kroky uzavírají rozdíl mezi „požadováno“ a „realizováno“ a mohou sloužit jako implementační backlog v návaznosti na runbook a produkční checklisty.

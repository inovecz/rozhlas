# Testovací konfigurace `.env`

Před spuštěním akceptačních testů podle dokumentu **Pokyny_Testování KPV** ověřte následující klíčové položky v souboru `.env`:

| Proměnná | Hodnota | Důvod |
|----------|---------|-------|
| `BROADCAST_AUDIO_ROUTING_ENABLED` | `true` | Aktivuje směrování audio vstupů/výstupů tak, aby se spouštěly příslušné watchery. |
| `BROADCAST_MIXER_ENABLED` | `true` | Umožní aplikaci vysílat preset příkazy do mixéru během testů. |
| `JSVV_ENABLED` | `true` | Zapne veškeré workflow navázané na JSVV zprávy. |
| `JSVV_SEQUENCE_MODE` | `remote_trigger` | Odlehčí testům tím, že JSVV sekvence posílá přes Modbus místo lokálního přehrávání. |
| `JSVV_PORT` | `serial:/tmp/jsvv.sock` (nebo reálný port) | Zajistí, že Python listener naváže spojení i bez fyzického zařízení. |
| `CONTROL_TAB_ENABLED` | `true` | Umožňuje zpracování tlačítek na Control Tabu. |
| `CONTROL_TAB_SERIAL_PORT` | `/dev/tty.usbserial-110` (nebo odpovídající) | Cesta ke čtečce Control Tabu. |
| `CONTROL_TAB_TOKEN` | nenulová hodnota | Token, který musí posílat Control Tab listener. |
| `GPIO_BUTTON_ENABLED` | `true` | Aktivuje listener pro fyzické tlačítko sirénového testu. |
| `SMS_GOSMS_*` | vyplněno | Nutné pro odeslání SMS při alarmu slabé baterie. |

Pokud nasazení používá skutečný sériový port JSVV/KPPS, nahraďte `serial:/tmp/jsvv.sock` reálným rozhraním (např. `/dev/ttyUSB1`). V případě simulace je možné použít i `socat` nebo vlastní emulátor, jen zachovejte prefix `serial:` pro Python klienta.

Po úpravě `.env` nezapomeňte restartovat běžící watchery (`run_daemons.sh restart`), aby načetly nové hodnoty.

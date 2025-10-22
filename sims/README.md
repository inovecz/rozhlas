# Laboratorní simulace – Boreček 2025

Tato složka obsahuje skripty, které umožňují ověřit integraci ústředny podle dokumentu
`docs/requirements_docs/2025_10_16-Pokyny_Testování KPV_Bartek.docx`.

## Obsah

```
 sims/
 ├── README.md               # tento soubor
 ├── control_tab_sim.py      # simulátor zpráv z Control Tabu (panel_loaded, button_pressed…)
 ├── jswv_sequence_sim.py    # simulace paralelních požadavků JSVV s různou prioritou
 └── alarm_buffer_check.py   # čte LIFO buffer 0x3000–0x3009 a ověřuje frontu alarmů
```

## Požadavky
- Python 3.10+
- Přístup k seriovému portu (pokud se testuje proti reálnému zařízení)
- Aktivovaný `python-client` (předpokládá se výchozí umístění `python-client/modbus_control.py`)

## Základní scénáře

### 1. Control Tab – ovládací požadavky
```
python sims/control_tab_sim.py --button 1     # přímé hlášení (Mikrofon)
python sims/control_tab_sim.py --button 13    # poplach JSVV – všeobecná výstraha
python sims/control_tab_sim.py --text 1       # vyžádej text panelu „infopanelText“
```

Skript vypíše odpověď backendu (stav, případná pozice ve frontě) a validuje CRC podle protokolu.

### 2. Prioritní poplachy
```
python sims/jswv_sequence_sim.py --count 3 --mix
```

Spustí více JSVV sekvencí v krátkém sledu a sleduje reakci orchestrátoru (loguje frontu, priority,
stav `BroadcastSession`).

### 3. Alarm buffer
```
python sims/alarm_buffer_check.py --loops 5 --delay 2
```

Pravidelně čte registr 0x3000–0x3009 podle nového protokolu, vypíše zdroj, pořadí opakování a
datová slova. Ověří, že po čtení se nahrává další alarm.

## Poznámky
- Všechny skripty podporují volitelný argument `--port`/`--unit-id`, pokud je potřeba přesměrovat na jiný
  Modbus port.
- Při testování proti reálnému zařízení je nutné zajistit, aby byly nastaveny přenosové cesty
  (route + destination zones). Skripty využívají výchozí konfiguraci (`constants.DEFAULT_ROUTE`, `DEFAULT_DESTINATION_ZONES`).


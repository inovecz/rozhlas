# UART Komunikační Protokol - S5 Control

Tento dokument popisuje sériový komunikační protokol mezi ESP32-P4 (hlavní řídící jednotka s displejem) a externím zařízením (řídicí systém).

## 📋 Obsah
- [Základní parametry](#základní-parametry)
- [Formát zpráv](#formát-zpráv)
- [CRC Kontrolní součet](#crc-kontrolní-součet)
- [Typy zpráv ESP32 → Externí zařízení](#typy-zpráv-esp32--externí-zařízení)
- [Typy zpráv Externí zařízení → ESP32](#typy-zpráv-externí-zařízení--esp32)
- [Mechanismus spolehlivosti](#mechanismus-spolehlivosti)
- [Tabulky ID](#tabulky-id)
- [Příklady komunikace](#příklady-komunikace)

---

## ⚙️ Základní parametry

| Parametr | Hodnota |
|----------|---------|
| **UART Port** | UART0 |
| **TX Pin** | GPIO 43 |
| **RX Pin** | GPIO 44 |
| **Baudrate** | 115200 |
| **Data Bits** | 8 |
| **Parity** | None |
| **Stop Bits** | 1 |
| **Flow Control** | None |
| **Inter-message Delay** | 5 ms mezi každou odeslanou zprávou |

---

## 📦 Formát zpráv

### Zprávy ESP32 → Externí zařízení (TX)

```
\n<<<:X:Y:Z=data>>CRC<<<\n
```

| Pole | Popis |
|------|-------|
| `\n` | Start delimiter (newline) |
| `<<<:` | Message start marker |
| `X` | Screen ID (1-3) |
| `Y` | Panel ID (1-3) |
| `Z` | Event Type ID (1=panel_loaded, 2=button_pressed, 3=text_field_request) |
| `=data` | Data field (pro button_pressed=Button ID, pro text_field_request=?Field ID?, pro panel_loaded prázdné) |
| `>>` | Data end marker (VŽDY přítomen před CRC) |
| `CRC` | 1-byte kontrolní součet (hexadecimální) |
| `<<<\n` | Message end marker s newline |

**Poznámka:** 
- CRC se počítá ze stringu: `"X:Y:Z=data"` (včetně prázdného data pokud není nic)
- Marker `>>` je VŽDY přítomen před CRC ve všech TX zprávách
- Pro panel_loaded (s prázdnými daty) je formát: `\n<<<:X:Y:1=>>CRC<<<\n`

### Zprávy Externí zařízení → ESP32 (RX)

#### ACK Potvrzení
```
\n>>>:X:Y:Z=S>>CRC<<<\n
```

| Pole | Popis |
|------|-------|
| `>>>:` | Response marker |
| `X` | Echo Screen ID z TX zprávy |
| `Y` | Echo Panel ID z TX zprávy |
| `Z` | Echo Event Type z TX zprávy |
| `=S` | Status: `1` = OK, `0` = Error |
| `>>` | Data end marker (sjednocení s TX formátem) |
| `CRC` | CRC kontrolní součet (2 hex znaky) |

**Poznámka:** CRC se počítá ze stringu: `"X:Y:Z=S"`

#### TEXT Odpověď
```
\n>>>TEXT:Z:"textová hodnota">>CRC<<<\n
```

| Pole | Popis |
|------|-------|
| `>>>TEXT:` | TEXT message marker |
| `Z` | Text Field ID (1-6) |
| `:"textová hodnota"` | Text v uvozovkách |
| `>>` | Data end marker |
| `CRC` | 1-byte kontrolní součet |

**Poznámka:** CRC se počítá ze stringu: `Z:"textová hodnota"`

---

## 🔐 CRC Kontrolní součet

### Algoritmus
- **Typ:** 1-byte XOR checksum
- **Inicializace:** `crc = 0`
- **Výpočet:** Pro každý byte v datech: `crc ^= byte`
- **Formát výstupu:** Hexadecimální string (2 znaky, např. `"A5"`)

### Příklad výpočtu CRC (C)
```c
uint8_t calculate_crc(const char *data, size_t len)
{
    uint8_t crc = 0;
    for (size_t i = 0; i < len; i++) {
        crc ^= (uint8_t)data[i];
    }
    return crc;
}
```

### Příklad použití
Pro data: `"3:1:1="` (panel_loaded: Screen=3, Panel=1, Event=1, data prázdné)
```c
const char *data = "3:1:1=";
uint8_t crc = calculate_crc(data, strlen(data));
// crc bude například 0x32
// Formátování: sprintf(crc_str, "%02X", crc); → "32"
```

---

## 📤 Typy zpráv ESP32 → Externí zařízení

### 1. Panel Loaded (Event Type = 1)

Odesláno automaticky při zobrazení panelu na displeji.

**Formát:**
```
\n<<<:X:Y:1=>>CRC<<<\n
```

| Parametr | Popis |
|----------|-------|
| `X` | Screen ID (1-3) |
| `Y` | Panel ID v rámci screenu (1-3) |
| `1` | Event Type (panel_loaded) |
| `=` | Data prázdné |
| `>>` | Data end marker (VŽDY přítomen) |

**Příklad:**
```
\n<<<:2:1:1=>>0F<<<\n
```
*Panel "menu1" (Screen 2, Panel 1) byl zobrazen. CRC se počítá z "2:1:1=" → 0x0F*

---

### 2. Button Pressed (Event Type = 2)

Odesláno při stisknutí tlačítka uživatelem.

**Formát:**
```
\n<<<:X:Y:2=Z>>CRC<<<\n
```

| Parametr | Popis |
|----------|-------|
| `X` | Screen ID aktuálního screenu |
| `Y` | Panel ID aktuálního panelu |
| `2` | Event Type (button_pressed) |
| `=Z` | Button ID (1-20, 100 pro WiFi) |

**Příklad:**
```
\n<<<:3:1:2=9>>04<<<\n
```
*Tlačítko "Spusť Vysílání" (ID=9) bylo stisknuto na Screen 3, Panel 1. CRC se počítá z "3:1:2=9" → 0x04*

---

### 3. Text Field Request (Event Type = 3)

Odesláno automaticky po zobrazení panelu s textovými poli. Vyžádá si aktuální obsah textového pole.

**Formát:**
```
\n<<<:X:Y:3=?Z?>>CRC<<<\n
```

| Parametr | Popis |
|----------|-------|
| `X` | Screen ID (kde je textové pole) |
| `Y` | Panel ID (kde je textové pole) |
| `3` | Event Type (text_field_request) |
| `=?Z?` | Text Field ID (1-6) v otaznících |

**Příklad:**
```
\n<<<:3:1:3=?1?>>7E<<<\n
```
*Vyžádání obsahu pole "infopanelText" (Field ID=1) na Screen 3, Panel 1. CRC se počítá z "3:1:3=?1?" → 0x7E*

**Odpověď:**
Externí zařízení odpoví TEXT zprávou (viz níže), ESP32 pak potvrdí TEXT ACK zprávou.

---

### 4. TEXT ACK (Potvrzení TEXT zprávy)

Odesláno automaticky po příjmu TEXT odpovědi od externího zařízení. Potvrzuje validitu CRC.

**Formát:**
```
\n<<<TEXT:Z:S>>CRC<<<\n
```

| Parametr | Popis |
|----------|-------|
| `TEXT:` | TEXT ACK marker |
| `Z` | Text Field ID (echo z TEXT zprávy) |
| `S` | Status: `1` = CRC OK, `0` = CRC chyba |
| `>>` | Data end marker |
| `CRC` | Kontrolní součet řetězce `"TEXT:Z:S"` |

**Příklad - Úspěšné potvrzení:**
```
\n<<<TEXT:1:1>>0B<<<\n
```
*Potvrzení TEXT zprávy pro Field ID=1, CRC validní. CRC se počítá z "TEXT:1:1" → 0x0B*

**Příklad - Chybné CRC:**
```
\n<<<TEXT:1:0>>1A<<<\n
```
*TEXT zpráva pro Field ID=1 měla chybné CRC. CRC se počítá z "TEXT:1:0" → 0x1A. Externí zařízení může odeslat TEXT znovu.*

---

## 📥 Typy zpráv Externí zařízení → ESP32

### 1. ACK Potvrzení

Odpověď na zprávy typu: `panel_loaded`, `button_pressed`

**POZOR:** Na `text_field_request` se posílá TEXT odpověď (viz níže), ne tento typ ACK.

**Formát:**
```
\n>>>:X:Y:Z=S>>CRC<<<\n
```

| Parametr | Popis |
|----------|-------|
| `X:Y:Z` | Echo původních hodnot z TX zprávy |
| `S` | Status: `1` = OK, `0` = Error |
| `>>` | Data end marker |
| `CRC` | CRC kontrolní součet (2 hex znaky) |

**Poznámka:** CRC se počítá ze stringu: `"X:Y:Z=S"`

**Příklad - Úspěšné potvrzení button_pressed:**
```
\n>>>:3:1:2=1>>31<<<\n
```
*Potvrzení příjmu button_pressed (Screen=3, Panel=1, Event=2), status OK (1). CRC se počítá z "3:1:2=1" → 0x31*

**Příklad - Chybné potvrzení:**
```
\n>>>:3:1:2=0>>00<<<\n
```
*Chyba při zpracování zprávy (Screen=3, Panel=1, Event=2), status Error (0). CRC se počítá z "3:1:2=0" → 0x00*

---

### 2. TEXT Odpověď

Odpověď na `text_field_request` s obsahem textového pole.

**Podporuje také nevyžádané zprávy** - ESP32 aktualizuje UI i když TEXT zpráva přijde bez předchozího requestu (např. push notifikace).

**Formát:**
```
\n>>>TEXT:Z:"textová hodnota">>CRC<<<\n
```

| Parametr | Popis |
|----------|-------|
| `Z` | Text Field ID (1-6) |
| `"textová hodnota"` | Text v uvozovkách (může obsahovat mezery) |
| `CRC` | Kontrolní součet řetězce `"Z:\"textová hodnota\""` |

**Příklad:**
```
\n>>>TEXT:1:"Poplach aktivní">>45<<<\n
```
*Odpověď s textem pro Field ID=1. CRC se počítá z `1:"Poplach aktivní"` → 0x45*

**ESP32 automaticky odešle TEXT ACK:**
```
\n<<<TEXT:Z:S>>CRC<<<\n
```
Kde:
- `Z` = Text Field ID (echo)
- `S` = Status: `1` = CRC OK, `0` = CRC chyba
- `CRC` = Kontrolní součet řetězce `"TEXT:Z:S"`

**Zpracování na ESP32:**
- **Vyžádaná zpráva:** Odešle TEXT ACK, aktualizuje UI, odebere z pending bufferu
- **Nevyžádaná zpráva:** Odešle TEXT ACK, aktualizuje UI (umožňuje push notifikace od externího zařízení)
- **Nevalidní CRC:** Odešle TEXT ACK se statusem `0`, UI se neaktualizuje

**Příklad TEXT ACK:**
```
\n<<<TEXT:1:1>>XX<<<\n
```
*Potvrzení příjmu TEXT zprávy pro Field ID=1, CRC validní (status=1)*

---

## 🔄 Mechanismus spolehlivosti

### Retry mechanismus

| Typ zprávy | Timeout | Max. počet pokusů | Poznámka |
|------------|---------|-------------------|----------|
| `panel_loaded` | 100 ms | 3 | Ukončí se po 3 pokusech |
| `button_pressed` | 100 ms | 3 | Ukončí se po 3 pokusech |
| `text_field_request` | 1000 ms | Neomezeno | Důležitá data, čeká na odpověď |

### Inter-message delay
- **5 ms mezera** mezi každou odeslanou zprávou
- Prevence přetížení UART bufferu

### Zpracování ACK
1. ESP32 odešle zprávu (`panel_loaded` nebo `button_pressed`) a přidá ji do pending bufferu (max 10 zpráv)
2. Čeká na ACK odpověď od externího zařízení
3. Pokud přijde ACK se statusem `1` → zpráva je potvrzena a odstraněna z bufferu
4. Pokud přijde ACK se statusem `0` → zpráva je znovu odeslána
5. Pokud vyprší timeout → zpráva je znovu odeslána (max. 3× pro panel/button)

### Zpracování TEXT
1. ESP32 odešle `text_field_request`
2. Externí zařízení odešle TEXT odpověď s CRC
3. ESP32 ověří CRC a odešle TEXT ACK:
   - **Validní CRC:** Odešle TEXT ACK se statusem `1`, aktualizuje UI, zpráva se odebere z pending bufferu
   - **Nevalidní CRC:** Odešle TEXT ACK se statusem `0`, zpráva zůstává v pending bufferu pro retry
4. Pokud nedorazí TEXT odpověď → retry po 1000 ms

---

## 🗂️ Tabulky ID

### Screen ID

| Screen ID | Název Screenu | Popis                           |
|-----------|---------------|---------------------------------|
| 1         | Screen1       | Úvodní obrazovka                |
| 2         | Screen2       | Menu obrazovka                  |
| 3         | Screen3       | Informační a ovládací obrazovka |

---

### Panel ID

| Screen ID | Panel ID | Název Panelu | Textová pole      |
|-----------|----------|--------------|-------------------|
| 1         | 1        | Panel1       | -                 |
| 2         | 1        | menu1        | -                 |
| 2         | 2        | Panel2       | -                 |
| 2         | 3        | Panel3       | -                 |
| 3         | 1        | playInfo     | 3 pole (ID 1,2,3) |
| 3         | 2        | PrimeHlaseni | 3 pole (ID 4,5,6) |

---

### Button ID

| Button ID | Název Tlačítka       | Screen | Panel        | Funkce                |
|-----------|----------------------|--------|--------------|-----------------------|
| 1         | Spustit Hlášení      | 2      | menu1        | Spuštění hlášení      |
| 2         | Zkouška Siren        | 2      | menu1        | Test sirén            |
| 3         | Chemická Havárie     | 2      | menu1        | Typ poplachu          |
| 4         | Všeobecná Výstraha   | 2      | menu1        | Typ poplachu          |
| 5         | Požární Poplach      | 2      | menu1        | Typ poplachu          |
| 6         | Zatopová Vlna        | 2      | menu1        | Typ poplachu          |
| 7         | Mikrofon             | 2      | Panel2       | Aktivace mikrofonu    |
| 8         | Konec Poplachu       | 2      | Panel2       | Ukončení poplachu     |
| 9         | Spusť Vysílání       | 3      | playInfo     | Spustit vysílání      |
| 10        | Stop                 | 3      | playInfo     | Zastavit vysílání     |
| 11        | Spusť Vysílání 1     | 3      | PrimeHlaseni | Spustit přímé hlášení |
| 12        | Přímé Hlášení Zrušit | 3      | PrimeHlaseni | Zrušit přímé hlášení  |
| 13        | Spustit Poplach JSVV | 2      | menu1        | JSVV poplach          |
| 14        | Ostatní              | 2      | menu1        | Ostatní funkce        |
| 15        | Stop (menu1)         | 2      | menu1        | Zastavit v menu       |
| 16        | Lock                 | 2      | Panel2       | Zamknout panel        |
| 17        | Radiační Poplach     | 2      | menu1        | Typ poplachu          |
| 18        | Zrušit               | 2      | Panel3       | Zrušit akci           |
| 19        | Vybrat Znělku        | 3      | PrimeHlaseni | Výběr znělky          |
| 20        | Vybrat Lokalitu      | 3      | PrimeHlaseni | Výběr lokality        |
| 100       | WiFi Icon            | *      | *            | WiFi nastavení        |

---

### Text Field ID

| Field ID | Název Pole                | Screen | Panel        | Popis                           |
|----------|---------------------------|--------|--------------|---------------------------------|
| 1        | infopanelText             | 3      | playInfo     | Informační panel - hlavní text  |
| 2        | dobaHlaseniText           | 3      | playInfo     | Doba hlášení (formát času)      |
| 3        | delkaHlaseniText          | 3      | playInfo     | Délka hlášení (sekundy)         |
| 4        | planovanaDelkaHlaseniText | 3      | PrimeHlaseni | Plánovaná délka přímého hlášení |
| 5        | seznamLokalitText         | 3      | PrimeHlaseni | Seznam aktivních lokalit        |
| 6        | vybranaZnelkaText         | 3      | PrimeHlaseni | Název vybrané znělky            |

---

## 💡 Příklady komunikace

### Příklad 1: Zobrazení panelu s texty

**Uživatel otevře panel "playInfo" (Screen 3, Panel 1)**

1. ESP32 → Externí zařízení: Panel loaded
```
\n<<<:3:1:1=>>0E<<<\n
```
*(Screen=3, Panel=1, Event=1 (panel_loaded), Data prázdné, `>>` marker přítomen, CRC vypočítáno z "3:1:1=" → 0x0E)*

2. Externí zařízení → ESP32: ACK
```
\n>>>:3:1:1=1>>32<<<\n
```
*(Screen=3, Panel=1, Event=1, Status=1, CRC se počítá z "3:1:1=1" → 0x32)*

3. ESP32 → Externí zařízení: Text field requests (3× pro 3 pole v panelu)
```
\n<<<:3:1:3=?1?>>7E<<<\n
\n<<<:3:1:3=?2?>>7F<<<\n
\n<<<:3:1:3=?3?>>7C<<<\n
```
*(Screen=3, Panel=1, Event=3 (text_field_request), Data=?Field ID?, CRC: "3:1:3=?1?" → 0x7E, "3:1:3=?2?" → 0x7F, "3:1:3=?3?" → 0x7C)*

4. Externí zařízení → ESP32: TEXT odpovědi
```
\n>>>TEXT:1:"Systém aktivní">>7D<<<\n
\n>>>TEXT:2:"10:35:20">>47<<<\n
\n>>>TEXT:3:"120 s">>49<<<\n
```
*(CRC: `1:"Systém aktivní"` → 0x7D, `2:"10:35:20"` → 0x47, `3:"120 s"` → 0x49)*

5. ESP32 → Externí zařízení: TEXT ACK potvrzení (pro každý TEXT)
```
\n<<<TEXT:1:1>>0B<<<\n
\n<<<TEXT:2:1>>08<<<\n
\n<<<TEXT:3:1>>09<<<\n
```
*(Pro každé pole TEXT ACK se statusem=1 (CRC OK), CRC: "TEXT:1:1" → 0x0B, "TEXT:2:1" → 0x08, "TEXT:3:1" → 0x09)*
*(ESP32 aktualizuje UI a odebere zprávy z pending bufferu)*

---

### Příklad 2: Stisknutí tlačítka s retry

**Uživatel stiskne "Spusť Vysílání" (Button ID=9) na Screen 3, Panel 1**

1. ESP32 → Externí zařízení: Button pressed
```
\n<<<:3:1:2=9>>04<<<\n
```
*(Screen=3, Panel=1, Event=2 (button_pressed), Data=Button ID 9, CRC vypočítáno z "3:1:2=9" → 0x04)*

2. *Externí zařízení neodpoví (zpráva se ztratí)*

3. **Po 100 ms timeout:** ESP32 → Externí zařízení: Retry #1
```
\n<<<:3:1:2=9>>04<<<\n
```
*(Stejná zpráva se odešle znovu s identickým CRC → 0x04)*

4. Externí zařízení → ESP32: ACK OK
```
\n>>>:3:1:2=1>>31<<<\n
```
*(Echo Screen=3, Panel=1, Event=2, Status=1 OK, CRC vypočítáno z "3:1:2=1" → 0x31)*

5. Zpráva potvrzena, odstraněna z pending bufferu ✅

---

### Příklad 3: TEXT s chybným CRC

**ESP32 vyžádá text pro Field ID=1 na Screen 3, Panel 1**

1. ESP32 → Externí zařízení:
```
\n<<<:3:1:3=?1?>>7E<<<\n
```
*(Screen=3, Panel=1, Event=3, Data=?Field ID 1?, CRC vypočítáno z "3:1:3=?1?" → 0x7E)*

2. Externí zařízení → ESP32: TEXT s CHYBNÝM CRC
```
\n>>>TEXT:1:"Test data">>FF<<<\n
```
*(předpokládejme, že správný CRC by byl 0x7A)*

3. ESP32 validuje CRC → **CHYBA!**
   - Log: `"TEXT CRC mismatch! Expected: 7A, Received: FF"`

4. ESP32 → Externí zařízení: TEXT ACK Error
```
\n<<<TEXT:1:0>>1A<<<\n
```
*(Field ID=1, Status=0 Error - CRC nevalidní, CRC se počítá z "TEXT:1:0" → 0x1A, zpráva zůstává v pending)*

5. **Po 1000 ms timeout:** ESP32 → Retry request
```
\n<<<:3:1:3=?1?>>7E<<<\n
```
*(Stejná zpráva se odešle znovu s identickým CRC → 0x7E)*

6. Externí zařízení → ESP32: TEXT se SPRÁVNÝM CRC
```
\n>>>TEXT:1:"Test data">>7A<<<\n
```
*(CRC správně vypočítáno z `1:"Test data"` → 0x7A)*

7. ESP32 → Externí zařízení: TEXT ACK OK
```
\n<<<TEXT:1:1>>0B<<<\n
```
*(Field ID=1, Status=1 OK, CRC se počítá z "TEXT:1:1" → 0x0B)*

8. ESP32 zpracuje TEXT zprávu:
   - Validuje CRC → **OK**
   - Aktualizuje text v UI
   - Odebere zprávu z pending bufferu

9. Text aktualizován v UI ✅

---

### Příklad 4: Výpočet CRC krok za krokem

**Data pro button_pressed:** `"3:1:2=9"` (Screen=3, Panel=1, Event=2, Button ID=9)

```c
char data[] = "3:1:2=9";
uint8_t crc = 0;

crc ^= '3';  // crc = 0x33
crc ^= ':';  // crc = 0x33 ^ 0x3A = 0x09
crc ^= '1';  // crc = 0x09 ^ 0x31 = 0x38
crc ^= ':';  // crc = 0x38 ^ 0x3A = 0x02
crc ^= '2';  // crc = 0x02 ^ 0x32 = 0x30
crc ^= '=';  // crc = 0x30 ^ 0x3D = 0x0D
crc ^= '9';  // crc = 0x0D ^ 0x39 = 0x34

// Výsledek: crc = 0x34
// Formátovaný string: "34"
```

**Finální zpráva button_pressed:**
```
\n<<<:3:1:2=9>>34<<<\n
```

---

**Data pro panel_loaded:** `"3:1:1="` (Screen=3, Panel=1, Event=1, data prázdné)

```c
char data[] = "3:1:1=";
uint8_t crc = 0;

crc ^= '3';  // crc = 0x33
crc ^= ':';  // crc = 0x33 ^ 0x3A = 0x09
crc ^= '1';  // crc = 0x09 ^ 0x31 = 0x38
crc ^= ':';  // crc = 0x38 ^ 0x3A = 0x02
crc ^= '1';  // crc = 0x02 ^ 0x31 = 0x33
crc ^= '=';  // crc = 0x33 ^ 0x3D = 0x0E

// Výsledek: crc = 0x0E
// Formátovaný string: "0E"
```

**Finální zpráva panel_loaded:**
```
\n<<<:3:1:1=>>0E<<<\n
```
*(Všimněte si: `>>` marker JE přítomen i když data jsou prázdná)*

---

### Příklad 5: Nevyžádaná TEXT zpráva (Push notifikace)

**Externí zařízení chce aktualizovat text bez předchozího requestu**

1. Externí zařízení → ESP32: Nevyžádaná TEXT zpráva
```
\n>>>TEXT:1:"POPLACH! Evakuace zahájena">>2D<<<\n
```
*Externí zařízení zasílá push notifikaci pro Field ID=1. CRC se počítá z `1:"POPLACH! Evakuace zahájena"` → 0x2D*

2. ESP32 zpracuje zprávu:
   - Validuje CRC → **OK**
   - Nenajde odpovídající pending request (zpráva nebyla vyžádána)
   - **AKTUALIZUJE UI** - text se zobrazí v příslušném poli
   - Log: `"Received unsolicited TEXT for field_id=1 - UI updated via callback"`

3. ESP32 → Externí zařízení: TEXT ACK OK
```
\n<<<TEXT:1:1>>0B<<<\n
```
*(Field ID=1, Status=1 OK, CRC se počítá z "TEXT:1:1" → 0x0B)*

4. ✅ Text "POPLACH! Evakuace zahájena" se zobrazí v UI pole `infopanelText`

**Poznámka:** Toto umožňuje externímu zařízení posílat okamžité aktualizace bez čekání na request od ESP32.

---

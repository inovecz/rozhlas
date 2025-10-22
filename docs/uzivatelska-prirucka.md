# Uživatelská příručka Sarrah V (aktualizace 2025-05-24)

Tato příručka shrnuje aktuální možnosti rozhraní aplikace Sarrah V. Soustředí se na každodenní práci operátora – živé vysílání, přípravu vlastních nahrávek, plánování úloh a obsluhu systému JSVV. Všechny popsané obrazovky jsou dostupné v hlavním menu aplikace.

---

## 1. Živé vysílání

### 1.1 Hlavní panel
- Velké tlačítko umožňuje okamžitě spustit nebo zastavit vysílání.
- V horní části se zobrazí aktuální stav (běží/neaktivní), použitý zdroj, délka běžícího vysílání a čas posledního startu/stopu.
- Pod tlačítkem je informační karta „Aktuální stav vysílání“ se všemi relevantními údaji (ID relace, zdroj, stav, požadované/aplikované trasy, zóny, hnízda). Případné problémy se zobrazují v sekci „Upozornění“.
- Karta „Příští vysílání“ zobrazuje naplánovanou úlohu z Plánu vysílání (název, termín a odkaz na úpravu).

### 1.2 Nastavení zdrojů a obsahu
- **Výběr zdroje** – dostupné zdroje:  
  `Mikrofon`, `Soubor v ústředně`, `Vstup z PC (WebRTC)`, `Vstup 2 – Vstup 8`, `FM Rádio`, `Control box`.
- **Soubor v ústředně** – zobrazí se správa playlistu (výběr souborů z katalogu, odebírání, pořadí).
- **FM Rádio** – umožňuje načíst či aktualizovat frekvenci z nastavení.
- **Ovládání hlasitosti** – jediný slider pracuje vždy s hlasitostí aktuálně vybraného zdroje (připojeno přímo na ALSA mixer). Zobrazuje název kanálu, krok 0,5 dB, rozsah −12 až +59,5 dB.
- **Audio výstup** – volba reproduktorů/zařízení pro monitoring (uloženo v LocalStorage).
- **Poznámka** – text ukládaný k běžné relaci (promítá se do payloadu vysílání).

### 1.3 Spouštění a živá aktualizace
- Před spuštěním je kontrolováno, zda jsou vybrány povinné údaje (např. soubory pro „Soubor v ústředně“).
- Během běžícího vysílání lze:
  - změnit zdroj (aplikuje se až po splnění precondicí – např. vybrané soubory),
  - upravit playlist, hlasitost, poznámku nebo výstup; změny se okamžitě odesílají do ústředny přes Live update.
- Tlačítko „Aktualizovat stav“ vyvolá manuální načtení stavu z backendu.
- „Vynutit stop“ okamžitě ukončí relaci (s identifikátorem `frontend_stop`).

### 1.4 Nejčastější scénáře
| Scénář | Postup |
| --- | --- |
| **Vysílání předtočeného pořadu** | Zvolte „Soubor v ústředně“, otevřete playlist, přidejte požadované položky, případně upravte hlasitost a poznámku, poté spusťte vysílání. |
| **Přechod na FM** | Změňte zdroj na „FM Rádio“, klikněte na „Aktualizovat frekvenci“, zkontrolujte hodnotu a potvrďte změnu zdroje. |
| **Rychlá změna hlasitosti** | Bez ohledu na zvolený zdroj použijte slider „Hlasitost“ – hodnota se okamžitě propsuje do ALSA a uloží se i pro další spuštění stejného kanálu. |

> **Poznámka k zdrojům:** Pokud uživatel zvolí zdroj, který není dosud napojen (např. „Vstup 7“), aplikace sice umožní spustit vysílání, ale v praxi bude nutné zkontrolovat, zda je na mixu dostupný fyzický signál.

---

## 2. Záznamy (Recorder)

### 2.1 Hlavní ovládání
- Na stránce „Záznamy“ je k dispozici karta s velkým tlačítkem **Spustit/Zastavit**. Zobrazuje název zdroje, průběžnou délku a čas startu/stopu.
- K dispozici je lokální náhled, takže lze záznam přehrát před uložením.

### 2.2 Nastavení záznamu
- **Zdroj záznamu** – stejné možnosti jako u živého vysílání.
- **Hlasitost** – slider ovládá příslušný mix kanál přes ALSA (stejné nastavení se aplikuje i při startu vysílání).
- **Poznámka** – volitelný text, ukládá se v metadatach souboru.
- Nahrává se pomocí `MediaRecorder`; kontejner a kodek se volí automaticky (preferován `audio/webm;codecs=opus`).
- User může vložit externí soubor; metadata (délka) se zpracují automaticky.

### 2.3 Uložení
- Po ukončení záznamu se zobrazí dialog „Uložit nahrávku“.  
  - Lze zadat název a typ (Běžné hlášení, Úvodní slovo, …).  
  - Metadatům se předá délka, zdroj a poznámka.  
  - Po úspěšném uložení se vyvolá událost `recordSaved`, která obnoví seznam níže.
- V pravém sloupci je komponenta **Seznam nahrávek** s filtrováním, přehráváním, přejmenováním a mazáním (napojeno na `/records` API).

### 2.4 Doporučené workflow
1. **Rychlé info** – stiskněte „Spustit“ a po dobu hlášení mluvte na mikrofon; po dokončení klikněte na „Zastavit“ a ihned uložte jako „Běžné hlášení“.
2. **Import externí nahrávky** – použijte volbu „Vybrat soubor“ (pod sliderem hlasitosti), zkontrolujte náhled, uložte s vhodným názvem.  
3. **Archivace delších pořadů** – rozdělte pořad na části, uložte s relevantními typy (např. „Úvodní slovo“, „Závěrečné slovo“). Díky tomu je později lze snadno zařadit do plánu vysílání.

---

## 3. Plán vysílání

### 3.1 Obecné informace
- Název a termín (datum + čas) jsou povinné. Aplikace hlídá kolizi termínů (API `schedules/check-time-conflict`).
- Výběr zvuků: `Úvodní znělka`, `Úvodní slovo`, `Hlášení` (více položek), `Závěrečné slovo`, `Závěrečná znělka`. Každá položka lze přehrát, smazat, měnit pořadí.
- Délka vysílání se přepočítává v reálném čase (zohledněna délka nahrávek i opakování).

### 3.2 Opakování hlášení
- Volbou „Opakovat hlášení“ se zobrazí:
  - **Počet opakování** (≥1).
  - **Typ intervalu**: `Minuty`, `Hodiny`, `Dny`, `Vybraný den v týdnu`, `Měsíce`, `První daný den v měsíci`, `Roky`.
  - Podle typu je k dispozici numerický vstup nebo výběr dne.
  - Celková délka se přepočítává jako (délka hlášení * počet opakování) + intervaly mezi opakováními.
- Hodnoty se ukládají do `repeat_count`, `repeat_interval_value`, `repeat_interval_unit`, `repeat_interval_meta`.

### 3.3 Ukládání a validace
- API `schedules` ukládá kompletní strukturu. Frontend kontroluje minimální délky názvu, přítomnost hlášení, správnost opakování.
- Po uložení se aplikace vrátí na seznam naplánovaných úloh.

### 3.4 Tipy pro plánování
- **Předsazení o několik minut** – zadejte termín o 5 minut dříve, než má vysílání opravdu začít; ústředna tak získá čas na inicializaci.
- **Zkouška** – vytvořte zkušební úlohu s jediným „Hlášení“ typu Zkouška sirén, nastavte opakování 1× ročně; úlohu ponechte deaktivovanou a aktivujte pouze před oficiální zkouškou.
- **Přehled kolizí** – při plánování více opakovaných úloh se doporučuje exportovat seznam `schedules/list` a zkontrolovat, zda se intervaly nepřekrývají (API vrací `start`/`end`).

---

## 4. Poplach JSVV

### 4.1 Přehled a rychlá tlačítka
- Hlavní obrazovka obsahuje:
  - Tlačítka 1–8 s předdefinovanými poplachy. Každé tlačítko zobrazuje ID, název, **sekvenci (např. 28A9)** a slovní popis kroků (např. Trvalý tón → Gong č.1 → Zkouška sirén → Gong č.2).
  - Pokud není sekvence uložená v backendu, použije se výchozí fallback podle zadání.
  - Tlačítko „Nastavit“ vede do editoru příslušného poplachu; „Spustit poplach“ odešle sekvenci do ústředny (přes `jsvv/sequences` + `trigger`).
- Odkazy:
  - **Protokol JSVV** – přechod do logů.
  - **Nastavení JSVV** – přímý odkaz na detailní konfiguraci.

### 4.2 Vlastní poplach
- Levý panel: čtyři kategorie (`Sirény`, `Gongy`, `Verbální informace`, `Audiovstupy`) s filtrem. Každý zvuk se přidá kliknutím do sestavy.
- Pravý panel: aktuální sekvence, možnost měnit pořadí, mazat položky, spustit nebo uložit.
- Uložení vlastní sekvence zatím informativní – plné uložení se provádí v Nastavení JSVV (builder sdílí stejné rozhraní).

### 4.3 Výchozí obsah poplachů
| Tlačítko | Sekvence | Průběh (kroky) |
| --- | --- | --- |
| 1 Zkouška sirén | `28A9` | Trvalý tón → Gong č. 1 → Zkouška sirén → Gong č. 2 |
| 2 Všeobecná výstraha | `18B9` | Kolísavý tón → Gong č. 1 → Všeobecná výstraha → Gong č. 2 |
| 3 Požární poplach | `48G9` | Požární poplach → Gong č. 1 → Požární poplach → Gong č. 2 |
| 4 Zátopová vlna | `18C9` | Kolísavý tón → Gong č. 1 → Zátopová vlna → Gong č. 2 |
| 5 Chemická havárie | `18D9` | Kolísavý tón → Gong č. 1 → Chemická havárie → Gong č. 2 |
| 6 Radiační poplach | `18E9` | Kolísavý tón → Gong č. 1 → Radiační havárie → Gong č. 2 |
| 7 Konec poplachu | `8F9` | Gong č. 1 → Konec poplachu → Gong č. 2 |
| 8 Mikrofon | `8M` | Gong č. 1 → Mikrofon |

> Sekvence jsou dostupné i na mobilních tlačítkách 1–8. Tlačítka 0 a 9 lze přizpůsobit dle konkrétní potřeby (např. pro lokální hlášení).

---

## 5. Nastavení JSVV

### 5.1 Tlačítka JSVV (desktop a mobil)
- Tabulky pro tlačítka 1–8 (desktop) a 0–9 (mobil).  
  - Předvyplněné sekvence:  
    1 `28A9`, 2 `18B9`, 3 `48G9`, 4 `18C9`, 5 `18D9`, 6 `18E9`, 7 `8F9`, 8 `8M`.  
  - Mobilní tlačítka 0 a 9 jsou zatím prázdná (lze definovat).
- Otevření builderu (stejný jako u vlastního poplachu) umožňuje vizuální výběr sekvence.
- Uložením se volá `jsvv-alarms` API; sekvence se zapisují jak pro desktop, tak mobilní tlačítka.

### 5.2 Nastavení zvuků JSVV
- Seznam symbolů `1-9, A-Y`. Přes dropdown lze zvolit:
  - systémový vstup (Vstup 1–8, FM, Mikrofon),
  - nebo zvukový soubor (výběr přes dialog).
- „Uložit zvuky“ odešle změny na `jsvv-alarms/audios`.

### 5.3 Ukládání sekvencí
- Při ukládání tlačítka se sekvence převádí na velká písmena a ukládá do databáze.
- Pokud sekvence není definovaná, fallback v modulu Poplach JSVV použije výchozí hodnotu (`defaultSequence`).
- Uložené sekvence lze kdykoli obnovit – stačí znovu otevřít nastavení a potvrdit, nebo kliknout na „Vyčistit“ a zadat nové symboly.

### 5.3 Lokalita, SMS, e-mail
- **Lokalita** – primární lokalita pro rozvod JSVV (výchozí „General“).
- **SMS upozornění** – volitelné, s validací seznamu čísel a textu zprávy.
- **E-mail** – podobně; validuje se adresát, předmět i tělo zprávy.

++ 5.4 Fallback logika pro tlačítka
- Pokud na backendu není tlačítko od 1 do 8 nakonfigurováno, modul Poplach JSVV automaticky použije výše uvedené výchozí sekvence.
- To umožňuje okamžitou reakci i po čisté instalaci systému – administrátor však může sekvence upravit a uložit v nastavení.
- Stejná logika platí i pro mobilní tlačítka; pokud není tlačítko nastaveno, použije se desktopová hodnota (pokud existuje).

---

## 6. Mapa a seznam hnízd

### 6.1 Editor hnízda
- Dialog pro nové/úpravu místa nabízí:
  - název, typ (Centrála/Hnízdo),
  - výchozí lokalitu + multiselect dalších lokalit, do kterých hnízdo patří,
  - modbus adresu,
  - **Adresa obousměru hnízda (A16)**,
  - **Privátní adresa přijímače (A16)**,
  - výběr součástí (checkboxy: Přijímač, Nabíječ, Obousměr, Ekotechnika, Proudová smyčka, BAT+REP Test, Digitální interface, Digitální obousměr),
  - stav hnízda (`V pořádku`, `Varování`, `Chyba`, `Neznámý stav`).
- Přidání nového hnízda přes mapu automaticky předvyplní souřadnice; po uložení se aktualizuje kolekce v Pinia store a na backendu (`locations/save`).

### 6.2 Vizualizace na mapě
- Hnízda používají barvy podle stavu: zelená (OK), oranžová (Varování), červená (Chyba), modrá (Neznámý). Centrály jsou oranžové.
- Popup zobrazuje název, stav a seznam součástí.
- Přes tlačítko „Upravit rozmístění“ lze aktivovat drag & drop a uložit nové pozice.

### 6.2.1 Barvy ikon
| Stav | Barva | Význam |
| --- | --- | --- |
| OK | Zelená | Hnízdo je v pořádku, všechny součásti reagují. |
| WARNING | Oranžová | Vyžaduje pozornost – např. vybitá baterie, částečná závada. |
| ERROR | Červená | Hnízdo hlásí kritickou chybu (např. výpadek komunikace). |
| UNKNOWN | Modrá | Stav není znám (např. hnízdo ještě neodpovědělo). |

### 6.3 Seznam hnízd
- Tabulka zobrazuje rozšířené informace: obousměrná adresa, privátní adresa, součásti, přiřazené lokality, stav (s barevným indikátorem), souřadnice, aktivita.
- Při úpravě záznamu se hodnoty ukládají přes `locations/save`; multiselect lokalit zapisuje do pivot tabulky.

---

## 7. Doporučené postupy

### 7.1 Po nasazení nové verze
1. Spusťte `php artisan migrate` – databáze obsahuje nová pole (hnízda, plán vysílání).
2. V nastavení JSVV potvrďte, že sekvence tlačítek odpovídají požadovaným hodnotám; případně uložte změny.
3. Z mapy otevřete několik hnízd a doplňte chybějící údaje (adresy, součásti, stav).

### 7.2 Běžný provoz
- Pro rutinní hlášení stačí využívat „Poplach JSVV“ – rychlá tlačítka a protokol.
- Pro kombinované vysílání (živé + záznamy) používejte stránku Živé vysílání a stránku Záznamy (rychlé natáčení a archiv).
- Opakující se scénáře vkládejte do Plánu vysílání s vyladěnými opakovacími pravidly.
- Průběžně kontrolujte mapu hnízd (barvy) a doplňujte součásti pro přesnou diagnostiku.

---

## 8. Technické poznámky
- Hlasitosti se nastavují přímo přes ALSA (`amixer`); záznam i živé vysílání používají stejnou logiku.
- Sekvence JSVV se plánují pomocí `jsvv/sequences` a následně spouští (`trigger`); fallback sekvence umožňuje okamžité spuštění i bez explicitního nastavení.
- Opakování plánovaného vysílání ukládá metadata, ale časovač spouštění by měl brát v potaz, že intervaly `weekday` nebo `first_weekday_month` vyžadují logiku na backendu (zatím pouze ukládáme hodnoty).
- Správa hnízd používá pivot tabulku `location_location_group`; v API je zachována zpětná kompatibilita (původní `location_group_id` zůstává jako výchozí).

### 8.1 Audio kanály a hlasitosti
| Kanál (ALSÁ) | Zdroj v UI | Poznámka |
| --- | --- | --- |
| `input_1` | Mikrofon | Standardní mikrofonní vstup. |
| `file_playback` | Soubor v ústředně | Přehrávání playlistu. |
| `pc_webrtc` | Vstup z PC (WebRTC) | Přímý přenos z prohlížeče. |
| `input_2 – input_8` | Vstup 2 – 8 | Volitelné fyzické vstupy. |
| `fm_radio` | FM rádio | Převzato z nastavení FM. |
| `control_box` | Control box | Speciální signál pro krizi. |

> Hlasitost se ukládá do LocalStorage (`audioOutputDevice`) a zároveň se aplikuje přes `amixer`. Po restartu prohlížeče zůstává zachována.

### 8.2 Troubleshooting
- **Živé vysílání nejde spustit** – ověřte, zda je vybraný zdroj připraven (např. playlist není prázdný). Zkontrolujte chyby v toast notifikacích.
- **Poplach JSVV nelze spustit** – pokud není dostupná sekvence, zkontrolujte Nastavení tlačítek. V defaultu by měla být přítomna fallback sekvence.
- **Hnízda mají modrou barvu** – systém nedostal žádnou stavovou informaci; zkontrolujte komunikaci obousměru. V editoru lze stav dočasně upravit manuálně pro evidenci.

---

> **Tip**: Pro nového operátora se doporučuje projít obrazovky v pořadí:  
> (1) Nastavení JSVV → (2) Poplach JSVV → (3) Živé vysílání → (4) Záznamy → (5) Plán vysílání → (6) Mapa.  
> Tak získá přehled o celém workflow od konfigurace přes přípravu materiálů až po vyhodnocení.

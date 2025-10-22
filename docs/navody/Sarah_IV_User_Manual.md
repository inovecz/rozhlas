# SARAH IV – Uživatelská dokumentace

> Rekonstruovaná a rozšířená příručka na základě původního manuálu „Sarah_V_User_Manual_1.8.0.docx“. Verze 1.8 (aktualizace 2025).

---

## 1. Přehled systému

### 1.1 Co je Sarah V
Sarah V je integrovaná ústředna pro obecní rozhlas, navržená pro kombinaci plánovaných, ručně spouštěných i automatických hlášení. Systém zahrnuje:

- **Řídicí jednotku (ústřednu)** umístěnou obvykle na radnici nebo v krizovém středisku.
- **Venkovní hnízda** s reproduktory/sirénami, senzory (např. hladinoměry), případně obousměrnou komunikací.
- **Webovou aplikaci** pro správu, přístupnou přes HTTPS.
- **Rozšiřující hardware**: GSM modul (Waveshare SIM7600G-H-PCIE), FM přijímač (USB RTL2832U + R820T2), ovládací Control Box, mobilní klienti.

### 1.2 Typické scénáře použití
- **Ranní hlášení** vyučovacích institucí – automatické playlisty.
- **Varování obyvatelstva** přes JSVV poplachy.
- **Živá krizová komunikace** (mikrofon, mobil, Control Box).
- **Monitoring** – stav ústředny, GSM signálu, SMS zpráv.

### 1.3 Architektura a datové toky
| Komponenta | Úloha | Komunikační kanál |
|------------|-------|--------------------|
| Ústředna | vyhodnocení plánů, vysílání, logování | Interní sběrnice + Ethernet |
| Webová aplikace | UI, API | HTTPS/REST |
| Daemony (GSM, JSVV) | Poslech hardware, push do API | Python + HTTP webhook |
| GSM modul | příjem/odesílání SMS, hlasový automat | UART/USB |
| FM přijímač | audio vstup | USB + ALSA | 
| Fronta (Laravel queue) | zpracování playlistů | Supervisor + PHP |

---

## 2. Technické požadavky

### 2.1 Hardware
- Ústředna s Linux/ARM nebo x86 (min. 4 GB RAM, 32 GB úložiště).
- USB port pro FM přijímač (RTL-SDR Blog V3 s čipem RTL2832U + R820T2).
- PCIe nebo USB slot pro GSM modul Waveshare SIM7600G-H-PCIE, případně emp slot.
- Síťové rozhraní 100/1000 Mbps.
- Reproduktory/zesilovače ve venkovních hnízdech.

### 2.2 Software
- OS: Debian/Ubuntu (LTS) nebo přizpůsobený build dodavatele.
- Docker (volitelně) pro izolaci daemons.
- Laravel 10+ (PHP 8.2), Node.js 18+, Python 3.11.
- Nginx/Apache s platným TLS certifikátem.
- Browser klienti: Chrome ≥ 60, Firefox ≥ 55, Edge Chromium.

### 2.3 Síť a bezpečnost
- Přístup pouze přes HTTPS (možno použít interní CA).
- Šifrované VPN pro vzdáleného administrátora.
- Firewall s povolením pouze nutných portů (HTTPS, GSM data, monitorovací služby).
- Pravidelná rotace hesel a audit přístupů.

### 2.4 Prerekvizity klienta
- Mikrofon + reproduktory (pro WebRTC).
- Povolení přístupu k médiím v prohlížeči.
- Dostupnost WebRTC, WebAudio, WebSockets.

---

## 3. Spuštění aplikace a přihlášení

### 3.1 Přístupová URL
1. Zjistěte IP nebo DNS ústředny od správce.
2. V prohlížeči otevřete `https://<adresa>`. Poprvé potvrďte výjimku certifikátu (pokud je self-signed).

### 3.2 Přihlašovací formulář
- Pole **Uživatelské jméno** a **Heslo** (case-sensitive).
- Volba **Zůstat přihlášen** (pokud je implementována) prodlužuje expiraci tokenu.
- Při zapomenutí hesla kontaktujte Administrátora nebo Servisního technika.

### 3.3 Bezpečnostní mechanismy
- Po 5 neúspěšných pokusech se účet dočasně zablokuje.
- Platnost relace 12 h, poté automatické odhlášení.
- TLS handshake zajišťuje šifrování.

---

## 4. Navigace a rozvržení aplikace

### 4.1 Hlavní menu
Levý panel obsahuje modulární položky:

1. Přímé hlášení
2. Záznam
3. Poplachy JSVV
4. Plánování
5. Historie hlášení
6. Zprávy (SMS a E-mail)
7. Kontakty
8. Skupiny
9. Soubory
10. Info
11. Aktivní alarmy
12. Protokoly
13. O aplikaci
14. Mapa
15. Nastavení (podmenu: Uživatelé, GSM, servisní parametry)

### 4.2 Boční stavový panel
Pravý panel informuje o:
- Připojení k ústředně (heartbeat, latence).
- Stavu vysílání (zdroj, lokality, délka, operátor).
- Přehrávači (aktivní soubory, cesty).
- GSM modulu (síť, signál, počet SMS).
- Senzorech/hnízdech (pokud jsou integrovány).

### 4.3 Uživatelské menu
- Změna hesla.
- Odhlášení.
- Přepnutí jazyka (pokud je lokalizace k dispozici).
- Stažení nápovědy.

---

## 5. Modul Přímé hlášení

### 5.1 Výběr vstupu
| Zdroj | V aplikaci | Popis | Typické použití |
|-------|------------|-------|------------------|
| Mikrofon | `Mikrofon` | WebRTC vstup z vašeho počítače | okamžitá hlášení operátora |
| Soubor v ústředně | `Soubor v ústředně` | Přehrává audio uložené v systému (playlist) | připravené spoty a znělky |
| PC (WebRTC) | `Vstup z PC (WebRTC)` | WebRTC linka z jiného zařízení (např. notebook na sále) | vzdálený komentátor |
| Analogové vstupy | `Vstup 2` … `Vstup 8` | Fyzické vstupy ústředny (AUX, mix, bezdrátové přijímače) | externí zařízení, CD přehrávač |
| FM rádio | `FM Rádio` | Přímý přenos z připojeného FM přijímače | relace rádia, nouzové vysílání státu |
| Control Box | `Control box` | Hardwarové tlačítko/mikrofon připojený k ústředně | klasické obecní hlášení z pultu |

Každá karta obsahuje stručné shrnutí a název zdroje. Podrobnosti o mapování na vstupy ústředny jsou uvedeny ve spodní části stránky (tabulka „Aktivní vstup“).

### 5.2 Postup zahájení
1. Vyberte lokality/hnízda (musí být alespoň jedna položka). Vícenásobný výběr provedete podržením `Ctrl/Cmd`.
2. Zvolte zdroj a připravte vstup (povolení mikrofonu v prohlížeči, výběr souboru, kontrola FM frekvence apod.). Systém upozorní, pokud chybí povolení mikrofonu či soubor.
3. Nastavte hlasitost – aktivní vstup vidíte v panelu „Hlasitost vstupu“; rozsah slideru odpovídá úrovním mixu (−12 dB až +59,5 dB).
4. Klikněte na **Spustit vysílání**. Pokud je aktivní jiný zdroj, nejprve jej vlastní akcí zastavte.
5. Sledujte stav v horní části obrazovky (průběh, zbývající čas, hlasitost). Informace o běžícím vysílání jsou dostupné v protokolu.

### 5.3 Práce během vysílání
- Zdroj můžete kdykoliv přepnout – po změně nezapomeňte upravit hlasitost v panelu s aktivním vstupem.
- Hlasitost upravíte sliderem nebo zadáním hodnoty do pole. Úroveň se okamžitě uloží pro běžící vstup.
- Tlačítko **Zastavit** ukončí vysílání ihned bez dalšího potvrzení.
- Pokud je aktivován poplach JSVV, vysílání se pozastaví. Po jeho skončení jej můžete znovu spustit.

### 5.4 Přímé hlášení z mobilu
- Volající z whitelistu (viz GSM) může spustit hlášení.
- Pokud je nastaven PIN, systém ho vyžaduje.
- Aktuální přehrávání nižší priority se ukončí.

### 5.5 Control Box
- Fyzické tlačítko pro mikrofon / poplach.
- Požaduje jedinečný PIN (konfigurovaný v Uživatelích).

---

## 6. Modul Záznam

### 6.1 Pořízení nahrávky
1. Přejděte na **Záznam**.
2. V seznamu zdrojů vyberte, odkud chcete audio pořizovat (mikrofon, WebRTC vstup, vybraný analogový kanál apod.).
3. Stiskněte **Spustit nahrávání**.
4. Po dokončení **Ukončit nahrávání** – soubor se uloží a zobrazí v seznamu.

### 6.2 Nastavení nahrávky
- **Hlasitost kanálu** – slider přímo ovládá odpovídající vstup na ALSA mixu (rozsah −12 dB až +59,5 dB, krok 0,5 dB). Nastavení se pamatuje pro konkrétní zdroj a znovu se použije při dalším nahrávání i vysílání.
- **Poznámka** – text uložený v metadatech, lze jej později zobrazit v seznamu nahrávek a při sestavování playlistů.
- **Náhled / Přehrát** – tlačítko „Přehrát“ využívá WebAudio; audio je přehráno z paměti, aniž by se odesílalo do ústředny.
- **Monitor** – pokud máte připojená sluchátka, zapněte monitorování, abyste slyšeli, co se skutečně nahrává. Zabráníte tím klipování nebo prázdným záznamům.
- **Předzáznam** – při aktivaci (v nastavení) se automaticky uloží i 3 sekundy před stisknutím tlačítka. Hodí se pro zachycení spontánních hlášení.

### 6.3 Uložení a typy nahrávek
Při ukládání záznamu (dialog „Uložit nahrávku“):

| Typ | Použití |
| --- | --- |
| **COMMON** (Běžné hlášení) | Krátké oznamy do playlistu nebo poplachu | 
| **OPENING / CLOSING** | Úvodní / závěrečná slova pořadu |
| **INTRO / OUTRO** | Znělky a signály |
| **OTHER** | Specifické materiály mimo standardní kategorizaci |

Metadata obsahují délku, zdroj, poznámku i datum pořízení – díky tomu lze nahrávky filtrovat.

### 6.4 Správa nahrávek
- Seznam zobrazuje název, typ, délku, datum, velikost.
- K dispozici je vyhledávání, třídění i stránkování (5/10/25/50 záznamů).
- Přehrávání v seznamu probíhá pomocí blobu staženého z API `records/{id}/get-blob`.
- Přejmenování/mazání využívá potvrzovací dialogy; po akci je seznam obnoven.
- Sloupce v tabulce zobrazují všechny dostupné parametry (typ, délku, velikost); doplňující informace můžete dopsat do pole „Poznámka“ před uložením nahrávky.

> **Doporučení:** Před začleněním nové nahrávky do playlistu ji vždy přehrajte a zkontrolujte úroveň hlasitosti. Pokud je úroveň nízká, lze nahrávku znovu vytvořit se zvýšenou hlasitostí nebo použít externí editor.

---

## 7. Modul Poplachy JSVV

### 7.0 Jak backend přehrává poplachy
- Každé spuštění poplachu vytvoří sekvenci (`jsvv/sequences`) a zařadí ji do fronty. Pokud běží jiný poplach, požadavek čeká na dokončení podle priority.
- Výchozí režim `JSVV_SEQUENCE_MODE=local_stream` přehrává sekvenci přímo přes ústřednu – systém přepne mix na zdroj „JSVV“ a ostatní vysílání dočasně pozastaví.
- Alternativní režim `JSVV_SEQUENCE_MODE=remote_trigger` pouze odešle rámce JSVV do přijímačů. Backend zároveň čeká na dohrání sekvence (využívá odhad délky) a až poté uvolní plánované playlisty a další zdroje.
- Každé spuštění/ukončení je zaznamenáno do `stream_telemetry_entries` (typ `jsvv_sequence_started|completed|failed`) i do Laravel logu. Logy obsahují režim, odhadovanou a skutečnou délku a případné chyby.

### 7.1 Rychlá tlačítka
- V horní části je sada osmi tlačítek s připravenými poplachy (viz tabulka níže). Každé tlačítko zobrazuje název, zkonfigurovanou sekvenci (např. `28A9`) a slovní popis jednotlivých kroků.
- Pokud ještě neproběhlo ruční nastavení, aplikace použije výchozí sekvenci (fallback). Tím je zajištěno, že poplach lze spustit i na čistém systému.
- Tlačítko **Nastavit** otevře editor konkrétního poplachu (v modulu Nastavení JSVV). Spuštění poplachu vyžaduje potvrzení dialogem.

| Tlačítko | Sekvence | Průběh |
| --- | --- | --- |
| 1 Zkouška sirén | `28A9` | Trvalý tón → Gong 1 → Zkouška sirén → Gong 2 |
| 2 Všeobecná výstraha | `18B9` | Kolísavý tón → Gong 1 → Všeobecná výstraha → Gong 2 |
| 3 Požární poplach | `48G9` | Požární poplach → Gong 1 → Požární poplach → Gong 2 |
| 4 Zátopová vlna | `18C9` | Kolísavý tón → Gong 1 → Zátopová vlna → Gong 2 |
| 5 Chemická havárie | `18D9` | Kolísavý tón → Gong 1 → Chemická havárie → Gong 2 |
| 6 Radiační poplach | `18E9` | Kolísavý tón → Gong 1 → Radiační havárie → Gong 2 |
| 7 Konec poplachu | `8F9` | Gong 1 → Konec poplachu → Gong 2 |
| 8 Mikrofon | `8M` | Gong 1 → Mikrofon |

#### 7.1.1 Detailní popis jednotlivých tlačítek
- **1 Zkouška sirén** – používá se při pravidelných měsíčních zkouškách. V kombinaci s SMS notifikací může informovat občany, že jde pouze o test. Doporučuje se spouštět mimo špičku (např. 12:00 první středa v měsíci).
- **2 Všeobecná výstraha** – upozorňuje na obecné ohrožení obyvatelstva (povodně, silný vítr, průmyslová havárie). Před spuštěním je vhodné připravit navazující živé hlášení s konkrétními pokyny.
- **3 Požární poplach** – aktivuje sirénu s požární smyčkou, často používanou pro svolání hasičů. Po druhém přehrání „Požární poplach“ následuje gong pro jasné ukončení.
- **4 Zátopová vlna** – varuje před přicházející vodou. Pokud má obec více záplavových pásem, zvažte vytvoření varianty s omezeným cílením na konkrétní lokality.
- **5 Chemická havárie** – informuje o úniku nebezpečné látky. Doporučeno kombinovat s e-mailem obsahujícím směr větru a instrukce uzavření budov.
- **6 Radiační poplach** – využívá se při ohrožení radioaktivním únikem. Navazující hlášení by mělo obsahovat pokyny k ukrytí, případně odkaz na krizové weby.
- **7 Konec poplachu** – informuje o ukončení mimořádné situace; znovu uklidňuje obyvatelstvo.
- **8 Mikrofon** – otevírá kanál pro živé hlášení. Před spuštěním je vhodné připravit si osnovu sdělení. Po ukončení je doporučeno použít tlačítko „Konec poplachu“ nebo další informaci z playlistu.

### 7.2 Vlastní poplach
- Kliknutím na tlačítko **Vlastní poplach** se otevře editor sekvence. Ten obsahuje katalog zvuků seřazený do čtyř kategorií: **Sirény** (Kolísavý tón, Trvalý tón, Rezerva, Požární poplach), **Gongy** (Gong č. 1, Gong č. 2), **Verbální informace** (Zkouška sirén, Všeobecná výstraha, …, Proběhne zkouška (R)) a **Audiovstupy** (Externí audio, Externí modulace, Rezerva 1, Rezerva 3, Mikrofon, Ticho pro P/Q/R/S/T). Obsah vychází z konfigurace `jsvv-alarms/audios`.
- Po kliknutí na zvuk se položka přidá do sekvence v pravém panelu. Pořadí lze měnit tlačítky se šipkami, položky lze mazat, případně celé sestavení vyčistit.
- Tlačítko **Odeslat vlastní poplach** odešle sestavu přímo do ústředny (přes `jsvv/sequences` + `trigger`). Po odeslání se seznam vyprázdní, editor však zůstává otevřený pro případné další hlášení.
- Tlačítko **Uložit jako předvolbu** otevře informační toast s připomenutím, že sekvenci lze uložit v Nastavení JSVV. Z editoru je možné odejít tlačítkem **Zavřít** – při zavření se aktuální výběr smaže.

> **Pozor:** JSVV používá pro symboly ASCII znaky `1-9, A-Y`. Při zadávání sekvencí ručně vždy používejte velká písmena bez mezer.

### 7.3 Spuštění poplachu
1. Vyberte příslušné tlačítko nebo otevřete vlastní sekvenci.
2. Klikněte na **Spustit poplach**. Zobrazí se potvrzovací dialog s přehledem kroků.
3. Potvrďte akci – během několika vteřin se stav v horním panelu změní na „Odesílání“.
4. Po přijetí potvrzení z ústředny se zobrazí notifikace **Alarm byl odeslán do ústředny**. V opačném případě aplikace nahlásí chybu a nabídne opakování.
5. Během poplachu je karta zablokována pro další spouštění, dokud Orchestrátor nepotvrdí dokončení. Pokus o nový poplach zobrazí zbývající čas.

### 7.4 Monitoring a protokol
- Historii poplachů najdete v modulu **Protokol JSVV** (tlačítko v horní liště karty). Každý záznam obsahuje čas, operátora, výsledek a případnou chybovou hlášku.
- Pokud je aktivní GSM/e-mail notifikace, v protokolu se objeví také informace o jejich odeslání.

### 7.5 Nejčastější chyby a řešení
| Kód | Popis | Doporučený postup |
|-----|-------|-------------------|
| `sequence.validation_failed` | Sekvence obsahuje neplatný symbol | Zkontrolujte, zda nově přidané audio skutečně existuje v katalogu a má symbol `1-9` nebo `A-Y`. |
| `orchestrator.busy` | Orchestrátor právě zpracovává jiný poplach | Vyčkejte na dokončení aktuální sekvence; v protokolu ověřte, kdo poplach spustil a jak dlouho běží. |
| `transport.timeout` | Ústředna nepotvrdila přijetí | Ověřte konektivitu mezi webovou aplikací a ústřednou. Pokud problém trvá, kontaktujte servis – odeslání se opakovat automaticky nebude. |

### 7.6 Jak správně testovat poplachy
1. V modulu **Nastavení JSVV** zkontrolujte, že je cílová lokalita nastavena na testovací skupinu (např. „Ústředna“).
2. Vypněte všechny notifikační kanály (SMS/E-mail), aby nedošlo k záměně se skutečným poplachem.
3. Spusťte poplach mimo oficiální dobu testů a sledujte status v protokolu JSVV. Pokud se zobrazí chyba, zaznamenejte její kód.
4. Po dokončení obnovte původní nastavení lokalit a notifikací. V protokolu přidejte poznámku „Test“, aby bylo zřejmé, že šlo o plánovanou kontrolu.

### 7.7 Nejlepší praxe při komunikaci s veřejností
- **Před poplachem** připravte stručný scénář – např. „Varování → živé hlášení → instrukce k jednání → potvrzení“. Vyhnete se improvizaci a zbytečnému prodlení.
- **Během poplachu** pravidelně informujte o tom, co se děje (např. „Hasiči jsou na místě, zůstaňte uvnitř“). Dlouhé ticho budí paniku.
- **Po poplachu** vždy odešlete zprávu o ukončení. Občané získají jistotu, že mimořádná situace skončila.
- **Interně** veďte protokol o tom, kdo poplach spustil, kdy, na jaké lokality a s jakým výsledkem. Usnadní to pozdější analýzu a případné revize postupů.
- **Control Tab:** Pokud je k ústředně připojen ovládací panel, má stejné priority jako ručně spuštěné poplachy a prochází stejnou frontou. Tlačítka definovaná správcem (např. `Spusť vysílání`, `Zkouška sirén`, `Stop`) odesílají požadavky do systému, který je zpracuje podle aktuálního stavu. Stav fronty se promítá do notifikací na panelu (např. „Poplach byl zařazen do fronty.“). 
- **Současné požadavky:** Systém nyní dokáže přijímat více poptávek za sebou – JSVV, Control Tab, GSM i plánovaná hlášení. Prioritu má JSVV, poté Control Tab / GSM, a nakonec playlisty. Přímé vysílání z webu se odmítne s hláškou, pokud již běží poplach JSVV.

---

## 8. Modul Nastavení JSVV

### 8.0 Klíčové konfigurační parametry
- **Režim přehrávání (`JSVV_SEQUENCE_MODE`)** – `local_stream` (výchozí) nebo `remote_trigger`. Změna probíhá v `.env`; po úpravě restartujte supervisorem spravované procesy (queue, `alarms:monitor`).
- **Výchozí délky (`JSVV_DEFAULT_*`)** – hodnoty pro odhad verbálních hlášení, sirén i fallbacku. Backend je používá při plánování sekvence i při čekání v režimu remote trigger.
- **Cache délek (`JSVV_DURATION_CACHE_SECONDS`)** – interval, po který se uloží změřené délky audio souborů.
- **GoSMS přístup (`SMS_GOSMS_*`)** – Client ID/Secret, kanál a volitelný sender. Bez platných údajů se SMS neodešlou.

### 8.1 Tlačítka (desktop, mobil)
- Každé tlačítko má pole pro sekvenci a volbu zvuků. Při otevření se načtou uložené hodnoty, případně fallback. Vedle pole je vždy zobrazen náhled slovního popisu.
- Builder sekvencí (stejný jako v modulu Poplachy) umožňuje vizuální výběr symbolů. Počet kroků je omezen na čtyři; pokud přidáte více položek, systém zobrazí upozornění.
- Při uložení jsou symboly normalizovány na velká písmena. Sekvence se ukládají pomocí `jsvv-alarms` API. Po úspěchu se tlačítko zvýrazní zeleně a objeví se čas poslední úpravy.
- Mobilní tlačítka 0 a 9 lze konfigurovat nezávisle (např. pro lokální upozornění). Pro běžnou osmičku (1–8) lze zvolit, zda mají sdílet stejnou sekvenci jako stolní tlačítko – volba je v detailu řádku.
- Defaultní kombinace (viz tabulka v kapitole 7.1) se automaticky předvyplní při výběru tlačítek 1–8. Pokud operátor sekvenci nezmění, uloží se právě tyto hodnoty pro stolní i mobilní verzi. V případě nového poplachu se číslo mobilního tlačítka synchronně nastaví podle zvoleného stolního tlačítka a naopak; ruční změna tuto vazbu přepíše.
- Po kliknutí na „Vybrat sekvenci“ se otevře modální okno – lze ho bezpečně zavřít bez uložení, dokud nepotvrdíte tlačítko **Uložit sekvenci**.

### 8.2 Zvukové banky
- Přehled symbolů `1-9, A-Y`. U každého lze přepnout zdroj:
  - **Source** – přímý vstup (Vstup 1–8, FM, Mikrofon).
  - **File** – zvukový soubor (vybírá se z modálního okna, lze přehrát).
- Po změně je nutné kliknout na „Uložit zvuky“, aby se aktualizace propsala do databáze.
- Při volbě souboru se vypisuje délka audio stopy. Pokud je delší než doporučených 30 s, aplikace zobrazí upozornění.
- Pokud délku souboru nelze zjistit, backend použije výchozí hodnoty definované v `.env` (verbální 12 s, siréna 60 s, fallback 10 s); po doplnění souboru se délka přepočítá a uloží do metadat.
- Náhradní položky (Rezerva 1/3, Ticho pro P–T) lze ponechat bez souboru – slouží jako placeholdery pro budoucí integrace.
- V případě potřeby lze přidat nouzový zvuk – např. znělku místního rozhlasu. Dočasně ho přiřaďte k symbolu Rezerva 3 a aktualizaci oznámte všem operátorům.

### 8.3 Lokality a notifikace
- **Lokalita JSVV** – vyberte skupinu (např. General, centrum, okraj obce). Určuje primární cílovou oblast při spuštění poplachu.
- **SMS / E-mail** – povolením se aktivuje sekce s výběrem kontaktů, textem zprávy atd. Systém validuje formát čísel a e-mailů.
- Doporučuje se mít připravené texty pro různé scénáře (výstraha, všeobecná informace), aby operátor nemusel při poplachu nic dopisovat.
- Pro strategii „nejprve SMS, poté e-mail“ použijte workflow: po spuštění poplachu odešlete příslušný kanál ručně ve stejné sekci (odeslání se provádí až při spuštění poplachu).

### 8.4 Doporučený postup pro aktualizaci tlačítek
1. Zkontrolujte, zda máte k dispozici aktuální sadu zvuků (v sekci *Zvukové banky*).
2. Upravte nejprve sekvenci pro stolní tlačítko, až následně mobilní variantu – snadno tak rozpoznáte sdílené symboly.
3. Odsouhlaste změny s kolegy (doporučené dvoučlenné pravidlo) a poté klikněte na **Uložit tlačítka**.
4. Ověřte funkčnost pomocí testovacího vysílání nebo spuštěním poplachu v režimu „Zkušební“ (pokud je v ústředně aktivní).

### 8.5 Check-list před nasazením do ostrého provozu
- [ ] Jsou definovány všechny sekvence 1–8 včetně mobilních variant?
- [ ] Jsou přiřazeny správné lokality a testovací skupina je přepnuta zpět na „General“?
- [ ] Jsou notifikační kanály otestovány (SMS i e-mail) a mají aktuální kontakty?
- [ ] Byla provedena zkouška vlastního poplachu a zaznamenán výsledek do protokolu?
- [ ] Je zajištěno, že fallback sekvence odpovídají aktuálním požadavkům krizového plánu obce?

---

## 9. Modul Plánování (Plan vysílání)

### 9.1 Vytvoření úlohy
1. V menu vyberte **Plán vysílání** a klikněte na „Nový úkol“.
2. Zadejte název, termín a vyberte nahrávky (Úvod, Hlášení, Závěr).
3. Rozhodněte, zda má být hlášení opakováno – viz níže.
4. Uložte; systém provede kontrolu kolizí a uloží délku + metadata.

### 9.2 Opakování
- Po zaškrtnutí „Opakovat hlášení“ se zobrazí nastavení: počet opakování, typ intervalu, hodnota intervalu.
- Dostupné typy: `Minuty`, `Hodiny`, `Dny`, `Vybraný den v týdnu`, `Měsíce`, `První daný den v měsíci`, `Roky`.
- Při volbě týdenního opakování je nutné vybrat konkrétní den (Po–Ne). Systém si hodnotu uloží do `repeat_interval_meta`.
- Celková délka úlohy se recalculuje v reálném čase (zahrnuje i intervaly).

### 9.3 Úprava a mazání
- Kliknutím na úlohu v seznamu lze provést úpravy; po uložení se aktualizují metadata.
- Mazání vyžaduje potvrzení dialogem; záznam se označí jako smazaný (soft delete) kvůli auditům.

---

## 10. Mapa a hnízda

### 10.1 Hnízdo – evidované informace
- Název, typ (Centrála/Hnízdo), GPS pozice.
- Adresy: Modbus, Adresa obousměru (A16), Privátní adresa přijímače (A16).
- Status (`OK`, `WARNING`, `ERROR`, `UNKNOWN`) – vizualizace barvou na mapě.
- Součásti: Přijímač, Nabíječ, Obousměr, Ekotechnika, Proudová smyčka, BAT+REP Test, Digitální interface, Digitální obousměr.
- Přiřazené lokality – hnízdo může být součástí více lokalit (např. centrum + záložní oblast).

### 10.2 Operace v mapě
- **Přidat místo** – vytvoří nové hnízdo s výchozími souřadnicemi ve středu mapy.
- **Upravit rozmístění** – přepne mapu do režimu drag & drop; po dokončení klikněte na „Uložit“.
- Popup zobrazuje název, stav a součásti; kliknutím se v seznamu filtruje dané hnízdo.

### 10.3 Seznam hnízd
- Obsahuje všechny výše uvedené informace v tabulce.
- Lze řadit podle názvu, typu, adres, stavu.
- Sloupec „Součásti“ vypisuje české názvy (např. „Přijímač, Obousměr“).
- Sloupec „Další lokality“ zobrazuje všechny přiřazené skupiny.

---

## 11. Control Tab (externí ovládací panel)

### 11.1 Přehled
- Control Tab je hardwarový panel připojený přes UART, který zobrazuje stav ústředny a umožňuje spouštět akce (přímé vysílání, poplachy JSVV, zastavení).
- Panel komunikuje s backendem pomocí protokolu popsaného v `docs/requirements_docs/Protokol pro komunikaci za pomoci Control Tabu.md`. Backend nyní dokáže zprávy přijmout, vyhodnotit a odpovědět na ně.
- Každé stisknuté tlačítko je přeloženo na konkrétní akci podle konfigurace: nastavitelné v `config/control_tab.php` (mapování tlačítek a textových polí).

### 11.2 Priorita a fronta
- Požadavky z panelu se řadí stejně jako ostatní zdroje. Probíhající poplach JSVV má nejvyšší prioritu – pokud je aktivní, Control Tab dostane odpověď „vysílání nelze spustit“.
- Při spouštění poplachu Control Tab obdrží informaci, zda byl poplach ihned spuštěn, nebo zařazen do fronty (např. když už běží jiný poplach).
- Zastavení poplachu (tlačítko Stop) ukončí pouze aktuální JSVV sekvenci. Pokud běží živé vysílání z jiného zdroje, systém jej musí řešit samostatně.

### 11.3 Zobrazovaná data
- Textová pole panelu jsou generována backendem – např. stav ústředny, délka aktuálního hlášení, vybraná znělka.
- Aktualizace probíhá na vyžádání panelu (`text_field_request`) i formou push zpráv (např. změna stavu poplachu).
- Pokud Control Tab hlásí chybu CRC, backend odešle zprávu znovu; log se nachází v `storage/logs/control-tab.log` (pokud je zapnuto).

### 11.4 Rekonfigurace tlačítek
- Cestu `config/control_tab.php` lze upravit, např. pro mapování tlačítka „Zkouška sirén“ na jiné tlačítko JSVV.
- Po změně konfigurace je nutné restartovat aplikaci nebo vyprázdnit cache (`php artisan config:clear`).
- Konfigurace je oddělena pro budoucí podporu více panelů. Pokud nejsou některé položky vyplněny, použijí se výchozí hodnoty (ruce definované v `defaults`).

---

## 12. Doporučené kroky při krizové situaci
1. **Ověřte poplach** – v modulu Poplachy JSVV zkontrolujte, zda jsou tlačítka připravena (sekvence). Pokud ne, otevřete Nastavení JSVV.
2. **Aktivujte poplach** – vyberte odpovídající tlačítko (např. „2 Všeobecná výstraha“) a potvrďte dialog.
3. **Doplňte informace** – spusťte živé hlášení (mikrofon) nebo naplánujte další úlohu pro opakování informací.
4. **Sledujte protokol** – v modulech Protokoly/Historie získáte přehled, kdy a kdo poplach spustil.
5. **Vyhodnoťte HW** – na mapě zkontrolujte barvy hnízd; pokud některé hlásí chybu, kontaktujte servis.

---

## 13. Technické poznámky

### 13.1 Audio směrování
- Systém používá ALSA mixer. Každý zdroj (mikrofon, playlist, FM…) má vlastní kanál (`input_1`, `file_playback`, `pc_webrtc`, `fm_radio`, `control_box` atd.).
- Změna hlasitosti u jednoho zdroje se nepromítá do ostatních.
- Při restartu aplikace se poslední hodnoty načtou z LocalStorage a hned aplikují.

### 13.2 API kanály
- `jsvv-alarms/all`, `jsvv-alarms/audios` – konfigurace tlačítek a zvuků.
- `jsvv/sequences` + `trigger` – naplánování a spuštění poplachové sekvence.
- `control-tab/events` – rozhraní pro fyzický Control Tab (panel_loaded, button_pressed, text_field_request).
- `locations/list` / `save` – práce s hnízdy (souřadnice, součásti, status).
- `schedules/*` – plán vysílání (včetně kontroly kolizí a updatu).
- `python-client/modbus_control.py read-alarms` – diagnostika alarmového LIFO bufferu (0x3000–0x3009).

### 13.3 Odolnost a fallbacky
- Pokud není sekvence tlačítka uložena, modul Poplachy použije výchozí kombinaci (viz tabulka). Tato logika je v UI i backendu, takže poplach lze vyvolat i hned po instalaci.
- LIFO buffer alarmů (registr 0x3000–0x3009) uchovává poslední přijaté hlášení z hnízd; po načtení se automaticky posune k dalšímu záznamu. Ústředna jej průběžně čte a případně nuluje.
- V případě výpadku WebRTC lze použít „Soubor v ústředně“ nebo „Control Box“ (přes hardware).
- Opakování v Plánu je zatím ukládáno, ale reálné spouštění více intervalů vyžaduje rozšíření backendu (plánovaný vývoj).

---

> **Často kladené otázky**
- *Mohu spustit poplach, když není definován playlist?* – Ano, poplach používá sekvence symbolů. Playlist je spojen jen s modulem Plán a přímým vysíláním.
- *Co když chci poplach s vlastní kombinací (např. dvě sirény, pak hlášení)?* – Sestavte sekvenci ve Vlastním poplachu a odešlete, případně ji uložte v Nastavení jako nové tlačítko.
- *Jak poznám, že hnízdo neodpovídá?* – Na mapě bude modré (UNKNOWN) nebo červené (ERROR); zároveň se objeví upozornění v přehledu.

---

## 14. Info, Aktivní alarmy, Protokoly, O aplikaci

### 14.1 Info
- Kontakty na podporu, verze firmware.
- Statistiky: počet hlášení, poplachů, uptime.

### 14.2 Aktivní alarmy
- Realtime seznam, možnost potvrdit/odmítat.
- Prioritizace a odkaz na Detail.

### 14.3 Protokoly
- Kompletní audit log (CRUD operace, hlášení, přihlášení).
- Export CSV/JSON.

### 14.4 O aplikaci
- Informace o licenci, autoři, datum vydání.
- Odkaz na dokumentaci a aktualizace.

---

## 15. Mapa

### 15.1 Zobrazení
- Podpora více vrstev (OpenStreetMap, satelitní mapy).
- Barevné indikátory podle stavu hnízda/senzoru.

### 15.2 Editace
- Přetažením změnit polohu.
- Formulář s detailními údaji (adresa, lokace, poznámka).

### 15.3 Filtrace
- Podle lokality, skupiny, stavu (online/offline).

---

## 16. Nastavení

### 16.1 Uživatelé
- Přidat, upravit, mazat.
- Nastavit role (Operátor, Administrátor, Servisní technik).
- Reset hesla, vynucené změny při prvním přihlášení.
- PIN pro Control Box (unikátní).
- Audit: poslední přihlášení, IP adresa.

### 16.2 GSM
- Whitelist telefonních čísel (režimy přístupu: pouze historie, přímé hlášení).
- SIM PIN, USSD příkaz pro kontrolu kreditu.
- Hlasový automat: přiřazení lokalit, textů, zpoždění.

### 16.3 Další servisní nastavení
- Parametry ústředny (tóny, zpoždění, diagnostika).
- Konfigurace mapy (API klíč, centrální pozice).
- Správa systémových souborů (firmware, logy).

---

## 17. Role a oprávnění

| Role | Přístup | Poznámky |
|------|---------|----------|
| Operátor | Přímé hlášení, Záznam, Historie, Zprávy, Kontakty, Mapa | Bez práv k nastavení uživatelů či GSM |
| Administrátor | Vše jako operátor + správa uživatelů, skupin, nastavení GSM | Nemůže nastavovat servisní parametry |
| Servisní technik | Kompletní přístup včetně servisních nastavení a systémových souborů | Nejvyšší oprávnění |

Individuální oprávnění (příklad):
- `direct-broadcast` – spouštěť/přerušovat hlášení.
- `schedule.manage` – vytváření/úpravy plánovaných úkolů.
- `history.view` – přístup k Historii hlášení.
- `messages.send` – odesílání SMS/e-mailů.
- `gsm.manage` – konfigurace GSM, whitelist, hlasový automat.

---

## 18. Přílohy a integrace

### 18.1 FTP export Historie
- Formát XML `CentralInfo.xml` obsahuje `<Broadcast>` elementy s atributy ID, typ, datum, čas, seznam `<File>`.
- Automatický upload na FTP server (k dispozici scheduler/cron job).

### 18.2 Interakce s daemony
- `gsm_listener.py` přijímá události SIM7600G-H, posílá POST na `/api/gsm/events`.
- `jsvv_listener.py` čte KPPS, generuje `/api/jsvv/events`.
- Queue worker (Supervisor) běží `php artisan queue:work` pro nahrávky.

### 18.3 Monitorování
- Liveness a readiness endpointy pro integraci s Prometheus/Nagios.
- Logování do `storage/logs/` + externí syslog.
- Ve složce `sims/` jsou simulační skripty (Control Tab, JSVV fronta, alarmový buffer) pro laboratorní ověření podle pokynů MV-GŘ HZS.

---

## 19. Technické poznámky a doporučení

- Při implementaci audio pipeline použijte FFmpeg/SoX pro mixing různých vstupů.
- WebRTC vyžaduje STUN/TURN, pro intranet lze použít interní STUN server.
- GSM modul vyžaduje správnou inicializaci AT příkazů (PIN, APN, textový režim SMS).
- FM přijímač (RTL2832U) vyžaduje instalaci ovladače RTL-SDR a nastavení ALSA zařízení.

---

## 20. Údržba a troubleshooting

### 20.1 Zálohování
- Konfigurace Laravelu (soubor `.env`).
- Databáze (MariaDB/PostgreSQL) – denní dump.
- Audio soubory a logy.

### 20.2 Diagnostika
- `php artisan health:check` (pokud dostupné).
- Logy v `storage/logs/laravel.log`, `queue-worker.log`, `gsm_listener.log`.
- Monitor SMS signálu (AT příkaz `AT+CSQ`).

### 20.3 Nejčastější problémy
| Problém | Příčina | Řešení |
|---------|---------|--------|
| Nelze spustit přímé hlášení | Nevybraná lokalita, chyba WebRTC | Zkontrolujte výběr lokality, povolení mikrofonu |
| Číslo nevyvolá přímé hlášení | Není ve whitelistu, špatný PIN | Přidejte do GSM nastavení, resetujte PIN |
| FM rádio nehrajе | Chybí ALSA/RTL-SDR | Ověřte `rtl_test`, nastavení vstupu |
| Úloha se nespustila | Queue worker neaktivní | Zkontrolujte supervisor, logy queue |

---

## 20. Dokumentace a nápověda

- Tento dokument je dostupný v `docs/navody/Sarah_V_User_Manual.md` a lze exportovat do DOCX/PDF.
- V aplikaci je tlačítko **Nápověda** s odkazy na tuto dokumentaci.
- Aktualizace manuálu provádějte při každé nové verzi systému (upravte číslo verze a sekce).

---

## 21. Index klíčových pojmů

- **Control Box** – fyzický ovládací panel ústředny.
- **JSVV** – Jednotný systém varování a vyrozumění.
- **Hnízdo** – venkovní reproduktor/hlásič.
- **Playlist** – sekvence audio souborů k vysílání.
- **Whitelist** – seznam čísel oprávněných k přístupu přes GSM.
- **WebRTC** – technologie pro přenos audio/video v prohlížeči.

---

*Poznámka:* Tato rozšířená dokumentace je určena jako pracovní podklad pro implementaci a testování. V případě odlišností mezi implementací a manuálem je nutné dokument aktualizovat, nebo zadat úpravy vývojovému týmu.

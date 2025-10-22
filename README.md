# Bezdratový rozhlas

## Backend services

- For API endpoints see `docs/api.md`.
- Daemon management guide: `docs/daemons.md`.
- Supervisor configs: `supervisor/*.conf` for GSM/JSVV listeners and queue workers.
- Seeder `BroadcastSeeder` seeds demo broadcast/JSVV/GSM data; run `php artisan migrate --seed`.

## Instalace

1. SHELL: composer install
2. SHELL: npm install
3. SHELL: cp .env.example .env
4. Pro povolení přístupu k mikrofonu a reproduktorům v prohlížeči je potřeba použít HTTPS. Na localhostu je nutné upravit nastavení prohlížeče:
    1. **Chrome**: chrome://flags/#unsafely-treat-insecure-origin-as-secure
    2. **Firefox**: about:config -> media.getusermedia.insecure.enabled
    3. **Edge**: edge://flags/#unsafely-treat-insecure-origin-as-secure
    4. Do pole s výjimkami přidat: http://rozhlas.lan:8000

## Spuštění

1. SHELL: php artisan serve --host=rozhlas.lan
2. SHELL: vite
3. Otevřít v prohlížeči: http://rozhlas.lan:8000
4. Přihlásit se jako admin (výchozí uživatel: admin, heslo: admin)
5. Prohlížeč může vyžadovat povolení přístupu k mikrofonu a reproduktorům - povolit

## Popis funkcí

### Živé vysílání

- Tlačítkem "Zahájit vysílání" se spustí živé vysílání
- Toto tlačítko se změní na "Zastavit vysílání", kterým se živé vysílání ukončí

### Záznamy

#### Sekce Nahrání záznamu

- Tlačítkem "Nový záznam" se spustí nahrávání záznamu z mikrofonu
    - Tlačítko se změní na "Zastavit záznam", po kliknutí se nahrávání zastaví
    - Následně je možné nahrávku přehrát pomoci tlačítka "Přehrát" a uložit tlačítkem "Uložit"
        - Při ukládání je možné zvolit název záznamu a typ nahrávky (Běžné hlášení, Úvodní slovo, Znělka, ...)
    - Pro uložení záznamu je možné zvolit kodek (dle aktuálního HW a SW ústředny) a možnost potlačení ozvěny
- Tlačítkem "Nahrát soubor" je možné nahrát záznam z počítače
    - Podporované formáty: mp3, wav, ogg
- Záznam se poté vloží do seznamu nahrávek

#### Sekce Seznam nahrávek

- Obsahuje veškeré náhrávky, které je mořné filtrovat dle typu (Běžné hlášení, Úvodní slovo, Znělka, ...) popřípadě vyhledávat dle názvu
- U každé nahrávky je možné:
    - upravit název nahrávky
    - přehrát nahrávku
    - smazat nahrávku

### Plán vysílání

#### Sekce Naplánované úkoly

- Tlačítkem "+" je možné vytvořit nový naplánovaný úkol
    - V sekci Obecné informace je možné zvolit název úkolu, datum a čas spuštění, zda se bude hlášení opakovat
    - Při zadávání úkolu je na pozadí hlídáno, aby nedošlo k časové kolizi s jiným úkolem
    - V sekci Zvukové soubory je možné přidat jednotlivé nahrávky, které se při spuštění úkolu přehrají
        - Nahrávky je možné přidávat z již nahraných záznamů dle typu (Běžné hlášení, Úvodní slovo, Znělka, ...)
        - Po přidání nahrávky je možné nahrávku přehrát nebo smazat
        - V záhlaví sekce se zobrazuje celkový čas přehrávaných nahrávek

#### Sekce Archivované úkoly

- Jakmile je naplánovaný úkol spuštěn, přesune se do této sekce
- Je mořné nahlédnout do detailu úkolu, kde je možné vidět čas spuštění a seznam přehrávaných nahrávek

### Poplach JSVV

- Obsahuje neměnný seznam poplachů JSVV (Jednotný systém varování a vyrozumění)
- Každou z položek na seznamu je možné upravit
    - Název poplachu
    - Tlačítko pro spuštění poplachu (text na tlačítku)
    - Mobilní tlačítko pro spuštění poplachu (text na tlačítku)
    - Sekvenci JSVV poplachu
        - Nahrávky pro sekvenci jsou definované v Nastavení -> JSVV

### Zprávy

- Seznam odeslaných zpráv (SMS, e-mail)
- Lze filtrovat dle kanálu (SMS, e-mail) a dle stavu doručení (odesláno, přijato, chyba)
- Lze vyhledávat dle textu zprávy nebo jména kontaktu
- Tlačítkem "+" je možné vytvořit novou zprávu
    - U této zprávy se volí konkrétní kontakty nebo skupiny kontaktů, kterým bude zpráva odeslána
    - Kanál na který bude zpráva odeslána (SMS, e-mail) konkrétním kontaktům je definován v Nastavení -> Kontakty
    - Do textového pole "Text zprávy" se napíše text zprávy
    - Tlačítkem "Odeslat" se zpráva odešle

### Mapa

#### Sekce Přehled míst

- Zobrazuje centrály a hnízda na mapě
- Tlačítkem "Přidat místo" je možné přidat nové místo
    - Pro nové místo se definuje název, typ (centrála, hnízdo), a lokace (lokace jsou definovány v Nastavení -> Lokality)
    - Kliknutím na tlačítko "Nastavit pozici" dojde k přidání značky na mapu, kterou je možné přetáhnout na požadované místo
    - Po přesunutí na zvolenou pozici je potřeba kliknout na tlačítko "Uložit"
    - Před uložením je možné přidat další místa kliknutím na tlačítko "Přidat místo"
- Kliknutím na značku místa na mapě dojde k jejímu vyfiltrování v sekci Seznam míst
- Kliknutím na tlačítko "Upravit rozmístění" se mapa přepne do režimu úprav, kde je možné přetáhnout značky míst na nové pozice
    - Je zde možné přidat nové místo kliknutím na tlačítko "Přidat místo"
    - Po dokončení úprav je potřeba kliknout na tlačítko "Uložit"

#### Sekce Seznam míst

- Obsahuje seznam všech míst viditelných na mapě
- Je možné vyhledávat dle názvu místa
- Kliknutím na značku "Zamířit na mapu" dojde k vycentrování mapy na dané místo
- Kliknutím na ikonu "Upravit" je možné upravit název, typ a lokaci místa
- Kliknutím na ikonu "Smazat" je možné místo smazat

### Protokoly

- Obsahuje protokoly událostí aplikace
- Zaznamenává se přihlášení uživatelů, spuštění a zastavení vysílání, vytvoření, úprava a smazání záznamů, naplánovaných úkolů, míst na mapě, uživatelů a nastavení aplikace
- K záznamům je příložen IP adresa a uživatel, který akci provedl

### Uživatelé

- Seznam uživatelů aplikace
- Tlačítkem "+" je možné vytvořit nového uživatele
- U každého uživatele je možné:
    - upravit jméno a heslo do systému
    - smazat uživatele (nejde smazat sám sebe)

### Nastavení

####  FM rádio (hardware: MINI TRAGBARER DIGITALER USB 2.0 TV STICK DVB-T + DAB + FM RTL2832U + R820T2 UNTERSTÜTZUNG SDR TUNER RECEIVER)

- Nastavení frekvence FM rádia
- Kliknutím na tlačítko "Uložit" dojde k uložení nově nastavené frekvence

#### Kontakty

##### Sekce Seznam kontaktů

- Seznam kontaktů, kterým je možné odesílat zprávy (SMS, e-mail)
- Seznam je možné filtrovat dle skupin kontaktů
- Lze vyhledávat dle jména, příjmení, telefonu nebo e-mailu
- V seznamu je vidět konkrétní kanál (SMS, e-mail) pro odesílání zpráv danému kontaktu (kanál je označen zeleně)
- Tlačítkem "+" je možné vytvořit nový kontakt
- U každého kontaktu je možné:
    - upravit jméno, příjmení, telefon, e-mail a skupiny kontaktů
    - nastavit který kanál (SMS, e-mail) bude použit pro odesílání zpráv
    - smazat kontakt

##### Sekce Skupiny kontaktů

- Seznam skupin kontaktů, které je možné přiřadit kontaktům a kterými lze filtrovav příjemce při odesílání zpráv
- U každé skupiny je zobrazeno, kolik lidí je do skupiny přiřazeno
- Ikonkou filtrace dojde k vyfiltrování kontaktů v sekci Seznam kontaktů
- Tlačítkem "+" je možné vytvořit novou skupinu kontaktů
- U každé skupiny je možné:
    - upravit název skupiny
    - smazat skupinu (při smazání nedojde k odstranění kontaktů, pouze se kontaktům odebere přiřazení ke skupině)

#### JSVV

##### Sekce Nastavení lokality

- Nastavení lokality pro JSVV (Jednotný systém varování a vyrozumění) - lokality jsou definovány v Nastavení -> Lokality
- Tlačítkem "Uložit" dojde k uložení nově nastavené lokality

##### Sekce Nastavení zvuků

- Sekce obsahuje tabulku s přednastavenými JSVV kódy
- U každého kódu je možné:
    - Upravit název kódu
    - Přiřadit nahrávku (z již nahraných záznamů)
    - Smazat nahrávku
    - Některé kódy obsahují místo nahrávky definicu vstupu (FM rádio, mikrofon, ...)
- Tlačítkem "Uložit" dojde k uložení změn v tabulce

##### Sekce Nastavení SMS

- Umožnuje zapnout nebo vypnout upozornění na začínající poplach JSVV pomocí SMS
- Je možné nastavit text SMS zprávy a příjemce (konkrétní telefonní čísla)
- Tlačítkem "Uložit" dojde k uložení nastavení

##### Sekce Nastavení e-mailu

- Umožnuje zapnout nebo vypnout upozornění na začínající poplach JSVV pomocí e-mailu
- Je možné nastavit předmět, text e-mailu a příjemce (konkrétní e-mailové adresy)
- Tlačítkem "Uložit" dojde k uložení nastavení

#### Lokality

- Seznam lokalit používaných v aplikaci (pro JSVV, místa na mapě, ...)
- V seznamu je možné vyhledávat dle názvu lokality
- Seznam zobazuje počet míst (centrál a hnízd) přiřazených k lokalitě
- Tlačítkem "+" je možné vytvořit novou lokalitu
- U každé lokality je možné:
    - upravit název lokality
    - nastavit, že jde o skrytou lokalitu
    - nastavit typ subtónu (A16, CTCSS*, DCS)
    - nastavit subtóny
    - zvolit nahrávku pro přehrání při inicializaci / ukončování přehrávání na dané lokalitě
    - nastavit časy sepnutí a rozepnutí spínacích prvků v ms (Celková dékla vysíláním Vysílačka, Subtón,...)
    - lokalitu smazat (při smazání nedojde k odstranění míst, pouze se místům odebere přiřazení k lokalitě)

#### Obousměrná komunuikace

- Nastavení typu komunikace
    - Typ obousměru (Digitální, 80MHz, ...)
    - Nastavení nevyžádaných zpráv
- Nastavení automatické aktualizace stavu hnízd
    - povolení automatického vyčítání stavu
    - nastavení času prvního vyčítání
    - nastavení intervalu vyčítání
- Nastavení automatické aktualizace stavu senzorů
    - povolení automatického vyčítání stavu
    - nastavení času prvního vyčítání
    - nastavení intervalu vyčítání

#### SMTP

- Nastavení SMTP serveru pro odesílání e-mailů
- Umožnuje nastavit
    - adresu SMTP serveru
    - port
    - typ připojení (SSL, TLS, ...)
    - uživatelské jméno a heslo
    - e-mailovou adresu odesílatele
    - jméno odesílatele
- Tlačítkem "Uložit" dojde k uložení nastavení

#### GSM

- Nastavení GSM brány (hardware: Waveshare SIM7600G-H-PCIE) pro odesílání SMS

### O aplikaci

- Informace o verzi aplikace a ústředny, kontakt na provozovatele
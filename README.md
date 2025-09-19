# Bezdratový rozhlas

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

- Zahájení a ukončení živého vysílání

### Záznamy

- Nahrávání(upload) a tvoření(přes mikrofon) záznamů
- Přehrávání a mazání záznamů

### Plán vysílání

- Plánování úkolů přehrávání na konkrétní čas
    - U záznamů lze nastavit opakování
    - Možnost znělek, úvodních a závěrečných slov a hlášení
- Automatické přehrávání záznamů podle plánu

### Poplach JSVV

- Nastavení sekvence poplachu JSVV
- Nastavení tlačítek pro spuštění poplachu

### Zprávy

- Rozesílání textových zpráv kontaktům
- Možnost nastavit odeslání na konkrétní kontakty / skupiny

### Mapa

- Zobrazení ústředen a hnízd na mapě
- Možnost přidání vlastních ústředen a hnízd
- Možnost editace ústředen a hnízd

### Protokoly

- Záznamy z běhu systému

### Uživatelé

- Správa uživatelů s přístupem do aplikace

### Nastavení

#### FM rádio

- Nastavení FM rádia (frekvence)

#### Kontakty

- Správa kontaktů a skupin kontaktů pro rozesílání zpráv
- Nastavení způsobu odesílání zpráv (SMS, e-mail)

#### JSVV

- Nastavení sekvence poplachu JSVV
- Nastavení informačních SMS/Emailů při spuštění poplachu

#### Lokality

- Správa lokalit
- Nastavení subtónů, nahrávek při inicializaci a ukončení vysílání na lokalitě
- Nastavení časů sepnutí a rozepnutí spínacích prvků na lokalitě

#### Obousměrná komunuikace

- Nastavení typu komunikace
- Nastavení automatických aktualizací

#### SMTP

- Nastavení SMTP serveru pro odesílání e-mailů

#### GSM

- Nastavení GSM brány pro odesílání SMS

### O aplikaci

- Informace o verzi aplikace a ústředny, kontakt na provozovatele
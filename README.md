# SchoolWeb

Ein Browser, den du über deinen Webspace (FTP) anbietest. Zum Benutzen braucht man ein Konto.
Als Admin siehst du, wer ihn nutzt und was gesucht wird, und kannst Seiten sperren.

| | Windows (ZIP) | Portable | Online |
|---|---|---|---|
| Wie | entpacken, `SchoolWeb.exe` starten | `SchoolWeb-Portable.exe` doppelklicken | im Browser unter `…/online/` |
| Installation | entpacken | keine, läuft auch vom USB-Stick | keine |
| Websites | praktisch alle (Chromium) | praktisch alle (Chromium) | einfache Seiten, Google/YouTube/Spiele oft nicht |

Die Portable-Version speichert Anmeldung und Cookies im Ordner `SchoolWeb-Daten` neben der `.exe`.

## Auf den Webspace hochladen

1. Den fertigen Ordner holen: Im Repository liegt er als Ordner **`ftp-ordner/`** (alles darin hochladen). Alternativ auf GitHub unter
   **Actions → FTP-Ordner bauen** den neuesten Lauf öffnen und unten **ftp-ordner** herunterladen, oder selbst bauen (siehe unten).
2. Den **Inhalt** per FTP hochladen (z. B. mit FileZilla), etwa nach `public_html/browser/`.
3. Der Ordner `data/` muss für PHP beschreibbar sein (in FileZilla: Rechtsklick → Dateiberechtigungen → `755`, falls nötig `775`).
4. `https://deine-seite.ch/browser/` aufrufen. Beim ersten Mal legst du dort dein **Administrator-Konto** an.

```
index.php              Startseite mit Download
login.php, register.php, logout.php, setup.php
api.php                Schnittstelle für die Windows-App
admin/                 Admin-Bereich
online/                Online-Version (Proxy)
lib/                   Programmcode und config.php (gesperrt für Besucher)
data/                  Datenbank (gesperrt für Besucher)
SchoolWeb-Windows.zip  die Windows-Version (ZIP)
SchoolWeb-Portable.exe die Portable-Version
```

**Voraussetzungen:** PHP 7.4 oder neuer mit den Erweiterungen `pdo_sqlite` (oder `pdo_mysql`) und `curl`. Das haben fast alle Hoster.

## Datenbank

Standardmässig wird **SQLite** benutzt: Das braucht keine Einrichtung, die Datenbank wird automatisch in `data/` angelegt
(mit zufälligem Dateinamen und Zugriffssperre).

Für **MySQL/MariaDB** deines Hosters die Zugangsdaten in `lib/config.php` eintragen:

```php
const DB_DSN  = 'mysql:host=localhost;dbname=browser;charset=utf8mb4';
const DB_USER = 'benutzer';
const DB_PASS = 'passwort';
```

Die Tabellen werden beim ersten Aufruf automatisch erstellt.

## Admin-Bereich (`/admin/`)

- **Übersicht:** Benutzer, aktive Benutzer, Seitenaufrufe, Suchanfragen. Dazu ein Diagramm pro Tag,
  die häufigsten Suchbegriffe, die meistbesuchten Websites (mit „Sperren“-Knopf) und die aktivsten Benutzer.
  Zeitraum: heute, 7 Tage, 30 Tage, alles.
- **Suchanfragen:** Top 50, Suchmaschinen, neueste Suchen. Erkannt werden Suchen bei Google, Bing, DuckDuckGo,
  Ecosia, Yahoo, Startpage, Brave, YouTube und Wikipedia.
- **Verlauf:** alle Seitenaufrufe, filterbar nach Benutzer und Text.
- **Benutzer:** wer sich registriert hat und wann zuletzt aktiv. Pro Person: Verlauf, Suchen, Konto sperren,
  Admin-Rechte, überall abmelden, Passwort ändern, löschen. Neue Konten anlegen.
- **Gesperrte Seiten:** Domains sperren und entsperren. `example.com` sperrt auch alle Subdomains.
  Gilt sofort in der Online-Version und innerhalb einer Minute in der App.
- **Nachrichten:** Benutzer schreiben dir über „Hilfe“, du antwortest direkt.
- **Ankündigungen:** Banner für alle Benutzer in App und Online-Version, mit Ablaufdatum.
- **Suche über alles** oben im Kopf, **CSV-Export** für Verlauf, Suchanfragen und Benutzer (öffnet in Excel),
  Diagramme nach Uhrzeit und Wochentag.
- **Einstellungen:** Registrierung offen oder geschlossen, Verlauf automatisch löschen nach X Tagen, Verlauf löschen.

## Windows-App

- Eigene Startseite, Favoritenleiste (Stern oder Strg+D), Verlauf (Strg+H), Downloads mit Fortschritt (Strg+J),
  „Hilfe / Problem melden“ im Konto-Menü.

Beim ersten Start fragt die App nach **Server-Adresse**, Benutzername und Passwort. Die Server-Adresse steht auf der
Startseite und im Admin-Bereich unter „Einstellungen“ (z. B. `https://deine-seite.ch/browser`).

Die Adresse kann auch fest in die App eingebaut werden, dann muss sie niemand eintippen:

```bash
BROWSER_SERVER=https://deine-seite.ch/browser npm run build:ftp
```

Beim GitHub-Workflow geht das über die Repository-Variable `BROWSER_SERVER`
(Settings → Secrets and variables → Actions → Variables).

Ohne gültige Anmeldung lädt die App keine Seiten. Wird ein Konto gesperrt, meldet die App die Person innerhalb einer Minute ab.

## Wichtig zu wissen

- **Datenschutz:** Besuchte Seiten und Suchanfragen werden mit dem Benutzernamen gespeichert. Darauf wird bei der
  Registrierung und beim Anmelden hingewiesen. Je nach Land (z. B. Schweiz: DSG, EU: DSGVO) brauchst du zusätzlich
  eine Datenschutzerklärung. Mit „Verlauf automatisch löschen“ speicherst du nur so lange wie nötig.
- **Online-Version:** Alle Seiten laufen über deinen Server. Das kostet Datenvolumen, und manche Hoster verbieten
  solche Proxys. Interne Adressen (z. B. `localhost`, `192.168.x.x`) sind gesperrt.
- **Sperren in der App** sind eine Hürde, kein Hochsicherheits-Filter: Wer will, kann einfach einen anderen Browser benutzen.

## Selbst bauen

Voraussetzung: [Node.js](https://nodejs.org/) 18 oder neuer.

```bash
npm install
npm start            # Desktop-Browser direkt starten
npm run build:ftp    # Windows-ZIP und Portable bauen, dist/ftp/ zusammenstellen
```

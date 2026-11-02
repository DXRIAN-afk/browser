# Browser

Ein einfacher Browser, den du über deinen Webspace (FTP) allen anbieten kannst. Es gibt ihn in zwei Varianten:

| | Windows-Version | Online-Version |
|---|---|---|
| Wie | herunterladen und `Browser.exe` starten | direkt im Browser unter `deine-seite.ch/online/` |
| Engine | Chromium (wie Edge und Chrome) | Proxy über deinen Server (PHP) |
| Websites | praktisch alle | einfache Seiten gut, Google/YouTube/Logins oft nicht |

## Auf den Webspace hochladen

1. Den fertigen Ordner holen, auf eine dieser Arten:
   - auf GitHub unter **Actions → FTP-Ordner bauen** den neuesten Lauf öffnen und unten **ftp-ordner** herunterladen, oder
   - selbst bauen (siehe unten).
2. Den **Inhalt** des Ordners per FTP hochladen (z. B. mit FileZilla), etwa in `public_html/browser/`:

   ```
   index.html             Download-Seite
   style.css
   Browser-Windows.zip    die Windows-Version
   online/                die Online-Version (braucht PHP mit curl)
   ```
3. Fertig: `https://deine-seite.ch/browser/` aufrufen.

Für die Online-Version braucht der Webspace **PHP 7.4 oder neuer mit der Erweiterung curl**. Das haben fast alle Hoster.
Die Download-Seite und die Windows-Version funktionieren auch ohne PHP.

### Wichtig zur Online-Version

- Alle Seiten, die Besucher öffnen, werden **über deinen Server** geladen. Das kostet Datenvolumen,
  und für den Hoster sieht es so aus, als würde dein Server diese Seiten abrufen.
  Manche Hoster erlauben solche Proxys nicht, darum am besten kurz in deren AGB nachschauen.
- Sperrliste und Limits stehen oben in `online/proxy.php` (`BLOCKED_HOSTS`, `MAX_BYTES`, …).
- Interne Adressen (z. B. `localhost`, `192.168.x.x`) werden gesperrt, damit niemand über deinen Server in interne Netze kommt.
- Am sichersten läuft die Online-Version auf einer eigenen Subdomain (z. B. `browser.deine-seite.ch`).

## Selbst bauen

Voraussetzung: [Node.js](https://nodejs.org/) 18 oder neuer.

```bash
npm install
npm start            # Desktop-Browser direkt starten
npm run build:ftp    # Windows-ZIP bauen und dist/ftp/ zusammenstellen
```

## Funktionen

- Tabs (Strg+T, Strg+W, Mittelklick schließt)
- Adressleiste: Adresse öffnet die Seite, alles andere wird gesucht
- Zurück, Vor, Neu laden, Startseite
- Links, die ein neues Fenster öffnen wollen, werden zu neuen Tabs

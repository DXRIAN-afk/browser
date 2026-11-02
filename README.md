# Browser

Ein einfacher Desktop-Browser, gebaut mit [Electron](https://www.electronjs.org/).
Er benutzt **Chromium**, also dieselbe Engine wie Microsoft Edge und Google Chrome,
und kann deshalb praktisch alle Websites anzeigen, die auch dort funktionieren.

## Funktionen

- Tabs (neuer Tab, schließen, Mittelklick schließt)
- Adressleiste: Adresse eingeben oder einfach einen Suchbegriff (Google-Suche)
- Zurück, Vor, Neu laden/Stopp, Startseite
- Links mit `target="_blank"` / Popups öffnen sich als neuer Tab
- Tastenkürzel: `Strg+T`, `Strg+W`, `Strg+L`, `F5`/`Strg+R`, `Alt+←`, `Alt+→`

## Starten

Voraussetzung: [Node.js](https://nodejs.org/) (Version 18 oder neuer).

```bash
npm install
npm start
```

## Ideen für später

- Lesezeichen und Verlauf
- Downloads-Anzeige
- Eigene Startseite
- Als `.exe` verpacken (z. B. mit `electron-builder`)

// Trägt optional eine feste Server-Adresse in die App ein.
// Beispiel:  BROWSER_SERVER=https://deine-seite.ch/browser npm run build:ftp
const fs = require('fs');
const path = require('path');
const server = (process.env.BROWSER_SERVER || '').trim().replace(/\/+$/, '');
fs.writeFileSync(path.join(__dirname, '..', 'src', 'server.json'), JSON.stringify({ server }, null, 2) + '\n');
console.log(server ? `Server-Adresse in der App: ${server}` : 'Keine feste Server-Adresse (wird beim ersten Start abgefragt).');

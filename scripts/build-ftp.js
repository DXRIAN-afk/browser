// Stellt den fertigen Ordner zum Hochladen per FTP zusammen: dist/ftp
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const pkg = require(path.join(root, 'package.json'));
const out = path.join(root, 'dist', 'ftp');
const zip = path.join(root, 'dist', `SchoolWeb-${pkg.version}-Windows.zip`);
const portable = path.join(root, 'dist', `SchoolWeb-${pkg.version}-Portable.exe`);

if (!fs.existsSync(zip) || !fs.existsSync(portable)) {
  console.error('Windows-ZIP oder Portable-Version fehlt. Zuerst "npm run dist:win" ausführen.');
  process.exit(1);
}

fs.rmSync(out, { recursive: true, force: true });
// Lokale Testdaten (Datenbank, Schlüssel) nicht mitkopieren
fs.cpSync(path.join(root, 'web'), out, {
  recursive: true,
  filter: (src) => !/[\\/]data[\\/](secret\.php|db-.*)$/.test(src),
});
fs.copyFileSync(zip, path.join(out, 'SchoolWeb-Windows.zip'));
fs.copyFileSync(portable, path.join(out, 'SchoolWeb-Portable.exe'));

const mb = (f) => (fs.statSync(f).size / 1024 / 1024).toFixed(0) + ' MB';
const size = mb(zip);
const sizePortable = mb(portable);
const indexFile = path.join(out, 'index.php');
fs.writeFileSync(indexFile, fs.readFileSync(indexFile, 'utf8')
  .replaceAll('{{VERSION}}', pkg.version)
  .replaceAll('{{SIZE_PORTABLE}}', sizePortable)
  .replaceAll('{{SIZE}}', size));

console.log(`Fertig: ${path.relative(root, out)} (Windows-ZIP ${size}, Portable ${sizePortable})`);
console.log('Den INHALT dieses Ordners per FTP hochladen und danach die Seite im Browser öffnen (Einrichtung).');

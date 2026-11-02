// Stellt den fertigen Ordner zum Hochladen per FTP zusammen: dist/ftp
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const pkg = require(path.join(root, 'package.json'));
const out = path.join(root, 'dist', 'ftp');
const zip = path.join(root, 'dist', `Browser-${pkg.version}-Windows.zip`);

if (!fs.existsSync(zip)) {
  console.error('Windows-ZIP fehlt. Zuerst "npm run dist:win" ausführen.');
  process.exit(1);
}

fs.rmSync(out, { recursive: true, force: true });
fs.cpSync(path.join(root, 'web'), out, { recursive: true });
fs.copyFileSync(zip, path.join(out, 'Browser-Windows.zip'));

const size = (fs.statSync(zip).size / 1024 / 1024).toFixed(0) + ' MB';
const indexFile = path.join(out, 'index.html');
fs.writeFileSync(indexFile, fs.readFileSync(indexFile, 'utf8')
  .replaceAll('{{VERSION}}', pkg.version)
  .replaceAll('{{SIZE}}', size));

console.log(`Fertig: ${path.relative(root, out)} (Windows-ZIP ${size})`);
console.log('Den INHALT dieses Ordners per FTP hochladen.');

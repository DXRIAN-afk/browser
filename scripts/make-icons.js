// Erzeugt aus assets/logo.svg die Symbole (PNG + ICO). Braucht Playwright (nur einmalig nötig,
// die fertigen Dateien liegen im Repo). Aufruf: node scripts/make-icons.js
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const svg = fs.readFileSync(path.join(root, 'assets', 'logo.svg'), 'utf8');
const out = path.join(root, 'assets');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const png = {};
  for (const size of [16, 24, 32, 48, 64, 128, 256, 512]) {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(`<html><body style="margin:0;background:transparent">${svg.replace('<svg ', `<svg style="display:block;width:${size}px;height:${size}px" `)}</body></html>`);
    png[size] = await page.screenshot({ omitBackground: true });
  }
  await browser.close();

  fs.writeFileSync(path.join(out, 'icon-512.png'), png[512]);
  fs.writeFileSync(path.join(out, 'icon-256.png'), png[256]);
  fs.writeFileSync(path.join(out, 'favicon-32.png'), png[32]);
  // Kopien für die Website
  fs.copyFileSync(path.join(out, 'logo.svg'), path.join(root, 'web', 'assets', 'logo.svg'));
  fs.writeFileSync(path.join(root, 'web', 'assets', 'favicon-32.png'), png[32]);

  // ICO-Datei mit PNG-Bildern (Windows Vista und neuer)
  const sizes = [16, 24, 32, 48, 64, 128, 256];
  const header = Buffer.alloc(6 + sizes.length * 16);
  header.writeUInt16LE(0, 0); header.writeUInt16LE(1, 2); header.writeUInt16LE(sizes.length, 4);
  let offset = header.length;
  sizes.forEach((s, i) => {
    const e = 6 + i * 16;
    header.writeUInt8(s >= 256 ? 0 : s, e); header.writeUInt8(s >= 256 ? 0 : s, e + 1);
    header.writeUInt8(0, e + 2); header.writeUInt8(0, e + 3);
    header.writeUInt16LE(1, e + 4); header.writeUInt16LE(32, e + 6);
    header.writeUInt32LE(png[s].length, e + 8); header.writeUInt32LE(offset, e + 12);
    offset += png[s].length;
  });
  fs.writeFileSync(path.join(out, 'icon.ico'), Buffer.concat([header, ...sizes.map((s) => png[s])]));
  console.log('Symbole erstellt in assets/');
})();

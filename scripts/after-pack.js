// Setzt nach dem Packen Symbol und Datei-Infos der SchoolWeb.exe (ohne Windows/Wine nötig).
const fs = require('fs');
const path = require('path');

module.exports = async function afterPack(context) {
  if (context.electronPlatformName !== 'win32') return;
  const ResEdit = require('resedit');
  const root = path.join(__dirname, '..');
  const pkg = require(path.join(root, 'package.json'));
  const exePath = path.join(context.appOutDir, `${context.packager.appInfo.productFilename}.exe`);

  const exe = ResEdit.NtExecutable.from(fs.readFileSync(exePath), { ignoreCert: true });
  const res = ResEdit.NtExecutableResource.from(exe);

  // Programm-Symbol
  const ico = ResEdit.Data.IconFile.from(fs.readFileSync(path.join(root, 'assets', 'icon.ico')));
  const groups = ResEdit.Resource.IconGroupEntry.fromEntries(res.entries);
  const groupId = groups.length ? groups[0].id : 1;
  const lang = groups.length ? groups[0].lang : 1033;
  ResEdit.Resource.IconGroupEntry.replaceIconsForResource(res.entries, groupId, lang, ico.icons.map((i) => i.data));

  // Datei-Infos (Rechtsklick → Eigenschaften → Details)
  const [vi] = ResEdit.Resource.VersionInfo.fromEntries(res.entries);
  const [a, b, c] = pkg.version.split('.').map(Number);
  vi.setFileVersion(a, b, c, 0, 1033);
  vi.setProductVersion(a, b, c, 0, 1033);
  vi.setStringValues({ lang: 1033, codepage: 1200 }, {
    FileDescription: 'SchoolWeb',
    ProductName: 'SchoolWeb',
    CompanyName: 'D.',
    LegalCopyright: '© 2026 Darian',
    OriginalFilename: 'SchoolWeb.exe',
    InternalName: 'SchoolWeb',
  });
  vi.outputToResourceEntries(res.entries);

  res.outputResource(exe);
  fs.writeFileSync(exePath, Buffer.from(exe.generate()));
  console.log('  • SchoolWeb.exe: Symbol und Datei-Infos gesetzt');
};

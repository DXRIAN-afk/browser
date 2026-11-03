const { app, BrowserWindow, Menu, ipcMain, session, shell } = require('electron');
const fs = require('fs');
const path = require('path');

// Portable-Version: alle Daten (Anmeldung, Cookies) im Ordner neben der .exe speichern,
// damit SchoolWeb z. B. vom USB-Stick läuft und auf dem PC nichts zurückbleibt
if (process.env.PORTABLE_EXECUTABLE_DIR) {
  app.setPath('userData', path.join(process.env.PORTABLE_EXECUTABLE_DIR, 'SchoolWeb-Daten'));
}

// Server-Adresse kann beim Bauen fest eingetragen werden (scripts/write-server.js)
let DEFAULT_SERVER = '';
try { DEFAULT_SERVER = require('./server.json').server || ''; } catch (e) {}

let win = null;
let auth = null;              // { server, token, username, isAdmin }
let blocked = new Set();      // gesperrte Domains vom Server
let checkTimer = null;
let loginMessage = '';

// ---- Anmeldedaten speichern ------------------------------------------------
const authFile = () => path.join(app.getPath('userData'), 'auth.json');
function loadAuth() { try { auth = JSON.parse(fs.readFileSync(authFile(), 'utf8')); } catch (e) { auth = null; } }
function saveAuth() { try { fs.writeFileSync(authFile(), JSON.stringify(auth || {})); } catch (e) {} }

function normalizeServer(s) {
  s = String(s || '').trim();
  if (!s) return '';
  if (!/^https?:\/\//i.test(s)) s = 'https://' + s;
  return s.replace(/[?#].*$/, '').replace(/\/(index|api|login)\.php$/i, '').replace(/\/+$/, '');
}

// ---- Server-Schnittstelle --------------------------------------------------
async function api(action, { method = 'GET', body, server = auth && auth.server, token = auth && auth.token } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) { headers.Authorization = 'Bearer ' + token; headers['X-Browser-Token'] = token; }
  let res;
  try {
    res = await fetch(server + '/api.php?action=' + action, {
      method, headers, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(15000),
    });
  } catch (e) {
    const err = new Error('Der Server ist nicht erreichbar. Bitte Internetverbindung und Server-Adresse prüfen.');
    err.network = true;
    throw err;
  }
  let data;
  try { data = await res.json(); } catch (e) { throw new Error('Unter dieser Adresse wurde kein SchoolWeb-Server gefunden.'); }
  if (!res.ok) { const err = new Error(data.error || 'Fehler ' + res.status); err.status = res.status; throw err; }
  return data;
}

async function refresh() {
  if (!auth || !auth.token) return;
  try {
    const me = await api('me');
    auth.username = me.username;
    auth.isAdmin = me.is_admin;
    const list = await api('blocklist');
    blocked = new Set(list.domains || []);
  } catch (e) {
    if (e.status === 401) logout('Deine Anmeldung ist abgelaufen oder dein Konto wurde gesperrt.');
  }
}

function isBlocked(url) {
  let host;
  try { host = new URL(url).hostname.toLowerCase(); } catch (e) { return false; }
  if (auth && auth.server && host === new URL(auth.server).hostname.toLowerCase()) return false;
  const parts = host.split('.');
  for (let i = 0; i < parts.length; i++) if (blocked.has(parts.slice(i).join('.'))) return true;
  return false;
}

function blockedPage(url) {
  const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  let host = url;
  try { host = new URL(url).hostname; } catch (e) {}
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Gesperrt</title></head>
    <body style="font:15px system-ui,'Segoe UI',sans-serif;background:#1e1f22;color:#e3e5e8;padding:60px">
    <h2>Diese Seite ist gesperrt</h2><p>${esc(host)}</p>
    <p style="color:#9aa0a6">Der Administrator hat diese Website gesperrt.</p></body></html>`;
  return 'data:text/html;charset=utf-8,' + encodeURIComponent(html);
}

// ---- Fenster ---------------------------------------------------------------
function showLogin(message = '') {
  loginMessage = message;
  clearInterval(checkTimer);
  win.loadFile(path.join(__dirname, 'login.html'));
}

async function showBrowser() {
  await refresh();
  if (!auth || !auth.token) return;
  win.loadFile(path.join(__dirname, 'index.html'));
  clearInterval(checkTimer);
  checkTimer = setInterval(refresh, 60 * 1000);
}

async function logout(message = '') {
  if (auth && auth.token) {
    api('logout', { method: 'POST' }).catch(() => {});
    auth.token = null;
    saveAuth();
  }
  blocked = new Set();
  // offene Seiten und Cookies vom vorherigen Benutzer entfernen
  await session.defaultSession.clearStorageData().catch(() => {});
  showLogin(message);
}

async function start() {
  loadAuth();
  if (!auth || !auth.token) return showLogin();
  try {
    await api('me');
    showBrowser();
  } catch (e) {
    if (e.status === 401) { auth.token = null; saveAuth(); showLogin('Bitte melde dich erneut an.'); }
    else showLogin(e.message);
  }
}

function createWindow() {
  win = new BrowserWindow({
    width: 1280,
    height: 800,
    title: 'SchoolWeb',
    icon: path.join(__dirname, '..', 'assets', 'icon-256.png'),
    backgroundColor: '#0b0c1d',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      webviewTag: true,       // <webview> = eigener Chromium-Tab
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  Menu.setApplicationMenu(null);
  start();
}

// ---- Verbindung zur Oberfläche ---------------------------------------------
ipcMain.handle('login-info', () => ({
  server: (auth && auth.server) || DEFAULT_SERVER,
  fixedServer: !!DEFAULT_SERVER,
  username: (auth && auth.username) || '',
  message: loginMessage,
}));

ipcMain.handle('login', async (_e, { server, username, password }) => {
  server = normalizeServer(server || DEFAULT_SERVER);
  if (!server) return { error: 'Bitte die Server-Adresse eintragen.' };
  try {
    const r = await api('login', { method: 'POST', server, token: null, body: { username, password } });
    auth = { server, token: r.token, username: r.username, isAdmin: r.is_admin };
    saveAuth();
    showBrowser();
    return { ok: true };
  } catch (e) {
    return { error: e.message };
  }
});

ipcMain.handle('open-register', (_e, server) => {
  server = normalizeServer(server || DEFAULT_SERVER);
  if (server) shell.openExternal(server + '/register.php');
});

ipcMain.handle('user-info', () => auth && auth.token
  ? { username: auth.username, isAdmin: !!auth.isAdmin, server: auth.server }
  : null);

ipcMain.handle('logout', () => logout());

// Server-Anfragen der Oberfläche (der Zugangsschlüssel bleibt im Hauptprozess)
const UI_ACTIONS = ['me', 'config', 'favorites', 'favorite_add', 'favorite_rename', 'favorite_remove', 'messages', 'message_send'];
ipcMain.handle('api', async (_e, action, body) => {
  if (!UI_ACTIONS.includes(action) || !auth || !auth.token) throw new Error('Nicht erlaubt');
  try {
    return await api(action, body ? { method: 'POST', body } : {});
  } catch (e) {
    if (e.status === 401) logout('Deine Anmeldung ist abgelaufen oder dein Konto wurde gesperrt.');
    return { __error: e.message };
  }
});

// ---- Tabs (webviews): Sperren, Protokoll, Popups ----------------------------
app.on('web-contents-created', (_e, contents) => {
  contents.on('will-attach-webview', (_ev, webPreferences) => {
    // Seiten dürfen keine Node-Rechte in Webviews erzwingen
    delete webPreferences.preload;
    webPreferences.nodeIntegration = false;
    webPreferences.contextIsolation = true;
  });

  if (contents.getType() !== 'webview') return;

  // Neue Fenster (target=_blank, window.open) als neuen Tab öffnen
  contents.setWindowOpenHandler(({ url }) => {
    const host = contents.hostWebContents;
    if (host) host.send('open-tab', url);
    return { action: 'deny' };
  });

  // Gesperrte Seite: statt der Seite eine Hinweis-Seite anzeigen
  contents.on('did-start-navigation', (e, legacyUrl, _inPlace, legacyMain) => {
    const url = (e && e.url) || legacyUrl;
    const isMain = e && typeof e.isMainFrame === 'boolean' ? e.isMainFrame : legacyMain;
    if (isMain && /^https?:/i.test(url) && auth && auth.token && isBlocked(url)) {
      // erst nach diesem Ereignis umleiten, sonst blockiert sich Electron selbst
      setImmediate(() => { if (!contents.isDestroyed()) contents.loadURL(blockedPage(url)).catch(() => {}); });
    }
  });

  // Seitenaufrufe an den Server melden
  let timer = null, lastUrl = '', lastAt = 0;
  const send = (url = contents.getURL(), title = contents.getTitle()) => {
    if (!auth || !auth.token || !/^https?:/i.test(url)) return;
    lastUrl = url; lastAt = Date.now();
    api('log', { method: 'POST', body: { url, title } })
      .catch((e) => { if (e.status === 401) logout('Deine Anmeldung ist abgelaufen oder dein Konto wurde gesperrt.'); });
  };
  let pending = null; // { url, title } wartet noch auf den Titel
  const flush = () => {
    clearTimeout(timer);
    if (pending && pending.url !== lastUrl) send(pending.url, pending.title);
    pending = null;
  };
  const schedule = () => {
    const url = contents.getURL();
    if (pending && pending.url !== url) flush(); // vorherige Seite sofort melden
    pending = { url, title: contents.getTitle() };
    clearTimeout(timer);
    timer = setTimeout(() => { if (pending) pending.title = contents.getTitle(); flush(); }, 1500);
  };
  contents.on('did-navigate', schedule);
  contents.on('did-navigate-in-page', (_ev, _url, isMainFrame) => { if (isMainFrame) schedule(); });
  contents.on('page-title-updated', () => {
    // Titel nachliefern (der Server aktualisiert den Eintrag)
    if (contents.getURL() === lastUrl && Date.now() - lastAt < 25000) send();
  });
  contents.on('destroyed', () => flush());
});

app.whenReady().then(() => {
  // Gesperrte Seiten gar nicht erst laden (Tab zeigt dann die Sperr-Seite)
  session.defaultSession.webRequest.onBeforeRequest({ urls: ['http://*/*', 'https://*/*'] }, (d, cb) => {
    const frame = d.resourceType === 'mainFrame' || d.resourceType === 'subFrame';
    cb({ cancel: frame && (!auth || !auth.token || isBlocked(d.url)) });
  });
  createWindow();
});
app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) createWindow();
});

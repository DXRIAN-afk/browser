// SchoolWeb – Oberfläche der Windows-App
let SEARCH = 'https://www.google.com/search?q=';
const api = (action, body) => window.browserApi.api(action, body);

const $ = (id) => document.getElementById(id);
const tabsEl = $('tabs'), viewsEl = $('views'), urlEl = $('url'), startEl = $('start');
const backBtn = $('back'), fwdBtn = $('forward'), reloadBtn = $('reload'), progressEl = $('progress');

const tabs = [];
let active = null;

// Eingabe in eine URL umwandeln: Adresse, localhost oder Suchbegriff
function toUrl(input) {
  const text = input.trim();
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(text) || /^(about|data|file):/i.test(text)) return text;
  if (/^localhost(:\d+)?(\/.*)?$/i.test(text)) return 'http://' + text;
  if (/^\d{1,3}(\.\d{1,3}){3}(:\d+)?([/?#].*)?$/.test(text)) return 'http://' + text;
  if (!/\s/.test(text) && /^[^\s/]+\.[a-z]{2,}(:\d+)?([/?#].*)?$/i.test(text)) return 'https://' + text;
  return SEARCH + encodeURIComponent(text);
}

const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function createTab(url = '') {
  const el = document.createElement('div');
  el.className = 'tab';
  el.innerHTML = '<img alt=""><span class="title">Neuer Tab</span><button class="close" title="Schließen">×</button>';
  const icon = el.querySelector('img');
  const title = el.querySelector('.title');
  icon.style.display = 'none';
  tabsEl.appendChild(el);

  const tab = { view: null, el, title, icon, ready: false, loading: false, url: '' };
  tabs.push(tab);

  el.addEventListener('mousedown', (e) => {
    if (e.button === 1) { e.preventDefault(); closeTab(tab); }
    else if (!e.target.classList.contains('close')) activate(tab);
  });
  el.querySelector('.close').addEventListener('click', () => closeTab(tab));

  if (url) openIn(tab, url);
  activate(tab);
  return tab;
}

// Die Webansicht wird erst erstellt, wenn der Tab eine Seite öffnet
function ensureView(tab, url) {
  if (tab.view) return tab.view;
  const view = document.createElement('webview');
  view.setAttribute('src', url);
  view.setAttribute('allowpopups', '');
  viewsEl.appendChild(view);
  tab.view = view;

  view.addEventListener('dom-ready', () => { tab.ready = true; if (tab === active) updateNav(); });
  view.addEventListener('page-title-updated', (e) => { setTitle(tab, e.title); });
  view.addEventListener('page-favicon-updated', (e) => {
    if (e.favicons.length) { tab.icon.src = e.favicons[0]; tab.icon.style.display = ''; }
  });
  view.addEventListener('did-start-loading', () => { tab.loading = true; if (tab === active) updateNav(); });
  view.addEventListener('did-stop-loading', () => { tab.loading = false; if (tab === active) updateNav(); });
  const onNav = (e) => {
    if (e.isMainFrame === false || e.url.startsWith('data:')) return;
    tab.url = e.url;
    if (tab === active && document.activeElement !== urlEl) urlEl.value = e.url;
    if (tab === active) updateNav();
  };
  view.addEventListener('did-navigate', onNav);
  view.addEventListener('did-navigate-in-page', onNav);
  view.addEventListener('did-fail-load', (e) => {
    if (!e.isMainFrame || e.errorCode === -3) return; // -3 = abgebrochen
    const isBlocked = e.errorCode === -20; // vom Admin gesperrt
    setTitle(tab, isBlocked ? 'Gesperrt' : 'Fehler');
    const html = isBlocked
      ? `<html><head><title>Gesperrt</title></head><body style="font:15px system-ui;background:#0b0c1d;color:#ecebff;padding:60px">
      <h2>Diese Seite ist gesperrt</h2><p>${esc(e.validatedURL)}</p>
      <p style="color:#9d9bc4">Der Administrator hat diese Website gesperrt.</p></body></html>`
      : `<html><head><title>Fehler</title></head><body style="font:15px system-ui;background:#0b0c1d;color:#ecebff;padding:60px">
      <h2>Seite konnte nicht geladen werden</h2><p>${esc(e.validatedURL)}</p>
      <p style="color:#9d9bc4">${esc(e.errorDescription)} (${e.errorCode})</p></body></html>`;
    view.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html)).catch(() => {});
  });
  return view;
}

function setTitle(tab, t) { tab.title.textContent = t; tab.el.title = t; tab.pageTitle = t; if (tab === active) updateStar(); }

function openIn(tab, url) {
  tab.url = url;
  if (!tab.view) ensureView(tab, url);
  else if (tab.ready) tab.view.loadURL(url).catch(() => {});
  else tab.view.setAttribute('src', url);
}

function activate(tab) {
  active = tab;
  for (const t of tabs) {
    t.el.classList.toggle('active', t === tab);
    if (t.view) t.view.classList.toggle('hidden', t !== tab || !t.url);
  }
  startEl.classList.toggle('hidden', !!tab.url);
  urlEl.value = tab.url;
  updateNav();
  tab.el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  if (!tab.url) setTimeout(() => $('start-input').focus(), 0);
}

function closeTab(tab) {
  const i = tabs.indexOf(tab);
  if (i < 0) return;
  tabs.splice(i, 1);
  tab.el.remove();
  if (tab.view) tab.view.remove();
  if (!tabs.length) { createTab(); return; }
  if (active === tab) activate(tabs[Math.min(i, tabs.length - 1)]);
}

function updateNav() {
  const t = active;
  const ok = t && t.view && t.ready && t.url;
  backBtn.disabled = !(ok && t.view.canGoBack());
  fwdBtn.disabled = !(ok && t.view.canGoForward());
  reloadBtn.disabled = !(t && t.url);
  reloadBtn.innerHTML = t && t.loading ? '&#10005;' : '&#8635;';
  reloadBtn.title = t && t.loading ? 'Stopp (Esc)' : 'Neu laden (F5)';
  progressEl.classList.toggle('loading', !!(t && t.loading && t.url));
  updateStar();
}

function navigate(input) {
  if (!active || !input.trim()) return;
  const url = toUrl(input);
  openIn(active, url);
  activate(active);
  urlEl.value = url;
  active.view.focus();
}

function goHome() {
  if (!active) return;
  active.url = '';
  if (active.view) { active.view.remove(); active.view = null; active.ready = false; active.loading = false; }
  setTitle(active, 'Neuer Tab');
  active.icon.style.display = 'none';
  activate(active);
}

backBtn.addEventListener('click', () => active.ready && active.view.goBack());
fwdBtn.addEventListener('click', () => active.ready && active.view.goForward());
reloadBtn.addEventListener('click', () => {
  if (!active.ready) return;
  active.loading ? active.view.stop() : active.view.reload();
});
$('home').addEventListener('click', goHome);
$('new-tab').addEventListener('click', () => createTab());

urlEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') navigate(urlEl.value);
  if (e.key === 'Escape') { urlEl.value = active.url; if (active.view) active.view.focus(); }
});
urlEl.addEventListener('focus', () => urlEl.select());
$('start-form').addEventListener('submit', (e) => { e.preventDefault(); navigate($('start-input').value); $('start-input').value = ''; });
document.querySelector('#start .hexa').addEventListener('click', (e) => { e.preventDefault(); navigate(e.currentTarget.dataset.url); });

// ---- Favoriten (auf dem Server pro Konto gespeichert) ----
let favorites = [];
const favbar = $('favbar'), tilesEl = $('tiles'), starBtn = $('star');
function favLetter(url) {
  let host = '';
  try { host = new URL(url).hostname.replace(/^www\./, ''); } catch (e) {}
  return (host[0] || '?').toUpperCase();
}
function renderFavorites() {
  favbar.innerHTML = '';
  tilesEl.innerHTML = '';
  favbar.hidden = favorites.length === 0;
  for (const f of favorites) {
    const chip = document.createElement('div');
    chip.className = 'fav';
    chip.title = f.title + '\n' + f.url + '\nDoppelklick = umbenennen';
    chip.innerHTML = '<span class="fi"></span><span class="ft"></span><button class="fx" title="Entfernen">×</button>';
    chip.querySelector('.fi').textContent = favLetter(f.url);
    chip.querySelector('.ft').textContent = f.title;
    chip.addEventListener('click', (e) => {
      if (e.target.classList.contains('fx')) return;
      if (e.ctrlKey || e.metaKey) createTab(f.url); else navigate(f.url);
    });
    chip.addEventListener('auxclick', (e) => { if (e.button === 1) { e.preventDefault(); createTab(f.url); } });
    chip.addEventListener('dblclick', () => startRename(chip, f));
    chip.querySelector('.fx').addEventListener('click', () => removeFavorite(f.id));
    favbar.appendChild(chip);

    const tile = document.createElement('button');
    tile.className = 'tile';
    tile.title = f.url;
    tile.innerHTML = '<span class="ti"></span><span class="tt"></span>';
    tile.querySelector('.ti').textContent = favLetter(f.url);
    tile.querySelector('.tt').textContent = f.title;
    tile.addEventListener('click', () => navigate(f.url));
    tilesEl.appendChild(tile);
  }
  updateStar();
}
// Umbenennen direkt in der Leiste (prompt() gibt es in Electron nicht)
function startRename(chip, f) {
  const input = document.createElement('input');
  input.className = 'frename';
  input.value = f.title;
  chip.querySelector('.ft').replaceWith(input);
  input.focus(); input.select();
  let done = false;
  const finish = async (save) => {
    if (done) return; done = true;
    if (save && input.value.trim() && input.value.trim() !== f.title) {
      try { favorites = (await api('favorite_rename', { id: f.id, title: input.value.trim() })).favorites; } catch (e) {}
    }
    renderFavorites();
  };
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') finish(true); if (e.key === 'Escape') finish(false); e.stopPropagation(); });
  input.addEventListener('blur', () => finish(true));
  input.addEventListener('click', (e) => e.stopPropagation());
}
function currentFavorite() { return active && active.url ? favorites.find((f) => f.url === active.url) : null; }
function updateStar() {
  const fav = currentFavorite();
  starBtn.textContent = fav ? '★' : '☆';
  starBtn.classList.toggle('on', !!fav);
  starBtn.title = fav ? 'Aus Favoriten entfernen (Strg+D)' : 'Zu Favoriten hinzufügen (Strg+D)';
  starBtn.disabled = !(active && /^https?:/i.test(active.url || ''));
}
async function removeFavorite(id) {
  try { favorites = (await api('favorite_remove', { id })).favorites; renderFavorites(); } catch (e) {}
}
async function toggleFavorite() {
  if (!active || !/^https?:/i.test(active.url || '')) return;
  const fav = currentFavorite();
  try {
    if (fav) favorites = (await api('favorite_remove', { id: fav.id })).favorites;
    else favorites = (await api('favorite_add', { url: active.url, title: active.pageTitle || '' })).favorites;
    renderFavorites();
  } catch (e) {}
}
starBtn.addEventListener('click', toggleFavorite);

// ---- Hilfe / Problem melden ----
const help = $('help');
function renderMessages(list) {
  $('help-list').innerHTML = list.map((m) => `<li><div class="meta">${new Date(m.created_at * 1000).toLocaleString('de-CH')}
    ${m.reply ? '<span class="tag ok">beantwortet</span>' : (m.status === 'done' ? '<span class="tag ok">erledigt</span>' : '<span class="tag">offen</span>')}</div>
    <div class="body">${esc(m.body)}</div>${m.reply ? `<div class="reply"><b>Admin:</b> ${esc(m.reply)}</div>` : ''}</li>`).join('');
}
async function openHelp() {
  help.hidden = false;
  $('help-msg').hidden = true;
  $('help-text').focus();
  try { renderMessages((await api('messages')).messages); $('dot').hidden = $('dot2').hidden = true; } catch (e) {}
}
$('help-close').addEventListener('click', () => { help.hidden = true; });
help.addEventListener('click', (e) => { if (e.target === help) help.hidden = true; });
$('help-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  let body = $('help-text').value.trim();
  if (!body) return;
  if ($('help-url').checked && active && active.url) body += '\n\nSeite: ' + active.url;
  $('help-send').disabled = true;
  try {
    const r = await api('message_send', { body });
    renderMessages(r.messages);
    $('help-text').value = '';
    $('help-msg').textContent = 'Danke! Deine Nachricht wurde gesendet.';
    $('help-msg').className = 'msg ok';
  } catch (err) {
    $('help-msg').textContent = err.message;
    $('help-msg').className = 'msg';
  }
  $('help-msg').hidden = false;
  $('help-send').disabled = false;
});

// ---- Verlauf (Strg+H): vom Server, also auf jedem Gerät gleich ----
const historyEl = $('history');
let historyTimer = null;
function dayLabel(d) {
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const diff = Math.round((today - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
  if (diff === 0) return 'Heute';
  if (diff === 1) return 'Gestern';
  return d.toLocaleDateString('de-CH', { weekday: 'long', day: 'numeric', month: 'long' });
}
async function loadHistory() {
  const list = $('history-list');
  try {
    const q = $('history-q').value.trim();
    const items = (await api('history', { q })).history;
    if (!items.length) { list.innerHTML = `<p class="empty">${q ? 'Nichts gefunden.' : 'Noch kein Verlauf.'}</p>`; return; }
    let html = '', last = '';
    for (const h of items) {
      const d = new Date(h.created_at * 1000), label = dayLabel(d);
      if (label !== last) { html += `<h3>${esc(label)}</h3>`; last = label; }
      html += `<button class="hitem" data-url="${esc(h.url)}" title="${esc(h.url)}"><span class="htime">${d.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' })}</span>
        <span class="fi">${esc(favLetter(h.url))}</span><span class="htitle">${esc(h.title || h.url)}</span><span class="hdomain">${esc(h.domain)}</span></button>`;
    }
    list.innerHTML = html;
  } catch (e) { list.innerHTML = `<p class="empty">${esc(e.message)}</p>`; }
}
function openHistory() { historyEl.hidden = false; $('history-q').value = ''; $('history-q').focus(); loadHistory(); }
$('history-q').addEventListener('input', () => { clearTimeout(historyTimer); historyTimer = setTimeout(loadHistory, 250); });
$('history-list').addEventListener('click', (e) => {
  const b = e.target.closest('.hitem');
  if (!b) return;
  historyEl.hidden = true;
  if (e.ctrlKey || e.metaKey) createTab(b.dataset.url); else navigate(b.dataset.url);
});
$('history-close').addEventListener('click', () => { historyEl.hidden = true; });
historyEl.addEventListener('click', (e) => { if (e.target === historyEl) historyEl.hidden = true; });

// ---- Downloads ----
const dlBar = $('downloads'), dlList = $('dl-list');
const dlItems = new Map();
function fmtBytes(n) { return n > 1e9 ? (n / 1e9).toFixed(1) + ' GB' : n > 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1e3)) + ' KB'; }
window.browserApi.onDownload((d) => {
  let el = dlItems.get(d.id);
  if (d.type === 'start') {
    el = document.createElement('div');
    el.className = 'dl';
    el.innerHTML = '<span class="dicon">⤓</span><span class="dinfo"><span class="dname"></span><span class="dstate">Startet …</span><span class="dprog"><span></span></span></span><button class="dbtn" title="Abbrechen">×</button>';
    el.querySelector('.dname').textContent = d.name;
    el.querySelector('.dbtn').addEventListener('click', (e) => { e.stopPropagation(); window.browserApi.downloadAction(d.id, el.classList.contains('done') ? 'folder' : 'cancel'); });
    el.addEventListener('click', () => { if (el.classList.contains('done')) window.browserApi.downloadAction(d.id, 'open'); });
    dlList.prepend(el);
    dlItems.set(d.id, el);
    dlBar.hidden = false;
  } else if (el && d.type === 'progress') {
    const pct = d.total ? Math.round(d.received / d.total * 100) : 0;
    el.querySelector('.dprog span').style.width = (d.total ? pct : 30) + '%';
    el.querySelector('.dstate').textContent = d.total ? `${fmtBytes(d.received)} von ${fmtBytes(d.total)} · ${pct} %` : fmtBytes(d.received);
  } else if (el && d.type === 'done') {
    const ok = d.state === 'completed';
    el.classList.add(ok ? 'done' : 'failed');
    el.querySelector('.dstate').textContent = ok ? 'Fertig · klicken zum Öffnen' : d.state === 'cancelled' ? 'Abgebrochen' : 'Fehlgeschlagen';
    el.querySelector('.dicon').textContent = ok ? '✓' : '!';
    const btn = el.querySelector('.dbtn');
    if (ok) { btn.textContent = '📁'; btn.title = 'Im Ordner zeigen'; } else btn.remove();
  }
});
$('dl-close').addEventListener('click', () => { dlBar.hidden = true; });

// ---- Ankündigungen vom Admin ----
function dismissed() { try { return JSON.parse(localStorage.getItem('sw-dismissed') || '[]'); } catch (e) { return []; } }
function renderBanners(list) {
  const gone = dismissed(), box = $('banners');
  box.innerHTML = '';
  for (const a of list.filter((x) => !gone.includes(x.id))) {
    const el = document.createElement('div');
    el.className = 'banner ' + (a.level || 'info');
    el.innerHTML = '<span class="bi"></span><span class="bt"></span><button class="bx" title="Ausblenden">×</button>';
    el.querySelector('.bi').textContent = a.level === 'warning' ? '⚠' : a.level === 'success' ? '✓' : 'ℹ';
    el.querySelector('.bt').textContent = a.body;
    el.querySelector('.bx').addEventListener('click', () => {
      try { localStorage.setItem('sw-dismissed', JSON.stringify(dismissed().concat(a.id).slice(-100))); } catch (e) {}
      el.remove();
    });
    box.appendChild(el);
  }
}
function loadBanners() { api('announcements').then((d) => renderBanners(d.announcements || [])).catch(() => {}); }
loadBanners();
setInterval(loadBanners, 60000);

// ---- Tastenkürzel (gelten, solange der Fokus in der Oberfläche liegt) ----
document.addEventListener('keydown', (e) => {
  const ctrl = e.ctrlKey || e.metaKey;
  if (e.key === 'Escape' && (!help.hidden || !historyEl.hidden)) { help.hidden = true; historyEl.hidden = true; }
  else if (ctrl && e.key === 'h') { e.preventDefault(); openHistory(); }
  else if (ctrl && e.key === 'j') { e.preventDefault(); dlBar.hidden = !dlBar.hidden || !dlList.children.length; }
  else if (ctrl && e.key === 't') { e.preventDefault(); createTab(); }
  else if (ctrl && e.key === 'w') { e.preventDefault(); closeTab(active); }
  else if (ctrl && e.key === 'l') { e.preventDefault(); urlEl.focus(); }
  else if (ctrl && e.key === 'd') { e.preventDefault(); toggleFavorite(); }
  else if (e.key === 'F5' || (ctrl && e.key === 'r')) { e.preventDefault(); active.ready && active.view.reload(); }
  else if (e.altKey && e.key === 'ArrowLeft') active.ready && active.view.goBack();
  else if (e.altKey && e.key === 'ArrowRight') active.ready && active.view.goForward();
});

window.browserApi.onOpenTab((url) => createTab(url));

// ---- Konto ----
const accountBtn = $('account-btn');
const accountMenu = $('account-menu');
window.browserApi.userInfo().then((u) => {
  if (!u) return;
  accountBtn.firstChild.textContent = u.username;
  if (u.isAdmin) {
    const adminBtn = $('menu-admin');
    adminBtn.hidden = false;
    adminBtn.addEventListener('click', () => createTab(u.server + '/admin/'));
  }
});
accountBtn.addEventListener('click', (e) => { e.stopPropagation(); accountMenu.hidden = !accountMenu.hidden; });
document.addEventListener('click', () => { accountMenu.hidden = true; });
$('menu-help').addEventListener('click', openHelp);
$('menu-history').addEventListener('click', openHistory);
$('menu-downloads').addEventListener('click', () => { if (dlList.children.length) dlBar.hidden = false; else alertDownloads(); });
function alertDownloads() {
  dlList.innerHTML = '<div class="dl empty">Noch keine Downloads in dieser Sitzung. Dateien landen im Ordner „Downloads“.</div>';
  dlBar.hidden = false;
  setTimeout(() => { if (dlList.querySelector('.empty')) { dlList.innerHTML = ''; dlBar.hidden = true; } }, 3500);
}
$('menu-hexa').addEventListener('click', () => createTab('https://hexa.da-rtx.ch'));
$('menu-logout').addEventListener('click', () => window.browserApi.logout());

// Daten vom Server: Suchmaschine, Favoriten, neue Antworten
api('config').then((c) => { if (c.search_url) SEARCH = c.search_url; }).catch(() => {});
api('favorites').then((d) => { favorites = d.favorites; renderFavorites(); }).catch(() => {});
function checkReplies() {
  api('me').then((d) => { $('dot').hidden = $('dot2').hidden = !d.unseen_replies; }).catch(() => {});
}
checkReplies();
setInterval(checkReplies, 60000);

createTab();

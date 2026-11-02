const HOME = 'https://www.google.com';
const SEARCH = 'https://www.google.com/search?q=';

const tabsEl = document.getElementById('tabs');
const viewsEl = document.getElementById('views');
const urlEl = document.getElementById('url');
const backBtn = document.getElementById('back');
const fwdBtn = document.getElementById('forward');
const reloadBtn = document.getElementById('reload');
const progressEl = document.getElementById('progress');

const tabs = [];
let active = null;

// Eingabe in eine URL umwandeln: Adresse, localhost oder Suchbegriff
function toUrl(input) {
  const text = input.trim();
  if (!text) return HOME;
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(text) || /^(about|data|file):/i.test(text)) return text;
  if (/^localhost(:\d+)?(\/.*)?$/i.test(text)) return 'http://' + text;
  if (!/\s/.test(text) && /^[^\s/]+\.[a-z]{2,}(:\d+)?(\/.*)?$/i.test(text)) return 'https://' + text;
  return SEARCH + encodeURIComponent(text);
}

function createTab(url = HOME) {
  const view = document.createElement('webview');
  view.setAttribute('src', url);
  view.setAttribute('allowpopups', '');
  viewsEl.appendChild(view);

  const el = document.createElement('div');
  el.className = 'tab';
  el.innerHTML = '<img alt=""><span class="title">Neuer Tab</span><button class="close" title="Schließen">×</button>';
  const icon = el.querySelector('img');
  const title = el.querySelector('.title');
  icon.style.visibility = 'hidden';
  tabsEl.appendChild(el);

  const tab = { view, el, ready: false, loading: false };
  tabs.push(tab);

  el.addEventListener('mousedown', (e) => {
    if (e.button === 1) { e.preventDefault(); closeTab(tab); }       // Mittelklick schließt
    else if (!e.target.classList.contains('close')) activate(tab);
  });
  el.querySelector('.close').addEventListener('click', () => closeTab(tab));

  view.addEventListener('dom-ready', () => { tab.ready = true; if (tab === active) updateNav(); });
  view.addEventListener('page-title-updated', (e) => { title.textContent = e.title; el.title = e.title; });
  view.addEventListener('page-favicon-updated', (e) => {
    if (e.favicons.length) { icon.src = e.favicons[0]; icon.style.visibility = 'visible'; }
  });
  view.addEventListener('did-start-loading', () => { tab.loading = true; if (tab === active) updateNav(); });
  view.addEventListener('did-stop-loading', () => { tab.loading = false; if (tab === active) updateNav(); });
  const onNav = (e) => {
    if (e.isMainFrame === false || e.url.startsWith('data:')) return;
    if (tab === active && document.activeElement !== urlEl) urlEl.value = e.url;
    if (tab === active) updateNav();
  };
  view.addEventListener('did-navigate', onNav);
  view.addEventListener('did-navigate-in-page', onNav);
  view.addEventListener('did-fail-load', (e) => {
    if (!e.isMainFrame || e.errorCode === -3) return; // -3 = abgebrochen
    title.textContent = 'Fehler';
    const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const html = `<html><body style="font:15px system-ui;background:#1e1f22;color:#e3e5e8;padding:60px">
      <h2>Seite konnte nicht geladen werden</h2><p>${esc(e.validatedURL)}</p>
      <p style="color:#9aa0a6">${esc(e.errorDescription)} (${e.errorCode})</p></body></html>`;
    view.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
  });

  activate(tab);
  return tab;
}

function activate(tab) {
  active = tab;
  for (const t of tabs) {
    t.el.classList.toggle('active', t === tab);
    t.view.classList.toggle('hidden', t !== tab);
  }
  urlEl.value = tab.ready ? tab.view.getURL() : (tab.view.getAttribute('src') || '');
  updateNav();
  tab.el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

function closeTab(tab) {
  const i = tabs.indexOf(tab);
  if (i < 0) return;
  tabs.splice(i, 1);
  tab.el.remove();
  tab.view.remove();
  if (!tabs.length) { createTab(); return; }
  if (active === tab) activate(tabs[Math.min(i, tabs.length - 1)]);
}

function updateNav() {
  const t = active;
  const ok = t && t.ready;
  backBtn.disabled = !(ok && t.view.canGoBack());
  fwdBtn.disabled = !(ok && t.view.canGoForward());
  reloadBtn.innerHTML = t && t.loading ? '&#10005;' : '&#8635;';
  reloadBtn.title = t && t.loading ? 'Stopp (Esc)' : 'Neu laden (F5)';
  progressEl.classList.toggle('loading', !!(t && t.loading));
}

function navigate(input) {
  if (!active) return;
  const url = toUrl(input);
  if (active.ready) active.view.loadURL(url); else active.view.setAttribute('src', url);
  urlEl.value = url;
  active.view.focus();
}

backBtn.addEventListener('click', () => active.ready && active.view.goBack());
fwdBtn.addEventListener('click', () => active.ready && active.view.goForward());
reloadBtn.addEventListener('click', () => {
  if (!active.ready) return;
  active.loading ? active.view.stop() : active.view.reload();
});
document.getElementById('home').addEventListener('click', () => navigate(HOME));
document.getElementById('new-tab').addEventListener('click', () => { createTab(); urlEl.focus(); });

urlEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') navigate(urlEl.value);
  if (e.key === 'Escape') { urlEl.value = active.view.getURL(); active.view.focus(); }
});
urlEl.addEventListener('focus', () => urlEl.select());

// Tastenkürzel (gelten, solange der Fokus in der Browser-Oberfläche liegt)
document.addEventListener('keydown', (e) => {
  const ctrl = e.ctrlKey || e.metaKey;
  if (ctrl && e.key === 't') { e.preventDefault(); createTab(); urlEl.focus(); }
  else if (ctrl && e.key === 'w') { e.preventDefault(); closeTab(active); }
  else if (ctrl && e.key === 'l') { e.preventDefault(); urlEl.focus(); }
  else if (e.key === 'F5' || (ctrl && e.key === 'r')) { e.preventDefault(); active.ready && active.view.reload(); }
  else if (e.altKey && e.key === 'ArrowLeft') active.ready && active.view.goBack();
  else if (e.altKey && e.key === 'ArrowRight') active.ready && active.view.goForward();
});

window.browserApi.onOpenTab((url) => createTab(url));

createTab();

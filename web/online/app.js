const PROXY = new URL('proxy.php', location.href).href;
const SEARCH = 'https://html.duckduckgo.com/html/?q=';

// Sitzungs-ID, damit der Server Cookies pro Besucher trennen kann
function makeToken() {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
}
let TOKEN;
try {
  TOKEN = sessionStorage.getItem('browserToken');
  if (!/^[a-f0-9]{32}$/.test(TOKEN || '')) { TOKEN = makeToken(); sessionStorage.setItem('browserToken', TOKEN); }
} catch (e) { TOKEN = makeToken(); }

const $ = (id) => document.getElementById(id);
const tabsEl = $('tabs'), viewsEl = $('views'), urlEl = $('url'), startEl = $('start');
const backBtn = $('back'), fwdBtn = $('forward'), reloadBtn = $('reload'), progressEl = $('progress');

const tabs = [];
let active = null;

function toUrl(input) {
  const text = input.trim();
  if (/^https?:\/\//i.test(text)) return text;
  if (/^localhost(:\d+)?(\/.*)?$/i.test(text)) return 'http://' + text;
  if (!/\s/.test(text) && /^[^\s/]+\.[a-z]{2,}(:\d+)?([/?#].*)?$/i.test(text)) return 'https://' + text;
  return SEARCH + encodeURIComponent(text);
}
const proxied = (url) => PROXY + '?__s=' + TOKEN + '&__u=' + encodeURIComponent(url);

function createTab(url) {
  const frame = document.createElement('iframe');
  // Sandbox: die fremde Seite darf nicht auf diese Oberfläche oder deine Domain zugreifen
  frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-modals allow-downloads allow-pointer-lock allow-presentation');
  frame.setAttribute('allow', 'fullscreen; autoplay; clipboard-write; encrypted-media; picture-in-picture');
  frame.setAttribute('referrerpolicy', 'no-referrer');
  frame.classList.add('hidden');
  viewsEl.appendChild(frame);

  const el = document.createElement('div');
  el.className = 'tab';
  el.innerHTML = '<span class="title"></span><button class="close" title="Schließen">×</button>';
  tabsEl.appendChild(el);

  const tab = { frame, el, history: [], index: -1, url: '', title: 'Neuer Tab', loading: false };
  tabs.push(tab);
  setTitle(tab, 'Neuer Tab');

  el.addEventListener('mousedown', (e) => {
    if (e.button === 1) { e.preventDefault(); closeTab(tab); }
    else if (!e.target.classList.contains('close')) activate(tab);
  });
  el.querySelector('.close').addEventListener('click', () => closeTab(tab));
  frame.addEventListener('load', () => { tab.loading = false; if (tab === active) updateNav(); });

  if (url) load(tab, url, true);
  activate(tab);
  return tab;
}

function setTitle(tab, title) {
  tab.title = title;
  tab.el.querySelector('.title').textContent = title;
  tab.el.title = title;
}

function load(tab, url, addToHistory) {
  if (addToHistory) {
    tab.history = tab.history.slice(0, tab.index + 1);
    tab.history.push(url);
    tab.index = tab.history.length - 1;
  }
  tab.url = url;
  tab.loading = true;
  tab.expect = true; // erste Meldung der neuen Seite ersetzt den Eintrag (Weiterleitungen)
  tab.frame.src = url ? proxied(url) : 'about:blank';
  if (tab === active) { urlEl.value = url; updateNav(); }
}

function activate(tab) {
  active = tab;
  for (const t of tabs) {
    t.el.classList.toggle('active', t === tab);
    t.frame.classList.toggle('hidden', t !== tab || !t.url);
  }
  startEl.classList.toggle('hidden', !!tab.url);
  urlEl.value = tab.url;
  updateNav();
  tab.el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  if (!tab.url) $('start-input').focus();
}

function closeTab(tab) {
  const i = tabs.indexOf(tab);
  if (i < 0) return;
  tabs.splice(i, 1);
  tab.el.remove();
  tab.frame.remove();
  if (!tabs.length) createTab();
  else if (active === tab) activate(tabs[Math.min(i, tabs.length - 1)]);
}

function updateNav() {
  const t = active;
  backBtn.disabled = !(t && t.index > 0);
  fwdBtn.disabled = !(t && t.index < t.history.length - 1);
  reloadBtn.disabled = !(t && t.url);
  progressEl.classList.toggle('loading', !!(t && t.loading));
}

function go(input) {
  if (!input.trim()) return;
  const url = toUrl(input);
  load(active, url, true);
  activate(active);
}

function goHistory(step) {
  const t = active, i = t.index + step;
  if (i < 0 || i >= t.history.length) return;
  t.index = i;
  load(t, t.history[i], false);
  activate(t);
}

// Nachrichten der Seiten im iframe (Adresse, Titel, neue Tabs)
window.addEventListener('message', (e) => {
  const tab = tabs.find((t) => t.frame.contentWindow === e.source);
  const m = e.data;
  if (!tab || !m || typeof m !== 'object') return;
  if (m.type === 'nav' && typeof m.url === 'string' && /^https?:/i.test(m.url)) {
    if (tab.expect) {
      tab.history[tab.index] = m.url;
      tab.expect = false;
    } else if (m.url !== tab.history[tab.index]) {
      // Seite hat selbst navigiert (Link, Formular, Weiterleitung)
      tab.history = tab.history.slice(0, tab.index + 1);
      tab.history.push(m.url);
      tab.index = tab.history.length - 1;
    }
    tab.url = m.url;
    if (m.title) setTitle(tab, m.title);
    else { try { setTitle(tab, new URL(m.url).hostname); } catch (err) {} }
    if (tab === active && document.activeElement !== urlEl) urlEl.value = m.url;
    if (tab === active) updateNav();
  } else if (m.type === 'open' && typeof m.url === 'string' && /^https?:/i.test(m.url)) {
    createTab(m.url);
  } else if (m.type === 'error') {
    setTitle(tab, 'Fehler');
  } else if (m.type === 'key') {
    if (m.ctrl && m.key === 't') { createTab(); }
    else if (m.ctrl && m.key === 'w') closeTab(active);
    else if (m.ctrl && m.key === 'l') urlEl.focus();
    else if (m.alt && m.key === 'arrowleft') goHistory(-1);
    else if (m.alt && m.key === 'arrowright') goHistory(1);
  }
});

backBtn.addEventListener('click', () => goHistory(-1));
fwdBtn.addEventListener('click', () => goHistory(1));
reloadBtn.addEventListener('click', () => active.url && load(active, active.url, false));
$('home').addEventListener('click', () => { active.url = ''; active.frame.src = 'about:blank'; activate(active); });
$('new-tab').addEventListener('click', () => createTab());

urlEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') go(urlEl.value);
  if (e.key === 'Escape') { urlEl.value = active.url; urlEl.blur(); }
});
urlEl.addEventListener('focus', () => urlEl.select());
$('start-form').addEventListener('submit', (e) => {
  e.preventDefault();
  go($('start-input').value);
  $('start-input').value = '';
});
document.querySelectorAll('#quick button').forEach((b) => b.addEventListener('click', () => go(b.dataset.url)));

document.addEventListener('keydown', (e) => {
  const ctrl = e.ctrlKey || e.metaKey;
  if (ctrl && e.key === 'l') { e.preventDefault(); urlEl.focus(); }
  else if (e.altKey && e.key === 'ArrowLeft') goHistory(-1);
  else if (e.altKey && e.key === 'ArrowRight') goHistory(1);
});

// Link wie  index.html#https://example.com  öffnet direkt diese Seite
createTab(location.hash.length > 1 ? toUrl(decodeURIComponent(location.hash.slice(1))) : '');

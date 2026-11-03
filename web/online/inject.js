/* Wird vom Proxy in jede Seite eingefügt. Leitet Links, Formulare und
   Netzwerk-Anfragen der Seite über den Proxy und meldet der Browser-
   Oberfläche Adresse und Titel. */
(function () {
  var C = window.__PROXY__;
  if (!C || window.__proxyInstalled) return;
  window.__proxyInstalled = true;

  function wrap(u) {
    try {
      var a = new URL(String(u), document.baseURI);
      if (a.protocol !== 'http:' && a.protocol !== 'https:') return null;
      if (a.href.indexOf(C.proxy) === 0) return a.href;
      return C.proxy + '?__s=' + C.token + '&__u=' + encodeURIComponent(a.href);
    } catch (e) { return null; }
  }
  function target(u) {
    try {
      var a = new URL(String(u), document.baseURI);
      if (a.href.indexOf(C.proxy) === 0) return a.searchParams.get('__u') || a.href;
      return a.href;
    } catch (e) { return String(u); }
  }
  function send(msg) { try { parent.postMessage(msg, '*'); } catch (e) {} }
  function report() { send({ type: 'nav', url: C.url, title: document.title || '' }); }

  // Die Seite läuft in einer Sandbox: Cookies und Speicher gibt es dort nicht.
  // Ersatz im Arbeitsspeicher, damit Seiten nicht abstürzen.
  function memStorage() {
    var d = {};
    return {
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(d, k) ? d[k] : null; },
      setItem: function (k, v) { d[k] = String(v); },
      removeItem: function (k) { delete d[k]; },
      clear: function () { d = {}; },
      key: function (i) { var k = Object.keys(d); return i < k.length ? k[i] : null; },
      get length() { return Object.keys(d).length; }
    };
  }
  ['localStorage', 'sessionStorage'].forEach(function (n) {
    try { window[n].length; } catch (e) {
      var s = memStorage();
      try { Object.defineProperty(window, n, { configurable: true, get: function () { return s; } }); } catch (e2) {}
    }
  });
  try { document.cookie; } catch (e) {
    var jar = {};
    try {
      Object.defineProperty(document, 'cookie', {
        configurable: true,
        get: function () { return Object.keys(jar).map(function (k) { return k + '=' + jar[k]; }).join('; '); },
        set: function (v) {
          var p = String(v).split(';')[0], i = p.indexOf('=');
          if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim();
        }
      });
    } catch (e2) {}
  }

  // fetch / XMLHttpRequest
  var origFetch = window.fetch;
  if (origFetch) {
    window.fetch = function (input, init) {
      try {
        if (typeof Request !== 'undefined' && input instanceof Request) {
          var w = wrap(input.url);
          if (w && w !== input.url) input = new Request(w, input);
        } else {
          var w2 = wrap(input);
          if (w2) input = w2;
        }
        if (init && init.mode === 'same-origin') init = Object.assign({}, init, { mode: 'cors' });
      } catch (e) {}
      return origFetch.call(this, input, init);
    };
  }
  var origOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (m, u) {
    var w = wrap(u);
    if (w) arguments[1] = w;
    return origOpen.apply(this, arguments);
  };
  if (navigator.sendBeacon) {
    var origBeacon = navigator.sendBeacon.bind(navigator);
    navigator.sendBeacon = function (u, d) { var w = wrap(u); return w ? origBeacon(w, d) : false; };
  }

  // Neue Fenster werden zu neuen Tabs
  window.open = function (u) {
    if (u) { var w = wrap(u); if (w) send({ type: 'open', url: target(w) }); }
    return null;
  };

  // Verlauf: Seiten-URL bleibt die Proxy-Adresse, aber die Oberfläche bekommt die neue Adresse
  ['pushState', 'replaceState'].forEach(function (n) {
    var orig = history[n];
    history[n] = function (state, title, u) {
      try { orig.call(history, state, title); } catch (e) {}
      if (u != null) { try { C.url = new URL(String(u), C.url).href; } catch (e) {} report(); }
    };
  });

  // Links
  function onLink(e, newTab) {
    if (e.defaultPrevented) return;
    var a = e.target && e.target.closest && e.target.closest('a[href], area[href]');
    if (!a) return;
    var raw = (a.getAttribute('href') || '').trim();
    if (raw === '' || /^(javascript|mailto|tel|data|blob):/i.test(raw)) return;
    if (raw.charAt(0) === '#') { e.preventDefault(); location.hash = raw; return; }
    if (a.hasAttribute('download')) return;
    var w = wrap(a.href);
    if (!w) return;
    e.preventDefault();
    if (newTab || a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) send({ type: 'open', url: target(w) });
    else location.href = w;
  }
  window.addEventListener('click', function (e) { if (e.button === 0) onLink(e, false); });
  window.addEventListener('auxclick', function (e) { if (e.button === 1) onLink(e, true); });

  // Formulare
  function hidden(f, name, value) {
    var i = f.querySelector('input[type=hidden][name="' + name + '"]');
    if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = name; f.appendChild(i); }
    i.value = value;
  }
  function fixForm(f) {
    if (!f || f.__proxyFixed) return;
    var action = f.getAttribute('action');
    var abs = action ? target(new URL(action, document.baseURI).href) : C.url;
    if (!/^https?:/i.test(abs)) return;
    f.__proxyFixed = true;
    var method = (f.getAttribute('method') || 'get').toLowerCase();
    if (method === 'get') {
      f.setAttribute('action', C.proxy);
      hidden(f, '__u', abs);
      hidden(f, '__s', C.token);
    } else {
      f.setAttribute('action', wrap(abs));
    }
    if (f.target && f.target !== '_self') f.target = '_self';
  }
  window.addEventListener('submit', function (e) { fixForm(e.target); }, true);
  var origSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { fixForm(this); return origSubmit.call(this); };
  if (HTMLFormElement.prototype.requestSubmit) {
    var origReq = HTMLFormElement.prototype.requestSubmit;
    HTMLFormElement.prototype.requestSubmit = function () { fixForm(this); return origReq.apply(this, arguments); };
  }

  // Titel melden
  var lastTitle = null;
  setInterval(function () { if (document.title !== lastTitle) { lastTitle = document.title; report(); } }, 800);
  report();
  document.addEventListener('DOMContentLoaded', report);

  // Tastenkürzel an die Oberfläche weitergeben
  window.addEventListener('keydown', function (e) {
    var ctrl = e.ctrlKey || e.metaKey;
    if ((ctrl && /^[tlw]$/i.test(e.key)) || (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight'))) {
      e.preventDefault();
      send({ type: 'key', key: e.key.toLowerCase(), ctrl: ctrl, alt: e.altKey });
    }
  }, true);
})();

/* ✦ Wer fünfmal schnell auf das Logo klickt, findet heraus, wer das gebaut hat. */
(function () {
  var clicks = 0, timer = null;
  document.addEventListener('click', function (e) {
    if (!e.target.closest || !e.target.closest('.sw-logo')) return;
    clicks++;
    clearTimeout(timer);
    timer = setTimeout(function () { clicks = 0; }, 1200);
    if (clicks < 5) return;
    clicks = 0;
    var t = document.createElement('div');
    t.textContent = '✦ SchoolWeb · made with ♥ by Darian';
    t.setAttribute('style', 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:99999;' +
      'padding:12px 22px;border-radius:999px;font:600 14px system-ui,sans-serif;color:#fff;' +
      'background:linear-gradient(90deg,#22d3ee,#8b5cf6,#f43f9e);box-shadow:0 10px 40px #8b5cf680;' +
      'transition:opacity .6s;opacity:0');
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.style.opacity = '1'; });
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 700); }, 3200);
  });
})();

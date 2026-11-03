const $ = (id) => document.getElementById(id);

function showMessage(text) {
  $('msg').textContent = text;
  $('msg').hidden = !text;
}

window.browserApi.loginInfo().then((info) => {
  $('server').value = info.server;
  $('username').value = info.username;
  if (info.fixedServer) { $('server-field').hidden = true; $('server').required = false; }
  showMessage(info.message);
  (info.fixedServer || info.server ? (info.username ? $('password') : $('username')) : $('server')).focus();
});

$('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('submit').disabled = true;
  $('submit').textContent = 'Anmelden …';
  showMessage('');
  const r = await window.browserApi.login({
    server: $('server').value, username: $('username').value.trim(), password: $('password').value,
  });
  if (r && r.error) {
    showMessage(r.error);
    $('submit').disabled = false;
    $('submit').textContent = 'Anmelden';
    $('password').select();
  }
});

$('register').addEventListener('click', (e) => {
  e.preventDefault();
  if (!$('server').value.trim()) { showMessage('Bitte zuerst die Server-Adresse eintragen.'); $('server').focus(); return; }
  window.browserApi.openRegister($('server').value);
});

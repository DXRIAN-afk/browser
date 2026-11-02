const { app, BrowserWindow, Menu } = require('electron');
const path = require('path');

function createWindow() {
  const win = new BrowserWindow({
    width: 1280,
    height: 800,
    title: 'Browser',
    backgroundColor: '#1e1f22',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      webviewTag: true,       // <webview> = eigener Chromium-Tab
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  win.loadFile(path.join(__dirname, 'index.html'));
  Menu.setApplicationMenu(null);
}

// Neue Fenster (target=_blank, window.open) als neuen Tab statt Popup öffnen
app.on('web-contents-created', (_e, contents) => {
  if (contents.getType() === 'webview') {
    contents.setWindowOpenHandler(({ url }) => {
      const host = contents.hostWebContents;
      if (host) host.send('open-tab', url);
      return { action: 'deny' };
    });
  }
  contents.on('will-attach-webview', (_ev, webPreferences) => {
    // Seiten dürfen keine Node-Rechte in Webviews erzwingen
    delete webPreferences.preload;
    webPreferences.nodeIntegration = false;
    webPreferences.contextIsolation = true;
  });
});

app.whenReady().then(createWindow);
app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) createWindow();
});

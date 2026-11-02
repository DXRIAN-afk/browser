const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('browserApi', {
  onOpenTab: (cb) => ipcRenderer.on('open-tab', (_e, url) => cb(url)),
});

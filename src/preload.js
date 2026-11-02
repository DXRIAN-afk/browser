const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('browserApi', {
  onOpenTab: (cb) => ipcRenderer.on('open-tab', (_e, url) => cb(url)),
  loginInfo: () => ipcRenderer.invoke('login-info'),
  login: (data) => ipcRenderer.invoke('login', data),
  openRegister: (server) => ipcRenderer.invoke('open-register', server),
  userInfo: () => ipcRenderer.invoke('user-info'),
  logout: () => ipcRenderer.invoke('logout'),
});

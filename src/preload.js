const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('browserApi', {
  onOpenTab: (cb) => ipcRenderer.on('open-tab', (_e, url) => cb(url)),
  loginInfo: () => ipcRenderer.invoke('login-info'),
  login: (data) => ipcRenderer.invoke('login', data),
  openRegister: (server) => ipcRenderer.invoke('open-register', server),
  userInfo: () => ipcRenderer.invoke('user-info'),
  logout: () => ipcRenderer.invoke('logout'),
  onDownload: (cb) => ipcRenderer.on('download', (_e, d) => cb(d)),
  downloadAction: (id, what) => ipcRenderer.invoke('download-action', id, what),
  api: async (action, body) => {
    const r = await ipcRenderer.invoke('api', action, body);
    if (r && r.__error) throw new Error(r.__error);
    return r;
  },
});

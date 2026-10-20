/**
 * preload.js — único puente entre la página y la aplicación de escritorio.
 * Expone SOLO tres funciones; la página no recibe `require`, `process` ni acceso a archivos.
 */
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('escritorio', Object.freeze({
  /** Vuelve a intentar cargar la plataforma (pantalla sin conexión). */
  reintentar: () => ipcRenderer.invoke('escritorio:reintentar'),
  /** Versión de la aplicación y del servidor al que se conecta. */
  informacion: () => ipcRenderer.invoke('escritorio:informacion'),
  /** Abre la configuración de la dirección (solo desde la pantalla sin conexión). */
  abrirAyuda: () => ipcRenderer.invoke('escritorio:ayuda'),
}));

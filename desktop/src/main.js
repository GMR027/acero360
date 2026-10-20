/**
 * main.js — proceso principal de la aplicación de escritorio.
 *
 * Es SOLO una ventana segura hacia la plataforma web: no tiene base de datos propia ni servicios
 * locales, así que lo que ves es exactamente lo que hay en el servidor.
 *
 * Servicios que deben seguir disponibles en el servidor (no están en esta app):
 *   aplicación PHP + MySQL, almacén de documentos fiscales, generación de PDF/Excel y correo.
 *
 * Dirección de la plataforma (en este orden):
 *   1. variable de entorno COTIZACIONES_URL
 *   2. archivo config.json en la carpeta de datos del usuario ({"url": "https://..."})
 *   3. producción: https://cotizaciones.iguzman.com.mx
 */
const { app, BrowserWindow, Menu, ipcMain, session, shell, dialog } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const { URL_PRODUCCION, validarUrlBase, mismoOrigen, esExternoSeguro, PREFERENCIAS_SEGURAS } = require('./seguridad');

const MODO_DESARROLLO = process.env.COTIZACIONES_DEV === '1';
const PARTICION = 'persist:cotizaciones';

let ventana = null;
let plataforma = { url: URL_PRODUCCION + '/', origen: URL_PRODUCCION };
let avisoConfiguracion = null;

/** Lee y valida la dirección configurada; si no es válida, usa producción y lo avisa. */
function resolverPlataforma() {
  const candidatos = [];
  if (process.env.COTIZACIONES_URL) {
    candidatos.push(['variable de entorno COTIZACIONES_URL', process.env.COTIZACIONES_URL]);
  }
  try {
    const archivo = path.join(app.getPath('userData'), 'config.json');
    if (fs.existsSync(archivo)) {
      candidatos.push(['config.json', JSON.parse(fs.readFileSync(archivo, 'utf8')).url]);
    }
  } catch (error) {
    avisoConfiguracion = 'No se pudo leer config.json; se usa la dirección de producción.';
  }

  for (const [fuente, valor] of candidatos) {
    const resultado = validarUrlBase(valor);
    if (resultado.ok) {
      return { url: resultado.url, origen: resultado.origen };
    }
    avisoConfiguracion = `La dirección de ${fuente} no es válida (${resultado.error}); se usa la de producción.`;
  }

  return { url: URL_PRODUCCION + '/', origen: URL_PRODUCCION };
}

function preferenciasVentana() {
  return {
    ...PREFERENCIAS_SEGURAS,
    preload: path.join(__dirname, 'preload.js'),
    partition: PARTICION,
    devTools: MODO_DESARROLLO,
  };
}

function cargarPlataforma() {
  if (ventana && !ventana.isDestroyed()) {
    ventana.loadURL(plataforma.url).catch(() => { /* did-fail-load muestra la pantalla sin conexión */ });
  }
}

function mostrarSinConexion(motivo) {
  if (!ventana || ventana.isDestroyed()) {
    return;
  }
  ventana.loadFile(path.join(__dirname, 'sin-conexion.html'), { query: { url: plataforma.url, motivo: motivo || 'desconocido' } });
}

function crearVentana() {
  ventana = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 360,
    minHeight: 480,
    title: 'Cotizaciones',
    backgroundColor: '#ededed',
    show: false,
    autoHideMenuBar: false,
    webPreferences: preferenciasVentana(),
  });

  ventana.once('ready-to-show', () => ventana.show());

  const contenido = ventana.webContents;
  contenido.setUserAgent(`${contenido.getUserAgent()} CotizacionesEscritorio/${app.getVersion()}`);

  // Fallo de carga del documento principal (sin red, DNS, certificado inválido, servidor caído…).
  contenido.on('did-fail-load', (_evento, codigo, descripcion, _url, esPrincipal) => {
    if (esPrincipal && codigo !== -3) { // -3 = cancelada por una navegación nueva
      mostrarSinConexion(`${descripcion} (${codigo})`);
    }
  });
  contenido.on('render-process-gone', (_evento, detalle) => mostrarSinConexion('La página dejó de responder: ' + detalle.reason));

  cargarPlataforma();
}

/* ---- Endurecimiento global: se aplica a TODA ventana o contenido que se cree ---- */
app.on('web-contents-created', (_evento, contenido) => {
  contenido.on('will-attach-webview', (evento) => evento.preventDefault());

  // La ventana nunca sale del origen de la plataforma.
  contenido.on('will-navigate', (evento, destino) => {
    if (!mismoOrigen(destino, plataforma.origen)) {
      evento.preventDefault();
      if (esExternoSeguro(destino)) {
        shell.openExternal(destino);
      }
    }
  });

  // window.open / target=_blank: mismo origen → ventana hija con las MISMAS preferencias seguras; otro origen → navegador del sistema.
  contenido.setWindowOpenHandler(({ url }) => {
    if (mismoOrigen(url, plataforma.origen)) {
      return { action: 'allow', overrideBrowserWindowOptions: { width: 1000, height: 760, webPreferences: preferenciasVentana() } };
    }
    if (esExternoSeguro(url)) {
      shell.openExternal(url);
    }
    return { action: 'deny' };
  });
});

app.on('certificate-error', (evento, _contenido, _url, _error, _certificado, callback) => {
  evento.preventDefault();
  callback(false); // nunca se aceptan certificados inválidos
});

/* ---- Pantalla sin conexión: solo tres llamadas, todas de lectura o reintento ---- */
ipcMain.handle('escritorio:reintentar', () => { cargarPlataforma(); return true; });
ipcMain.handle('escritorio:informacion', () => ({ version: app.getVersion(), servidor: plataforma.origen, electron: process.versions.electron }));
ipcMain.handle('escritorio:ayuda', () => dialog.showMessageBox(ventana, {
  type: 'info', title: 'Dirección de la plataforma',
  message: `Servidor: ${plataforma.origen}`,
  detail: 'Para cambiarla crea config.json en ' + app.getPath('userData') + ' con {"url": "https://tu-servidor"}.',
}));

function crearMenu() {
  const plantilla = [
    { label: 'Archivo', submenu: [
      { label: 'Recargar', accelerator: 'CmdOrCtrl+R', click: () => cargarPlataforma() },
      { label: 'Abrir en el navegador', click: () => shell.openExternal(plataforma.url) },
      { type: 'separator' },
      { label: 'Salir', accelerator: 'CmdOrCtrl+Q', role: 'quit' },
    ] },
    { label: 'Ver', submenu: [
      { label: 'Acercar', role: 'zoomIn' }, { label: 'Alejar', role: 'zoomOut' }, { label: 'Tamaño normal', role: 'resetZoom' },
      { type: 'separator' }, { label: 'Pantalla completa', role: 'togglefullscreen' },
    ] },
    { label: 'Ayuda', submenu: [
      { label: 'Acerca de Cotizaciones', click: () => dialog.showMessageBox(ventana, {
        type: 'info', title: 'Cotizaciones', message: `Cotizaciones ${app.getVersion()}`,
        detail: `Conectada a ${plataforma.origen}\nElectron ${process.versions.electron}\nLos datos viven en el servidor; esta aplicación no guarda una base de datos propia.`,
      }) },
    ] },
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(plantilla));
}

/* ---- Arranque: una sola instancia ---- */
if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.on('second-instance', () => {
    if (ventana) {
      if (ventana.isMinimized()) { ventana.restore(); }
      ventana.focus();
    }
  });

  app.whenReady().then(() => {
    plataforma = resolverPlataforma();

    // Se niegan todos los permisos del navegador (cámara, micrófono, ubicación, notificaciones…).
    const sesion = session.fromPartition(PARTICION);
    sesion.setPermissionRequestHandler((_c, _permiso, callback) => callback(false));
    sesion.setPermissionCheckHandler(() => false);

    crearMenu();
    crearVentana();

    if (avisoConfiguracion) {
      dialog.showMessageBox(ventana, { type: 'warning', title: 'Configuración', message: avisoConfiguracion });
    }

    app.on('activate', () => { if (BrowserWindow.getAllWindows().length === 0) { crearVentana(); } });
  });

  app.on('window-all-closed', () => app.quit());
}

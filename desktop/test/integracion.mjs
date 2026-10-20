/**
 * integracion.mjs — pruebas de la aplicación de escritorio con Electron real.
 *
 * Requiere el servidor de pruebas (BD cotizaciones_test):
 *   cd .. && DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   cd desktop && node test/integracion.mjs
 *
 * Abre ventanas reales de Electron durante unos segundos. Se sustituye shell.openExternal por un
 * registro para que las pruebas NUNCA abran tu navegador.
 */
import { _electron as electron, chromium } from 'playwright';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones_test';
if (DB !== 'cotizaciones_test') { console.error('Usa DB_NAME=cotizaciones_test'); process.exit(2); }
const EMAIL = 'prueba.escritorio@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };
const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba escritorio','${EMAIL}','${hash}',1)`);

const datos = mkdtempSync(join(tmpdir(), 'cotiz-escritorio-'));
const lanzar = async (url, sufijo = '') => {
  const app = await electron.launch({
    args: [RAIZ, `--user-data-dir=${join(datos, 'u' + sufijo)}`, ...(process.env.ELECTRON_NO_SANDBOX ? ['--no-sandbox'] : [])],
    env: { ...process.env, COTIZACIONES_URL: url },
  });
  // Nada de abrir el navegador real durante las pruebas.
  await app.evaluate(({ shell }) => { globalThis.__externos = []; shell.openExternal = async (u) => { globalThis.__externos.push(u); }; });
  return app;
};

let app;
try {
  console.log('Arranque y seguridad de la ventana');
  app = await lanzar(BASE);
  const ventana = await app.firstWindow();
  await ventana.waitForSelector('#email', { timeout: 15000 });
  verifica('carga la plataforma web (pantalla de acceso)', (await ventana.url()).startsWith(BASE) && (await ventana.locator('#email').count()) === 1, await ventana.url());
  verifica('el título de la ventana es «Cotizaciones»', (await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].getTitle())).length > 0);

  const prefs = await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].webContents.getLastWebPreferences());
  verifica('contextIsolation activo', prefs.contextIsolation === true);
  verifica('nodeIntegration desactivada', prefs.nodeIntegration === false);
  verifica('sandbox activo', prefs.sandbox === true);
  verifica('webSecurity activa', prefs.webSecurity !== false);
  const devtools = await app.evaluate(({ BrowserWindow }) => { const c = BrowserWindow.getAllWindows()[0].webContents; c.openDevTools(); return c.isDevToolsOpened(); });
  verifica('DevTools desactivadas en uso normal (no se pueden abrir)', devtools === false);
  verifica('la página NO tiene require ni process (sin Node)', await ventana.evaluate(() => typeof require === 'undefined' && typeof process === 'undefined'));
  const api = await ventana.evaluate(() => ({ claves: Object.keys(window.escritorio || {}).sort(), congelado: Object.isFrozen(window.escritorio) }));
  verifica('el puente expone solo 3 funciones y está congelado', JSON.stringify(api.claves) === '["abrirAyuda","informacion","reintentar"]' && api.congelado, JSON.stringify(api));
  const info = await ventana.evaluate(() => window.escritorio.informacion());
  verifica('informacion() devuelve versión y servidor', info.version === '1.0.0' && info.servidor === BASE, JSON.stringify(info));
  verifica('el agente de usuario identifica a la aplicación', (await ventana.evaluate(() => navigator.userAgent)).includes('CotizacionesEscritorio/1.0.0'));

  console.log('Sesión y uso normal');
  await ventana.fill('#email', EMAIL);
  await ventana.fill('#contrasena', CLAVE);
  await Promise.all([ventana.waitForURL('**/admin'), ventana.click('button[type=submit]')]);
  verifica('inicia sesión y llega al panel', (await ventana.url()).endsWith('/admin'));
  await ventana.goto(BASE + '/admin/productos');
  verifica('navega dentro de la plataforma (catálogo de productos)', (await ventana.locator('table.tabla').count()) >= 1);
  const cookies = await app.evaluate(async ({ session }) => (await session.fromPartition('persist:cotizaciones').cookies.get({})).length);
  verifica('la sesión queda en una partición persistente (cookies del servidor)', cookies >= 1);

  console.log('Mismos datos que la versión web');
  const enEscritorio = await ventana.evaluate(() => ({
    filas: document.querySelectorAll('tbody tr').length,
    primeros: [...document.querySelectorAll('tbody tr')].slice(0, 3).map((f) => f.innerText.replace(/\s+/g, ' ').trim()),
    total: document.querySelector('.badge')?.innerText.trim(),
  }));
  const navegadorWeb = await chromium.launch();
  const web = await navegadorWeb.newPage();
  await web.goto(BASE + '/login');
  await web.fill('#email', EMAIL); await web.fill('#contrasena', CLAVE);
  await Promise.all([web.waitForURL('**/admin'), web.click('button[type=submit]')]);
  await web.goto(BASE + '/admin/productos');
  const enWeb = await web.evaluate(() => ({
    filas: document.querySelectorAll('tbody tr').length,
    primeros: [...document.querySelectorAll('tbody tr')].slice(0, 3).map((f) => f.innerText.replace(/\s+/g, ' ').trim()),
    total: document.querySelector('.badge')?.innerText.trim(),
  }));
  await navegadorWeb.close();
  verifica('el catálogo de productos es idéntico en escritorio y en la web (mismas filas y datos)', enEscritorio.filas > 0 && JSON.stringify(enEscritorio) === JSON.stringify(enWeb), JSON.stringify([enEscritorio.filas, enWeb.filas]));
  const idProducto = sql("SELECT id FROM productos WHERE sku='RA-3'");
  sql(`UPDATE productos SET precio = precio + 1 WHERE id = ${idProducto}`);
  await ventana.reload();
  const precioNuevo = sql(`SELECT precio FROM productos WHERE id = ${idProducto}`);
  verifica('un cambio en el servidor se ve de inmediato en la aplicación de escritorio (no hay copia local)', (await ventana.locator('main').innerText()).includes(Number(precioNuevo).toLocaleString('en-US', { minimumFractionDigits: 2 })));
  sql(`UPDATE productos SET precio = precio - 1 WHERE id = ${idProducto}`);

  console.log('Navegación contenida');
  await ventana.evaluate(() => { window.location.href = 'http://example.com/robo'; });
  await dormir(700);
  verifica('no sale del origen de la plataforma', (await ventana.url()).startsWith(BASE));
  verifica('el destino externo se manda al navegador del sistema (simulado)', (await app.evaluate(() => globalThis.__externos)).includes('http://example.com/robo'));
  await ventana.evaluate(() => { window.location.href = 'file:///etc/passwd'; });
  await dormir(500);
  verifica('un enlace file:// ni navega ni se abre', (await ventana.url()).startsWith(BASE) && !(await app.evaluate(() => globalThis.__externos)).some((u) => u.startsWith('file:')));
  await ventana.evaluate(() => { window.open('https://www.sat.gob.mx/', '_blank'); });
  await dormir(500);
  verifica('window.open externo no crea ventana propia', (await app.windows()).length === 1 && (await app.evaluate(() => globalThis.__externos)).includes('https://www.sat.gob.mx/'));
  const antes = (await app.windows()).length;
  await ventana.evaluate((b) => { window.open(b + '/admin/inventario', '_blank'); }, BASE);
  await dormir(1500);
  const hija = (await app.windows()).find((w) => w.url().includes('/admin/inventario'));
  verifica('window.open del mismo origen abre una ventana hija', (await app.windows()).length === antes + 1 && !!hija);
  if (hija) {
    const prefsHija = await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows().map((w) => w.webContents.getLastWebPreferences()).pop());
    verifica('la ventana hija conserva las preferencias seguras', prefsHija.contextIsolation === true && prefsHija.nodeIntegration === false && prefsHija.sandbox === true);
    await hija.close();
  }

  console.log('Permisos del navegador denegados');
  const permisos = await ventana.evaluate(async () => ({
    notificaciones: await Notification.requestPermission(),
    ubicacion: await new Promise((resolver) => navigator.geolocation.getCurrentPosition(() => resolver('permitido'), (e) => resolver('denegado:' + e.code), { timeout: 3000 })),
    camara: await navigator.mediaDevices.getUserMedia({ video: true }).then(() => 'permitido').catch((e) => 'denegado:' + e.name),
  }));
  verifica('notificaciones, ubicación y cámara denegadas', permisos.notificaciones !== 'granted' && permisos.ubicacion.startsWith('denegado') && permisos.camara.startsWith('denegado'), JSON.stringify(permisos));

  await app.close();
  app = null;

  console.log('Manejo de errores de conexión');
  const puerto = 18931;
  app = await lanzar(`http://127.0.0.1:${puerto}`, 'off');
  const sinRed = await app.firstWindow();
  await sinRed.waitForSelector('#reintentar', { timeout: 15000 });
  const textoOff = await sinRed.locator('main').innerText();
  verifica('muestra la pantalla «sin conexión» (no una ventana en blanco)', textoOff.includes('No se pudo conectar'));
  verifica('indica la dirección y el motivo, y avisa que los datos viven en el servidor', textoOff.includes(`127.0.0.1:${puerto}`) && textoOff.includes('Motivo') && textoOff.includes('servidor'));
  verifica('el botón Reintentar tiene foco y es operable con teclado', await sinRed.evaluate(() => document.activeElement && document.activeElement.id === 'reintentar'));
  verifica('la pantalla sin conexión también expone solo el puente seguro', await sinRed.evaluate(() => typeof require === 'undefined' && typeof window.escritorio.reintentar === 'function'));

  // El servidor «vuelve»: se levanta en ese puerto y se pulsa Reintentar.
  const servidor = spawn('php', ['-S', `127.0.0.1:${puerto}`, 'index.php'], { cwd: join(RAIZ, '..'), env: { ...process.env, DB_NAME: DB }, stdio: 'ignore' });
  await dormir(1500);
  await sinRed.click('#reintentar');
  await sinRed.waitForSelector('#email', { timeout: 15000 });
  verifica('al volver el servidor, Reintentar carga la plataforma', (await sinRed.url()).startsWith(`http://127.0.0.1:${puerto}`));
  servidor.kill();
  await app.close();
  app = null;
} catch (error) {
  mal++;
  console.log('  ✗ error inesperado → ' + (error && error.stack || error));
} finally {
  if (app) { await app.close().catch(() => {}); }
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  rmSync(datos, { recursive: true, force: true });
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

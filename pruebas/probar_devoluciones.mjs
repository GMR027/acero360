/**
 * pruebas/probar_devoluciones.mjs — Devoluciones y cambios desde la pantalla de la venta, con doble clic.
 *   DB_NAME=cotizaciones_test node pruebas/probar_devoluciones.mjs   (servidor en :8017)
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

/** Las pruebas que mandan POST «a mano» deben llevar la llave anti-falsificación (CSRF) de la sesión, como lo hace el navegador. */
async function postCsrf(pagina, url, opciones = {}) {
  const token = await pagina.evaluate(() => (document.querySelector('meta[name="csrf"]') || {}).content || '');
  return pagina.request.post(url, { ...opciones, headers: { ...(opciones.headers || {}), 'X-CSRF-Token': token } });
}


const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.devol@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const RAIZ = new URL('..', import.meta.url).pathname;
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const limpiar = () => {
  const u = sql(`SELECT IFNULL(MAX(id),0) FROM usuarios WHERE email='${EMAIL}'`);
  sql(`DELETE FROM venta_cambios_entregados WHERE devolucion_id IN (SELECT id FROM venta_devoluciones WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u}))`);
  sql(`DELETE FROM venta_devoluciones WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM movimientos_inventario WHERE usuario_id=${u} OR venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM ingresos WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM ventas WHERE usuario_id=${u}`);
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
};
limpiar();
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba devol','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');
sql('UPDATE productos SET stock = 100 WHERE id = 37');
execFileSync('php', ['-r', `require '${RAIZ}/includes/app.php';
App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>0,'descuento_pct'=>0],[37=>['cantidad'=>10,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`], { env: { ...process.env, DB_NAME: DB } });
const venta = sql('SELECT MAX(id) FROM ventas');
const stock = () => parseInt(sql('SELECT stock FROM productos WHERE id = 37'), 10);
const s0 = stock();

const navegador = await chromium.launch();
try {
  const pagina = await (await navegador.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Formulario');
  await pagina.goto(`${BASE}/admin/ventas/devolucion?id=${venta}`);
  verifica('no pide motivo obligatorio', (await pagina.locator('#motivo').count()) === 0 && (await pagina.locator('#nota[required]').count()) === 0);
  verifica('pide fecha, cantidad devuelta y cantidad a cambiar', (await pagina.locator('#fecha').count()) === 1
    && (await pagina.locator('input[name="cantidad[37]"]').count()) === 1 && (await pagina.locator('input[name="cambio[37]"]').count()) === 1);

  console.log('Doble clic = una sola devolución');
  await pagina.fill('input[name="cantidad[37]"]', '2');
  await pagina.fill('input[name="cambio[37]"]', '2');
  await Promise.all([pagina.waitForURL('**/admin/ventas/detalle?id=*'), pagina.dblclick('button[data-un-envio]')]);
  verifica('queda un solo registro', sql(`SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id=${venta}`) === '1');
  verifica('las 2 piezas suman al inventario', stock() === s0 + 2, `${s0} → ${stock()}`);
  verifica('se guardó con usuario y sin motivo', sql(`SELECT CONCAT(usuario_id,'|',IFNULL(nota,'NULL')) FROM venta_devoluciones WHERE venta_id=${venta}`) === `${usuario}|NULL`);

  console.log('Reenvío del mismo formulario (recarga)');
  const r = await postCsrf(pagina, `${BASE}/admin/ventas/devolucion`, { form: { id: venta, 'cantidad[37]': '2', 'cambio[37]': '2', fecha: new Date().toISOString().slice(0, 10), token: 'viejo' }, maxRedirects: 0 });
  verifica('un token ya usado o inventado no registra nada', sql(`SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id=${venta}`) === '1' && stock() === s0 + 2);

  console.log('La venta muestra lo que se debe');
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${venta}`);
  const texto = await pagina.locator('#cambios').innerText();
  verifica('apartado «Se le deben al cliente: 2 ×»', /Se le deben al cliente/i.test(texto) && /2 ×/.test(texto), texto.slice(0, 120));

  console.log('Aparece en la campana');
  await pagina.click('[data-campana-boton]');
  await pagina.waitForSelector('.campana-aviso');
  verifica('aviso «Cambio por entregar»', (await pagina.locator('.campana-aviso', { hasText: 'Cambio por entregar' }).count()) >= 1);

  console.log('Entrega del cambio (parcial y total)');
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${venta}`);
  await pagina.fill('input[name="cantidad"]', '1');
  await Promise.all([pagina.waitForURL('**/admin/ventas/detalle?id=*'), pagina.click('button:has-text("Marcar cambio entregado")')]);
  verifica('entrega parcial descuenta 1', stock() === s0 + 1, String(stock()));
  verifica('sigue debiendo 1', /Se le deben al cliente:\s*1 ×/.test(await pagina.locator('#cambios').innerText()));
  await Promise.all([pagina.waitForURL('**/admin/ventas/detalle?id=*'), pagina.click('button:has-text("Marcar cambio entregado")')]);
  verifica('entrega total: stock vuelve a lo vendido', stock() === s0, String(stock()));
  verifica('ya no se debe nada', (await pagina.locator('#cambios').innerText()).includes('Se le deben al cliente') === false);
  verifica('quedó el historial', (await pagina.locator('#cambios tbody tr').count()) === 1);

  console.log('Tope');
  await pagina.goto(`${BASE}/admin/ventas/devolucion?id=${venta}`);
  await pagina.evaluate(() => { const i = document.querySelector('input[name="cantidad[37]"]'); i.removeAttribute('max'); });
  await pagina.fill('input[name="cantidad[37]"]', '9');
  await pagina.click('button[data-un-envio]');
  await pagina.waitForSelector('.nota--error');
  verifica('rechaza devolver más de lo disponible (8)', (await pagina.content()).includes('No se pueden devolver más piezas'));

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of [`/admin/ventas/devolucion?id=${venta}`, `/admin/ventas/detalle?id=${venta}`]) {
    await pagina.goto(BASE + ruta);
    verifica(`sin desborde horizontal en ${ruta.split('?')[0]}`, !(await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
  }
} finally {
  limpiar();
  await navegador.close();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

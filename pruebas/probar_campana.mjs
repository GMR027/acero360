/**
 * pruebas/probar_campana.mjs — Campana de avisos: contador, panel, marcar leído, abrir registro, sin duplicados.
 *   DB_NAME=cotizaciones_test node pruebas/probar_campana.mjs   (servidor en :8017)
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
const EMAIL = 'prueba.campana@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba campana','${EMAIL}','${hash}',1)`);
sql('DELETE FROM seguimiento_lecturas');
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
sql("DELETE FROM cotizaciones WHERE folio LIKE 'CAMP-%'");
sql(`INSERT INTO cotizaciones (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total, vigencia_dias)
     VALUES ('CAMP-1', 1, ${cliente}, ${usuario}, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'pendiente', 100, 0, 0, 100, 15)`);

const navegador = await chromium.launch();
try {
  const pagina = await (await navegador.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  const boton = pagina.locator('[data-campana-boton]');
  const badge = pagina.locator('[data-campana-n]');
  const panel = pagina.locator('[data-campana-panel]');
  verifica('la campana es visible', await boton.isVisible());
  const n0 = parseInt((await badge.innerText()).replace('+', ''), 10);
  verifica('el indicador muestra avisos sin leer', n0 > 0, String(n0));
  verifica('el panel empieza cerrado', await panel.isHidden());

  await boton.click();
  await pagina.waitForSelector('.campana-aviso');
  verifica('el panel se abre', await panel.isVisible());
  verifica('aria-expanded = true', (await boton.getAttribute('aria-expanded')) === 'true');
  const items = pagina.locator('.campana-aviso');
  verifica('lista avisos recientes (máx. 8)', (await items.count()) > 0 && (await items.count()) <= 8);
  const primero = (await items.first().innerText());
  verifica('cada aviso lleva el nivel escrito, no solo color', /Urgente|Alta|Normal|Informativa/i.test(primero), primero);
  const claves = await pagina.$$eval('[data-abrir]', (e) => e.map((x) => x.dataset.abrir));
  verifica('sin avisos repetidos en el panel', new Set(claves).size === claves.length);

  // Marcar uno como leído → baja el contador
  await pagina.locator("[data-leer]").last().click();
  await pagina.waitForFunction((n) => { const b = document.querySelector('[data-campana-n]'); return b.hidden || parseInt(b.textContent, 10) < n; }, n0);
  const n1 = (await badge.isHidden()) ? 0 : parseInt((await badge.innerText()).replace('+', ''), 10);
  verifica('marcar como leído baja el contador', n1 === n0 - 1, `${n0} → ${n1}`);

  // Recargar no duplica ni revive lo leído
  await pagina.reload();
  const n2 = (await badge.isHidden()) ? 0 : parseInt((await badge.innerText()).replace('+', ''), 10);
  verifica('recargar la página conserva el estado (sin duplicar)', n2 === n1, `${n1} → ${n2}`);

  // Abrir el registro de la cotización
  await boton.click();
  await pagina.waitForSelector('[data-abrir="cotizacion:' + sql("SELECT id FROM cotizaciones WHERE folio='CAMP-1'") + '"]');
  await Promise.all([pagina.waitForURL('**/admin/cotizaciones/detalle?id=*'), pagina.click('[data-abrir^="cotizacion:"]')]);
  verifica('«Abrir cotización» lleva al registro', pagina.url().includes('/admin/cotizaciones/detalle'));

  // Marcar todo
  await pagina.locator('[data-campana-boton]').click();
  await pagina.locator('[data-campana-todas]').click();
  await pagina.waitForFunction(() => document.querySelector('[data-campana-n]').hidden);
  verifica('«Marcar todo como leído» deja el contador en cero', await badge.isHidden());

  // Escape cierra
  await pagina.keyboard.press('Escape');
  verifica('Escape cierra el panel', await panel.isHidden());

  // POST sin el encabezado propio se rechaza
  const r = await postCsrf(pagina, BASE + '/admin/seguimiento/campana', { form: { todas: '1' } });
  verifica('POST ajeno (sin encabezado) → 400', r.status() === 400, String(r.status()));

  // Móvil: sigue visible
  await pagina.setViewportSize({ width: 375, height: 700 });
  await pagina.goto(BASE + '/admin');
  verifica('la campana también se ve en pantalla de teléfono', await pagina.locator('[data-campana-boton]').isVisible());
} finally {
  sql("DELETE FROM cotizaciones WHERE folio LIKE 'CAMP-%'");
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  await navegador.close();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

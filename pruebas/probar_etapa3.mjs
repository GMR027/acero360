/**
 * probar_etapa3.mjs — ventas unificadas en el navegador: listado único,
 * enlace cotización ↔ venta, devolución y pantallas sin desborde en móvil.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa3.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa3@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E3','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const cliente = sql('SELECT id FROM clientes LIMIT 1');

function limpiar() {
  sql("DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL");
  for (const t of ['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM documentos'); sql('DELETE FROM cobros'); sql('DELETE FROM ingresos');
  sql('DELETE FROM entregas'); sql('DELETE FROM preparaciones');
  sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
}
limpiar();
sql('UPDATE productos SET stock = 100 WHERE id = 37');
sql(`INSERT INTO cotizaciones (folio,empresa_id,cliente_id,usuario_id,fecha,estado,subtotal,descuento,aplica_iva,iva,total)
     VALUES ('FOL-UI-0001',1,${cliente},${usuario},CURDATE(),'pendiente',200,0,1,32,232)`);
const cot = sql("SELECT id FROM cotizaciones WHERE folio='FOL-UI-0001'");
sql(`INSERT INTO cotizacion_detalles (cotizacion_id,producto_id,cantidad,precio_unitario,descuento_pct,subtotal) VALUES (${cot},37,4,50,0,200)`);

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Marcar vendida desde el detalle de la cotización');
  await pagina.goto(`${BASE}/admin/cotizaciones/detalle?id=${cot}`);
  await pagina.click('.cotizador-estado-acciones button:has-text("Vendido")');
  const confirmar = pagina.locator('.modal button:has-text("Sí"), [role=dialog] button:has-text("Sí")');
  if (await confirmar.count()) { await confirmar.first().click(); }
  await pagina.waitForLoadState();
  verifica('cotización en estado vendido', sql(`SELECT estado FROM cotizaciones WHERE id=${cot}`) === 'vendido');
  verifica('una sola venta ligada', sql(`SELECT COUNT(*) FROM ventas WHERE cotizacion_id=${cot}`) === '1');
  verifica('stock bajó 4 una vez', sql('SELECT stock FROM productos WHERE id=37') === '96');

  await pagina.goto(`${BASE}/admin/cotizaciones/detalle?id=${cot}`);
  verifica('el detalle enlaza a la venta', (await pagina.locator('a:has-text("Venta VEN-")').count()) === 1);

  console.log('Listado de ventas');
  await pagina.goto(BASE + '/admin/ventas');
  verifica('aparece 1 sola vez la operación', (await pagina.locator('tbody tr').count()) === 1);
  verifica('etiquetada como cotización', (await pagina.locator('tbody .badge:has-text("Cotización")').count()) === 1);

  console.log('Devolución desde la venta');
  const ventaId = sql(`SELECT id FROM ventas WHERE cotizacion_id=${cot}`);
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${ventaId}`);
  verifica('indica «Desde cotización» y no ofrece editar', (await pagina.locator('.badge:has-text("Desde cotización")').count()) === 1
    && (await pagina.locator('a[href*="/admin/ventas/editar"]').count()) === 0);
  await pagina.click('a:has-text("Devolución")');
  await pagina.fill(`input[name="cantidad[37]"]`, '9');
  await pagina.fill('#motivo', 'Pieza defectuosa');
  await pagina.click('button[type=submit]');
  verifica('rechaza devolver más de lo vendido', (await pagina.content()).includes('No se pueden devolver más piezas'));
  await pagina.fill(`input[name="cantidad[37]"]`, '1');
  await pagina.fill('#motivo', 'Pieza defectuosa');
  await Promise.all([pagina.waitForURL('**/admin/ventas/detalle?id=*'), pagina.click('button[type=submit]')]);
  verifica('devolución suma 1 al stock', sql('SELECT stock FROM productos WHERE id=37') === '97');
  verifica('movimiento «devolucion» ligado a la venta', sql(`SELECT COUNT(*) FROM movimientos_inventario WHERE venta_id=${ventaId} AND motivo='devolucion'`) === '1');

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of ['/admin/ventas', `/admin/ventas/detalle?id=${ventaId}`, `/admin/ventas/devolucion?id=${ventaId}`]) {
    await pagina.goto(BASE + ruta);
    const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    verifica(ruta + ' @390px sin desborde', !desborde);
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  limpiar();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

/**
 * probar_etapa2.mjs — flujos de la Etapa 2 en un navegador real:
 *   productos en varios negocios, órdenes de compra con partidas dinámicas,
 *   recepción parcial, cierre con faltantes, alerta de cantidad mínima y
 *   PDF de inventario (un producto por negocio asignado).
 *
 * Uso (contra la BD de PRUEBA):
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa2.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa2@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (nombre, cond, detalle = '') => {
  if (cond) { ok++; console.log('  ✓ ' + nombre); } else { mal++; console.log('  ✗ ' + nombre + (detalle ? ' → ' + detalle : '')); }
};

/** Borra lo que esta prueba crea (producto E2-1 y sus órdenes), en orden de FK. */
function limpiar() {
  const pid = sql("SELECT id FROM productos WHERE sku='E2-1'");
  if (!pid) { return; }
  const ordenes = sql(`SELECT GROUP_CONCAT(DISTINCT orden_id) FROM orden_compra_partidas WHERE producto_id=${pid}`);
  sql(`DELETE FROM movimientos_inventario WHERE producto_id=${pid}`);
  sql(`DELETE FROM movimientos_por_terminar WHERE producto_id=${pid}`);
  if (ordenes && ordenes !== 'NULL') {
    sql(`DELETE FROM movimientos_inventario WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN (${ordenes}))`);
    sql(`DELETE FROM movimientos_por_terminar WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN (${ordenes}))`);
    sql(`DELETE FROM recepciones WHERE orden_id IN (${ordenes})`);
    sql(`DELETE FROM ordenes_compra WHERE id IN (${ordenes})`);
  }
  sql(`DELETE FROM productos WHERE id=${pid}`);
}
limpiar();

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E2','${EMAIL}','${hash}',1)`);

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

  console.log('Producto en varios negocios');
  await pagina.goto(BASE + '/admin/productos/crear');
  verifica('hay una casilla por negocio', (await pagina.locator('input[name="negocios[]"]').count()) === 2);
  await pagina.fill('#nombre', 'Registro E2 prueba');
  await pagina.fill('#sku', 'E2-1');
  await pagina.fill('#precio', '150');
  await pagina.fill('#costo_actual', '80');
  await pagina.fill('#stock_minimo', '5');
  await pagina.check('#negocio_1');
  await pagina.check('#negocio_2');
  await Promise.all([pagina.waitForURL('**/admin/productos'), pagina.click('button[type=submit]')]);
  const pid = sql("SELECT id FROM productos WHERE sku='E2-1'");
  verifica('producto creado', pid !== '');
  verifica('asignado a 2 negocios', sql(`SELECT COUNT(*) FROM producto_empresa WHERE producto_id=${pid}`) === '2');
  verifica('costo y mínimo guardados', sql(`SELECT CONCAT(costo_actual,'/',stock_minimo) FROM productos WHERE id=${pid}`) === '80.00/5');

  await pagina.goto(BASE + '/admin/productos/crear');
  await pagina.fill('#nombre', 'Duplicado');
  await pagina.fill('#sku', 'E2-1');
  await pagina.fill('#precio', '1');
  await pagina.check('#negocio_1');
  await Promise.all([pagina.waitForNavigation(), pagina.click('button[type=submit]')]);
  verifica('SKU duplicado se rechaza con mensaje', (await pagina.content()).includes('ya pertenece a otro producto'));

  console.log('Compra de semiterminados con partidas dinámicas');
  await pagina.goto(BASE + '/admin/compras/crear');
  const semi = pagina.locator('[data-bloque-tipo="semiterminado"]');
  const insumo = pagina.locator('[data-bloque-tipo="insumo"]');
  verifica('por defecto es semiterminado y el bloque de insumos está oculto', await semi.isVisible() && !(await insumo.isVisible()));
  await pagina.selectOption('#proveedor_id', { index: 1 });
  await semi.locator('select[name="producto_id[]"]').first().selectOption(pid);
  await semi.locator('input[name="cantidad[]"]').fill('5');
  await semi.locator('input[name="costo[]"]').fill('80');
  await semi.locator('[data-agregar-partida]').click();
  verifica('agregar producto crea otro renglón', (await semi.locator('[data-partida]').count()) === 2);
  const segundo = semi.locator('[data-partida]').nth(1);
  await segundo.locator('select[name="producto_id[]"]').selectOption({ index: 2 });
  await segundo.locator('[data-cantidad]').fill('3');
  await segundo.locator('[data-costo]').fill('10.50');
  const total = (await semi.locator('[data-total]').innerText()).replace(/\s/g, '');
  verifica('subtotal en vivo $431.50', total.includes('431.50'), total);
  await semi.locator('[data-partida]').nth(1).locator('[data-quitar-partida]').click();
  verifica('quitar renglón', (await semi.locator('[data-partida]').count()) === 1);
  await semi.locator('[data-agregar-partida]').click();
  await semi.locator('[data-partida]').nth(1).locator('select[name="producto_id[]"]').selectOption({ index: 2 });
  await semi.locator('[data-partida]').nth(1).locator('[data-cantidad]').fill('3');
  await semi.locator('[data-partida]').nth(1).locator('[data-costo]').fill('10.50');
  await Promise.all([pagina.waitForURL('**/admin/compras/detalle?id=*'), pagina.click('button[type=submit]')]);
  const oid = sql('SELECT MAX(id) FROM ordenes_compra');
  verifica('compra creada sin tocar inventario', sql(`SELECT stock FROM productos WHERE id=${pid}`) === '0');
  verifica('total esperado 431.50', sql(`SELECT total_esperado FROM ordenes_compra WHERE id=${oid}`) === '431.50');
  verifica('tipo semiterminado', sql(`SELECT tipo FROM ordenes_compra WHERE id=${oid}`) === 'semiterminado');

  console.log('Recepción parcial y cierre');
  await pagina.goto(BASE + '/admin/compras/recibir?id=' + oid);
  const cant = pagina.locator('input[name^="cantidad["]');
  await cant.nth(0).fill('5');
  await cant.nth(1).fill('1');
  await pagina.click('button[type=submit]');
  await pagina.waitForSelector('.modal button:has-text("Sí, confirmar recepción"), dialog button:has-text("Sí, confirmar recepción"), [role=dialog] button:has-text("Sí, confirmar recepción")', { timeout: 3000 }).catch(() => {});
  verifica('la recepción pide confirmación y aún no mueve existencias',
    (await pagina.locator('button:has-text("Sí, confirmar recepción")').count()) > 0
    && sql(`SELECT stock_por_terminar FROM productos WHERE id=${pid}`) === '0');
  await pagina.click('.modal button:has-text("Sí, confirmar recepción"), dialog button:has-text("Sí, confirmar recepción"), [role=dialog] button:has-text("Sí, confirmar recepción")');
  await pagina.waitForURL('**/admin/compras/detalle?id=*');
  verifica('lo recibido queda «por terminar» (5)', sql(`SELECT stock_por_terminar FROM productos WHERE id=${pid}`) === '5');
  verifica('NO sube la existencia para venta', sql(`SELECT stock FROM productos WHERE id=${pid}`) === '0');
  verifica('estado recibida parcial', sql(`SELECT estado FROM ordenes_compra WHERE id=${oid}`) === 'parcial');
  verifica('detalle muestra el faltante', (await pagina.content()).includes('Recibida parcial'));
  await pagina.fill('#motivo_cierre', 'No surtió');
  await pagina.click('form[action="/admin/compras/accion"] button:has-text("Cerrar compra")');
  await pagina.waitForSelector('.modal, dialog, [role=dialog]', { timeout: 3000 }).catch(() => {});
  verifica('cerrar pide confirmación modal (no confirm nativo)', (await pagina.locator('.modal, dialog, [role=dialog]').count()) > 0);
  await pagina.click('.modal button:has-text("Sí, cerrar"), dialog button:has-text("Sí, cerrar"), [role=dialog] button:has-text("Sí, cerrar")');
  await pagina.waitForLoadState();
  verifica('compra cerrada', sql(`SELECT estado FROM ordenes_compra WHERE id=${oid}`) === 'cerrada');

  console.log('Alerta de mínimo y PDF de inventario');
  sql(`UPDATE productos SET stock=3 WHERE id=${pid}`);
  await pagina.goto(BASE + '/admin/inventario');
  verifica('badge de stock bajo', (await pagina.locator('[data-alerta-minimo]').count()) >= 1);
  await pagina.goto(BASE + '/admin/inventario?estado=minimo');
  verifica('filtro «en o bajo el mínimo»', (await pagina.locator('[data-alerta-minimo]').count()) >= 1);
  const resp = await pagina.request.get(BASE + '/admin/inventario/pdf');
  writeFileSync(join(tmpdir(), 'inv-e2.pdf'), await resp.body());
  const texto = execFileSync('pdftotext', [join(tmpdir(), 'inv-e2.pdf'), '-'], { encoding: 'utf8' });
  verifica('PDF: producto compartido aparece 2 veces (1 por negocio)', (texto.match(/E2-1/g) || []).length === 2, String((texto.match(/E2-1/g) || []).length));

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of ['/admin/compras', '/admin/compras/crear', '/admin/compras/detalle?id=' + oid, '/admin/productos/crear']) {
    await pagina.goto(BASE + ruta);
    const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    verifica(ruta + ' @390px sin desborde horizontal', !desborde);
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  limpiar();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

/**
 * probar_compra_catalogo.mjs — Solicitud de compra desde el catálogo del proveedor, en un navegador real:
 * el selector muestra solo el catálogo activo del proveedor elegido, recupera nombre/código/costo, calcula importes
 * y total en vivo, permite cambiar el costo a propósito, y la compra conserva el costo con el que se hizo.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_compra_catalogo.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.compracat@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { if (c) { ok++; console.log('  ✓ ' + n); } else { mal++; console.log('  ✗ ' + n + (d ? ' → ' + d : '')); } };
const dinero = (t) => Number(String(t).replace(/[^0-9.]/g, ''));

function limpiar() {
  const o = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM ordenes_compra WHERE referencia LIKE 'CC-%'");
  if (o) { sql(`DELETE FROM ordenes_compra WHERE id IN (${o})`); }
  sql("DELETE FROM proveedor_productos WHERE nombre LIKE 'CC %'");
  sql("DELETE FROM proveedores WHERE nombre LIKE 'CC Proveedor%'");
  sql("DELETE FROM productos WHERE sku LIKE 'CC-%'");
}
limpiar();
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba CC','${EMAIL}','${hash}',1)`);
sql("INSERT INTO proveedores (nombre, activo) VALUES ('CC Proveedor Agustín', 1), ('CC Proveedor Otro', 1)");
const prov = sql("SELECT id FROM proveedores WHERE nombre='CC Proveedor Agustín'");
const otro = sql("SELECT id FROM proveedores WHERE nombre='CC Proveedor Otro'");
const empresa = sql('SELECT MIN(id) FROM empresas');
sql(`INSERT INTO productos (empresa_id, nombre, sku, precio) VALUES (${empresa}, 'CC Canastilla propia', 'CC-1', 300)`);
const propia = sql("SELECT id FROM productos WHERE sku='CC-1'");
sql(`INSERT INTO producto_empresa (producto_id, empresa_id) VALUES (${propia}, ${empresa})`);
sql(`INSERT INTO proveedor_productos (proveedor_id, nombre, codigo, costo_unitario, activo) VALUES
  (${prov}, 'CC Armazón', 'AG-1', 120.50, 1), (${prov}, 'CC Base', 'AG-2', 80.00, 1), (${prov}, 'CC Descontinuado', 'AG-3', 10, 0), (${otro}, 'CC Ajeno', 'OT-1', 5, 1)`);
const armazon = sql("SELECT id FROM proveedor_productos WHERE nombre='CC Armazón'");
const base = sql("SELECT id FROM proveedor_productos WHERE nombre='CC Base'");
sql(`INSERT INTO proveedor_producto_relaciones (proveedor_producto_id, producto_id) VALUES (${armazon}, ${propia})`);

const nav = await chromium.launch();
const p = await nav.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
p.on('pageerror', (e) => errores.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
const semi = p.locator('[data-bloque-tipo="semiterminado"]');
const fila = (n) => semi.locator('[data-partida]').nth(n);
const opciones = async (n) => (await fila(n).locator('[data-catalogo] option').allInnerTexts()).map((t) => t.trim());

try {
  await p.goto(BASE + '/login'); await p.fill('#email', EMAIL); await p.fill('#contrasena', CLAVE);
  await Promise.all([p.waitForURL('**/admin'), p.click('button[type=submit]')]);

  console.log('El catálogo depende del proveedor');
  await p.goto(BASE + '/admin/compras/crear');
  verifica('sin proveedor solo ofrece «Otro / fuera del catálogo»', (await opciones(0)).length === 1);
  await p.selectOption('#proveedor_id', prov);
  const o1 = await opciones(0);
  verifica('al elegir al proveedor muestra sus productos activos (con código)', o1.some((t) => t.includes('AG-1 · CC Armazón')) && o1.some((t) => t.includes('AG-2 · CC Base')));
  verifica('no muestra inactivos ni los de otro proveedor', !o1.some((t) => t.includes('Descontinuado') || t.includes('Ajeno')));

  console.log('Recuperación automática y cálculos');
  await fila(0).locator('[data-catalogo]').selectOption(armazon);
  verifica('rellena el nombre y lo bloquea', (await fila(0).locator('[data-descripcion]').inputValue()) === 'CC Armazón' && await fila(0).locator('[data-descripcion]').evaluate((e) => e.readOnly));
  verifica('muestra el código', (await fila(0).locator('[data-codigo]').innerText()).includes('AG-1'));
  verifica('trae el costo del proveedor y lo bloquea', (await fila(0).locator('[data-costo]').inputValue()) === '120.50' && await fila(0).locator('[data-costo]').evaluate((e) => e.readOnly));
  verifica('propone el único producto propio relacionado', (await fila(0).locator('select[name="producto_id[]"]').inputValue()) === propia);
  await fila(0).locator('[data-cantidad]').fill('10');
  verifica('importe de la partida = 10 × 120.50', dinero(await fila(0).locator('[data-importe]').innerText()) === 1205);
  await semi.locator('[data-agregar-partida]').click();
  verifica('la nueva partida también ofrece el catálogo', (await opciones(1)).some((t) => t.includes('CC Base')));
  await fila(1).locator('[data-catalogo]').selectOption(base);
  await fila(1).locator('[data-cantidad]').fill('3');
  verifica('subtotal = suma de importes (1205 + 240)', dinero(await semi.locator('[data-total]').innerText()) === 1445);
  verifica('total de la compra en vivo', dinero(await p.locator('[data-total-compra]').innerText()) === 1445);
  await p.click('[data-calcular-iva]');
  verifica('el IVA se calcula solo si se pide (16 % de 1445 = 231.20)', (await p.inputValue('#iva')) === '231.20' && dinero(await p.locator('[data-total-compra]').innerText()) === 1676.2);

  console.log('Cambiar el costo a propósito');
  await fila(1).locator('[data-cambiar-costo]').click();
  verifica('«Cambiar costo» libera el campo', !(await fila(1).locator('[data-costo]').evaluate((e) => e.readOnly)));
  await fila(1).locator('[data-costo]').fill('75');
  verifica('avisa cuál era el costo del catálogo', (await fila(1).locator('[data-aviso-costo]').innerText()).includes('80.00'));
  verifica('el total se recalcula (1205 + 225 + IVA)', dinero(await semi.locator('[data-total]').innerText()) === 1430);
  await p.fill('#referencia', 'CC-1');
  await p.fill('#iva', '228.80');
  await Promise.all([p.waitForURL('**/admin/compras/detalle?id=*'), p.click('button[type=submit]')]);
  const oid = sql("SELECT id FROM ordenes_compra WHERE referencia='CC-1'");

  console.log('Lo guardado');
  verifica('guarda detalle, cantidad, costo e importe de cada partida',
    sql(`SELECT GROUP_CONCAT(CONCAT(descripcion,'|',codigo,'|',CAST(cantidad_solicitada AS UNSIGNED),'|',costo_esperado,'|',importe_esperado) ORDER BY id SEPARATOR ';') FROM orden_compra_partidas WHERE orden_id=${oid}`)
      === 'CC Armazón|AG-1|10|120.50|1205.00;CC Base|AG-2|3|75.00|225.00');
  verifica('guarda además el costo del catálogo de ese momento', sql(`SELECT GROUP_CONCAT(costo_catalogo ORDER BY id) FROM orden_compra_partidas WHERE orden_id=${oid}`) === '120.50,80.00');
  verifica('total de la compra = subtotal + IVA (1430 + 228.80)', sql(`SELECT total_esperado+iva FROM ordenes_compra WHERE id=${oid}`) === '1658.80');
  const detalle = (await p.locator('main, body').first().innerText()).toLowerCase();
  verifica('el detalle muestra el código y marca el costo modificado', detalle.includes('ag-1') && detalle.includes('costo modificado') && detalle.includes('80.00'));
  verifica('el costo modificado quedó en la bitácora', sql(`SELECT COUNT(*) FROM auditoria WHERE entidad='orden_compra' AND entidad_id=${oid} AND accion='costo_modificado'`) === '1');

  console.log('El historial no cambia cuando cambia el catálogo');
  sql(`UPDATE proveedor_productos SET costo_unitario = 200 WHERE id=${armazon}`);
  await p.goto(BASE + '/admin/compras/detalle?id=' + oid);
  verifica('la compra anterior conserva su costo (120.50) y su total', sql(`SELECT costo_esperado FROM orden_compra_partidas WHERE orden_id=${oid} ORDER BY id LIMIT 1`) === '120.50' && (await p.content()).includes('1,205.00'));
  await p.goto(BASE + '/admin/compras/editar?id=' + oid);
  verifica('al editar, la partida muestra el costo con el que se compró (no el nuevo)', (await fila(0).locator('[data-costo]').inputValue()) === '120.50');
  verifica('el producto sigue seleccionado y su nombre bloqueado', (await fila(0).locator('[data-catalogo]').inputValue()) === armazon && (await fila(0).locator('[data-descripcion]').inputValue()) === 'CC Armazón');
  verifica('la partida con costo modificado queda editable y avisa el del catálogo', !(await fila(1).locator('[data-costo]').evaluate((e) => e.readOnly)) && (await fila(1).locator('[data-aviso-costo]').innerText()).includes('80.00'));
  await fila(0).locator('[data-cantidad]').fill('12');
  await Promise.all([p.waitForURL('**/admin/compras/detalle?id=*'), p.click('button[type=submit]')]);
  verifica('editar la cantidad conserva el costo original (12 × 120.50 = 1446)', sql(`SELECT CONCAT(costo_esperado,'|',importe_esperado) FROM orden_compra_partidas WHERE orden_id=${oid} ORDER BY id LIMIT 1`) === '120.50|1446.00');

  console.log('Producto inactivo y cambio de proveedor');
  sql(`UPDATE proveedor_productos SET activo = 0 WHERE id=${base}`);
  await p.goto(BASE + '/admin/compras/crear');
  await p.selectOption('#proveedor_id', prov);
  verifica('un producto desactivado ya no se ofrece en compras nuevas', !(await opciones(0)).some((t) => t.includes('CC Base')));
  await p.goto(BASE + '/admin/compras/editar?id=' + oid);
  verifica('pero en la compra que ya lo usaba sigue visible, marcado', (await opciones(1)).some((t) => t.includes('CC Base') && t.includes('(inactivo)')));
  await p.selectOption('#proveedor_id', otro);
  verifica('al cambiar de proveedor se libera la partida que ya no le corresponde', (await fila(0).locator('[data-catalogo]').inputValue()) === '0' && (await fila(0).locator('[data-descripcion]').inputValue()) === '');
  verifica('y se ofrece el catálogo del nuevo proveedor', (await opciones(0)).some((t) => t.includes('CC Ajeno')));

  console.log('Móvil');
  await p.setViewportSize({ width: 390, height: 800 });
  for (const r of ['/admin/compras/crear', '/admin/compras/editar?id=' + oid, '/admin/compras/detalle?id=' + oid]) {
    await p.goto(BASE + r);
    verifica(r + ' @390px sin desborde horizontal', !(await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await nav.close();
  limpiar();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

/**
 * probar_catalogo.mjs — Catálogo de productos semiterminados por proveedor, en un navegador real:
 * alta, duplicados, edición de costo, desactivar/reactivar con confirmación, filtros, relación con productos
 * propios (y su consulta desde el producto) y vista móvil.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_catalogo.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.catalogo@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { if (c) { ok++; console.log('  ✓ ' + n); } else { mal++; console.log('  ✗ ' + n + (d ? ' → ' + d : '')); } };

function limpiar() {
  sql("DELETE FROM proveedor_productos WHERE nombre LIKE 'UC %'");
  sql("DELETE FROM proveedores WHERE nombre LIKE 'UC Proveedor%'");
  sql("DELETE FROM productos WHERE sku LIKE 'UC-%'");
}
limpiar();
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba Catálogo','${EMAIL}','${hash}',1)`);
sql("INSERT INTO proveedores (nombre, activo) VALUES ('UC Proveedor Agustín', 1), ('UC Proveedor Dos', 1)");
const prov1 = sql("SELECT id FROM proveedores WHERE nombre='UC Proveedor Agustín'");
const prov2 = sql("SELECT id FROM proveedores WHERE nombre='UC Proveedor Dos'");
const empresa = sql('SELECT MIN(id) FROM empresas');
sql(`INSERT INTO productos (empresa_id, nombre, sku, precio, costo_actual) VALUES (${empresa}, 'UC Canastilla propia', 'UC-1', 300, 55)`);
const prod = sql("SELECT id FROM productos WHERE sku='UC-1'");
sql(`INSERT INTO producto_empresa (producto_id, empresa_id) VALUES (${prod}, ${empresa})`);

const nav = await chromium.launch();
const p = await nav.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
p.on('pageerror', (e) => errores.push(e.message));
p.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
const confirmar = async (texto) => {
  await p.click(`.modal button:has-text("${texto}"), dialog button:has-text("${texto}"), [role=dialog] button:has-text("${texto}")`);
  await p.waitForLoadState();
};
const llenar = async (d) => {
  if (d.prov) await p.selectOption('#proveedor_id', d.prov);
  await p.fill('#nombre', d.nombre ?? '');
  await p.fill('#codigo', d.codigo ?? '');
  await p.fill('#costo_unitario', d.costo ?? '');
  await p.fill('#descripcion', d.descripcion ?? '');
};

try {
  await p.goto(BASE + '/login'); await p.fill('#email', EMAIL); await p.fill('#contrasena', CLAVE);
  await Promise.all([p.waitForURL('**/admin'), p.click('button[type=submit]')]);

  console.log('Navegación');
  await p.goto(BASE + '/admin/compras');
  verifica('«Catálogo» es una pestaña del módulo', (await p.locator('nav[aria-label="Compras y proveedores"] a:has-text("Catálogo")').count()) === 1);
  await Promise.all([p.waitForURL('**/admin/compras/catalogo'), p.click('nav[aria-label="Compras y proveedores"] a:has-text("Catálogo")')]);
  verifica('abre el catálogo con su pestaña activa', (await p.locator('nav[aria-label="Compras y proveedores"] a[aria-current="page"]').innerText()).includes('Catálogo'));

  console.log('Alta');
  await p.goto(BASE + '/admin/compras/catalogo/crear');
  await llenar({ prov: prov1, nombre: 'UC Armazón sin pintar', codigo: 'AG-100', costo: '120.50', descripcion: 'Acero calibre 14' });
  await Promise.all([p.waitForURL('**/admin/compras/catalogo/detalle?id=*'), p.click('button[type=submit]')]);
  const id1 = sql("SELECT id FROM proveedor_productos WHERE nombre='UC Armazón sin pintar'");
  verifica('se guarda y abre su ficha', id1 !== '' && (await p.content()).includes('UC Armazón sin pintar'));
  verifica('guarda proveedor, código, costo, descripción y estado activo',
    sql(`SELECT CONCAT(proveedor_id,'|',codigo,'|',costo_unitario,'|',descripcion,'|',activo) FROM proveedor_productos WHERE id=${id1}`) === `${prov1}|AG-100|120.50|Acero calibre 14|1`);

  console.log('Duplicados y validación');
  await p.goto(BASE + '/admin/compras/catalogo/crear');
  await llenar({ prov: prov1, nombre: 'uc armazón SIN pintar', codigo: 'X', costo: '5' });
  await p.click('button[type=submit]'); await p.waitForLoadState();
  verifica('mismo nombre en el mismo proveedor se rechaza avisando cuál existe', (await p.content()).includes('ya tiene un producto llamado'));
  await llenar({ prov: prov1, nombre: 'UC Otro', codigo: 'AG-100', costo: '5' });
  await p.click('button[type=submit]'); await p.waitForLoadState();
  verifica('mismo código en el mismo proveedor se rechaza', (await p.content()).includes('ya usa el código'));
  verifica('no se creó ningún duplicado', sql("SELECT COUNT(*) FROM proveedor_productos WHERE nombre LIKE 'UC %'") === '1');
  await llenar({ prov: prov2, nombre: 'UC Armazón sin pintar', codigo: 'AG-100', costo: '135' });
  await Promise.all([p.waitForURL('**/admin/compras/catalogo/detalle?id=*'), p.click('button[type=submit]')]);
  const id2 = sql(`SELECT id FROM proveedor_productos WHERE proveedor_id=${prov2}`);
  verifica('el mismo artículo en otro proveedor se guarda con otro costo', sql(`SELECT costo_unitario FROM proveedor_productos WHERE id=${id2}`) === '135.00' && sql(`SELECT costo_unitario FROM proveedor_productos WHERE id=${id1}`) === '120.50');

  console.log('Edición');
  await p.goto(BASE + '/admin/compras/catalogo/editar?id=' + id1);
  await p.fill('#costo_unitario', '130');
  await Promise.all([p.waitForURL('**/admin/compras/catalogo/detalle?id=*'), p.click('button[type=submit]')]);
  verifica('el costo se actualiza', sql(`SELECT costo_unitario FROM proveedor_productos WHERE id=${id1}`) === '130.00');
  verifica('el cambio de costo queda en la bitácora', sql(`SELECT COUNT(*) FROM auditoria WHERE entidad='proveedor_producto' AND entidad_id=${id1} AND accion='cambiar_costo'`) === '1');

  console.log('Desactivar y reactivar');
  await p.goto(BASE + '/admin/compras/catalogo?q=UC+');
  verifica('lista ambos productos con proveedor y costo', (await p.locator('table.tabla tbody tr').count()) === 2 && (await p.content()).includes('UC Proveedor Dos'));
  await p.locator('table.tabla tr:has-text("UC Proveedor Agustín") button:has-text("Desactivar")').click();
  verifica('desactivar pide confirmación y aún no cambia', (await p.locator('button:has-text("Sí, desactivar")').count()) > 0 && sql(`SELECT activo FROM proveedor_productos WHERE id=${id1}`) === '1');
  await confirmar('Sí, desactivar');
  verifica('queda inactivo (no se borra)', sql(`SELECT activo FROM proveedor_productos WHERE id=${id1}`) === '0');
  await p.goto(BASE + '/admin/compras/catalogo?q=UC+');
  verifica('por defecto el listado muestra solo activos', (await p.locator('table.tabla tbody tr').count()) === 1);
  await p.goto(BASE + '/admin/compras/catalogo?q=UC+&estado=inactivos');
  verifica('el filtro «Inactivos» lo conserva en el historial', (await p.locator('table.tabla tbody tr').count()) === 1 && (await p.content()).includes('Inactivo'));
  await p.locator('table.tabla button:has-text("Reactivar")').click();
  await confirmar('Sí, reactivar');
  verifica('reactivar', sql(`SELECT activo FROM proveedor_productos WHERE id=${id1}`) === '1');

  console.log('Relación con productos propios');
  await p.goto(BASE + '/admin/compras/catalogo/detalle?id=' + id1);
  verifica('sin relación al inicio', (await p.locator('#relaciones').innerText()).includes('Aún no está relacionado'));
  await p.selectOption('#producto', prod);
  await Promise.all([p.waitForLoadState(), p.click('#relaciones button:has-text("Relacionar")')]);
  verifica('se relaciona con el producto propio', sql(`SELECT COUNT(*) FROM proveedor_producto_relaciones WHERE proveedor_producto_id=${id1} AND producto_id=${prod}`) === '1' && (await p.locator('#relaciones').innerText()).includes('UC Canastilla propia'));
  await p.goto(BASE + '/admin/compras/catalogo/detalle?id=' + id2);
  await p.selectOption('#producto', prod);
  await Promise.all([p.waitForLoadState(), p.click('#relaciones button:has-text("Relacionar")')]);
  await p.goto(BASE + '/admin/productos/editar?id=' + prod);
  const bloque = await p.locator('#semiterminados').innerText();
  verifica('desde el producto propio se ve qué semiterminado de qué proveedor se usa', bloque.includes('UC Proveedor Agustín') && bloque.includes('UC Proveedor Dos') && bloque.includes('AG-100'));
  verifica('con el costo de cada proveedor', bloque.includes('130.00') && bloque.includes('135.00'));
  verifica('el producto propio conserva su costo y código', sql(`SELECT CONCAT(sku,'|',costo_actual) FROM productos WHERE id=${prod}`) === 'UC-1|55.00');
  await p.goto(BASE + '/admin/compras/catalogo?q=UC+&relacion=sin');
  verifica('filtro «sin relacionar» no los muestra', (await p.locator('table.tabla tbody tr').count()) === 0);
  await p.goto(BASE + '/admin/compras/catalogo/detalle?id=' + id1);
  await p.locator('#relaciones button:has-text("Quitar relación")').click(); await p.waitForLoadState();
  verifica('se puede quitar la relación', sql(`SELECT COUNT(*) FROM proveedor_producto_relaciones WHERE proveedor_producto_id=${id1}`) === '0');

  console.log('Expediente del proveedor');
  await p.goto(BASE + '/admin/proveedores/detalle?id=' + prov1);
  verifica('el expediente enlaza a su catálogo', (await p.locator(`a[href*="/admin/compras/catalogo?proveedor=${prov1}"]`).count()) === 1);

  console.log('Móvil');
  await p.setViewportSize({ width: 390, height: 800 });
  for (const r of ['/admin/compras/catalogo', '/admin/compras/catalogo/crear', '/admin/compras/catalogo/detalle?id=' + id1, '/admin/compras/catalogo/editar?id=' + id1, '/admin/productos/editar?id=' + prod]) {
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

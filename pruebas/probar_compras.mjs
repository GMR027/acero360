/**
 * probar_compras.mjs — Compras y proveedores unificados, de punta a punta en un navegador real:
 * navegación, compra de insumos (consumible y material controlado), semiterminado de un proveedor con producto
 * del catálogo (relacionar después, recepción parcial, por terminar → disponibles), pagos parciales, CFDI/PDF,
 * duplicados, expediente del proveedor, proveedor inactivo y vista móvil.
 *
 * Uso (contra la BD de PRUEBA):
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_compras.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.compras@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (nombre, cond, detalle = '') => {
  if (cond) { ok++; console.log('  ✓ ' + nombre); } else { mal++; console.log('  ✗ ' + nombre + (detalle ? ' → ' + detalle : '')); }
};

/** Borra lo que esta prueba crea (compras UI-*, proveedores y producto de prueba), en orden de FK. */
function limpiar() {
  const ordenes = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM ordenes_compra WHERE referencia LIKE 'UI-%'");
  if (ordenes) {
    const gastos = sql(`SELECT IFNULL(GROUP_CONCAT(gasto_id), '') FROM ordenes_compra WHERE id IN (${ordenes}) AND gasto_id IS NOT NULL`);
    sql(`DELETE FROM pagos_compra WHERE orden_id IN (${ordenes})`);
    for (const t of ['movimientos_por_terminar', 'movimientos_material', 'movimientos_inventario']) {
      sql(`DELETE FROM ${t} WHERE recepcion_id IN (SELECT id FROM recepciones WHERE orden_id IN (${ordenes}))`);
    }
    sql(`DELETE FROM recepciones WHERE orden_id IN (${ordenes})`);
    sql(`DELETE FROM ordenes_compra WHERE id IN (${ordenes})`);
    if (gastos) {
      sql(`DELETE FROM documentos WHERE entidad='gasto' AND entidad_id IN (${gastos})`);
      sql(`DELETE FROM gastos WHERE id IN (${gastos})`);
    }
  }
  sql("DELETE FROM materiales WHERE nombre LIKE 'UI %'");
  sql("DELETE FROM movimientos_por_terminar WHERE producto_id IN (SELECT id FROM productos WHERE sku='UI-1')");
  sql("DELETE FROM movimientos_inventario WHERE producto_id IN (SELECT id FROM productos WHERE sku='UI-1')");
  sql("DELETE FROM productos WHERE sku='UI-1'");
  sql("DELETE FROM proveedores WHERE nombre LIKE 'UI Proveedor%'");
}
limpiar();

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba Compras','${EMAIL}','${hash}',1)`);
sql("INSERT INTO proveedores (nombre, tipo_proveedor, activo) VALUES ('UI Proveedor Agustín', 'Varios', 1), ('UI Proveedor Inactivo', 'Material', 0)");
const provId = sql("SELECT id FROM proveedores WHERE nombre='UI Proveedor Agustín'");
const provInactivoId = sql("SELECT id FROM proveedores WHERE nombre='UI Proveedor Inactivo'");
const empresaId = sql('SELECT MIN(id) FROM empresas');
sql(`INSERT INTO productos (empresa_id, nombre, sku, precio, costo_actual, stock) VALUES (${empresaId}, 'UI Canastilla terminada', 'UI-1', 250, 0, 10)`);
const prodId = sql("SELECT id FROM productos WHERE sku='UI-1'");
sql(`INSERT INTO producto_empresa (producto_id, empresa_id) VALUES (${prodId}, ${empresaId})`);
const rfc = sql('SELECT IFNULL(rfc, \'\') FROM contribuyentes ORDER BY id LIMIT 1') || 'XAXX010101000';

const cfdi = (uuid, total) => Buffer.from('<?xml version="1.0" encoding="UTF-8"?>'
  + `<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="A" Folio="9" Fecha="${new Date().toISOString().slice(0, 10)}T10:00:00" SubTotal="${total}" Total="${total}" Moneda="MXN" TipoDeComprobante="I">`
  + `<cfdi:Emisor Rfc="AAA010101AAA" Nombre="Proveedor"/><cfdi:Receptor Rfc="${rfc}" Nombre="Receptor"/>`
  + `<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="${uuid}"/></cfdi:Complemento></cfdi:Comprobante>`);
const uuid = 'AB12CD34-1111-2222-3333-' + Date.now().toString(16).toUpperCase().padStart(12, '0').slice(-12);

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

const confirmar = async (texto) => {
  await pagina.click(`.modal button:has-text("${texto}"), dialog button:has-text("${texto}"), [role=dialog] button:has-text("${texto}")`);
  await pagina.waitForLoadState();
};
const guardarCompra = async () => {
  await Promise.all([pagina.waitForURL('**/admin/compras/detalle?id=*'), pagina.click('button[type=submit]')]);
  return sql('SELECT MAX(id) FROM ordenes_compra');
};
const mensaje = async () => (await pagina.locator('.alerta, .nota, [role=alert], [role=status]').allInnerTexts()).join(' ');

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Un solo módulo: navegación');
  await pagina.goto(BASE + '/admin/compras');
  const pestanas = (await pagina.locator('nav[aria-label="Compras y proveedores"] a').allInnerTexts()).map((t) => t.trim());
  const pestanasEsperadas = ['Resumen', 'Compras y pedidos', 'Recepciones', 'Proveedores', 'Catálogo', 'Facturas',
    'Compras inteligentes', 'Complementos de pago', 'Pagos', 'Categorías'];
  // Las fases 2 y 4 añadieron «Compras inteligentes» y «Complementos de pago»: hoy son 10.
  verifica(`las ${pestanasEsperadas.length} secciones están en un mismo lugar`,
    pestanas.length === pestanasEsperadas.length && pestanasEsperadas.every((t) => pestanas.some((p) => p.endsWith(t))),
    pestanas.join(' | '));
  verifica('el menú lateral tiene una sola entrada «Compras y proveedores»',
    (await pagina.locator('.sidebar__enlace:has-text("Compras y proveedores")').count()) === 1
    && (await pagina.locator('.sidebar__enlace:text-is("Proveedores")').count()) === 0);
  verifica('el resumen muestra indicadores', (await pagina.locator('.stat').count()) >= 4);
  for (const [texto, ruta] of [['Compras y pedidos', '/admin/compras/pedidos'], ['Recepciones', '/admin/compras/recepciones'], ['Proveedores', '/admin/proveedores'],
    ['Facturas', '/admin/compras/facturas'], ['Pagos', '/admin/compras/pagos'], ['Categorías', '/admin/proveedores/categorias']]) {
    await pagina.goto(BASE + '/admin/compras');
    await Promise.all([pagina.waitForURL('**' + ruta + '*'), pagina.click(`nav[aria-label="Compras y proveedores"] a:has-text("${texto}")`)]);
    verifica(`«${texto}» abre su pantalla y conserva las pestañas`, (await pagina.locator('nav[aria-label="Compras y proveedores"]').count()) === 1);
  }

  console.log('Proveedor inactivo');
  await pagina.goto(BASE + '/admin/compras/crear');
  const opciones = await pagina.locator('#proveedor_id option').allInnerTexts();
  verifica('un proveedor activo aparece en una compra nueva', opciones.some((o) => o.includes('UI Proveedor Agustín')));
  verifica('un proveedor inactivo NO aparece en una compra nueva', !opciones.some((o) => o.includes('UI Proveedor Inactivo')));
  await pagina.goto(BASE + '/admin/compras/pedidos');
  verifica('pero sí en los filtros del historial', (await pagina.locator('#proveedor option').allInnerTexts()).some((o) => o.includes('UI Proveedor Inactivo')));

  console.log('Insumo consumible (sin producto ni inventario)');
  const productosAntes = sql('SELECT COUNT(*) FROM productos');
  await pagina.goto(BASE + '/admin/compras/crear');
  await pagina.check('#tipo-insumo');
  verifica('al elegir insumo se muestra su bloque y se oculta el de semiterminados',
    await pagina.locator('[data-bloque-tipo="insumo"]').isVisible() && !(await pagina.locator('[data-bloque-tipo="semiterminado"]').isVisible()));
  await pagina.selectOption('#proveedor_id', provId);
  await pagina.fill('#referencia', 'UI-PINTURA');
  await pagina.fill('#iva', '16.00');
  const ins = pagina.locator('[data-bloque-tipo="insumo"]');
  await ins.locator('input[name="descripcion[]"]').fill('Pintura esmalte negro');
  await ins.locator('input[name="cantidad[]"]').fill('10');
  await ins.locator('input[name="unidad[]"]').fill('L');
  await ins.locator('input[name="costo[]"]').fill('10');
  const idPintura = await guardarCompra();
  verifica('la compra de pintura se guarda sin crear productos', sql('SELECT COUNT(*) FROM productos') === productosAntes);
  verifica('queda «sin recepción» y con total 100 + IVA 16', sql(`SELECT CONCAT(estado,'/',total_esperado,'/',iva) FROM ordenes_compra WHERE id=${idPintura}`) === 'sin_recepcion/100.00/16.00');
  verifica('no ofrece registrar recepción', (await pagina.locator('a:has-text("Registrar recepción")').count()) === 0);
  verifica('muestra comprado 116, saldo 116',
    (await pagina.locator('.stat').first().innerText()).replace(/\s/g, '').includes('116.00'));

  console.log('Material controlado en inventario');
  await pagina.goto(BASE + '/admin/compras/crear');
  await pagina.check('#tipo-insumo');
  await pagina.selectOption('#proveedor_id', provId);
  await pagina.fill('#referencia', 'UI-VARILLA');
  await ins.locator('input[name="descripcion[]"]').fill('Varilla corrugada 3/8');
  await ins.locator('input[name="cantidad[]"]').fill('12.5');
  await ins.locator('input[name="unidad[]"]').fill('kg');
  await ins.locator('input[name="costo[]"]').fill('24');
  await ins.locator('select[name="controla[]"]').selectOption('1');
  verifica('al controlarlo pide el nombre del material', await ins.locator('input[name="material_nuevo[]"]').isVisible());
  await ins.locator('input[name="material_nuevo[]"]').fill('UI Varilla 3/8');
  const idVarilla = await guardarCompra();
  verifica('se crea el material (no un producto de venta)', sql("SELECT COUNT(*) FROM materiales WHERE nombre='UI Varilla 3/8'") === '1' && sql('SELECT COUNT(*) FROM productos') === productosAntes);
  await pagina.goto(BASE + '/admin/compras/recibir?id=' + idVarilla);
  await pagina.locator('input[name^="cantidad["]').fill('12.5');
  await pagina.click('button[type=submit]');
  await pagina.waitForSelector('button:has-text("Sí, confirmar recepción")', { timeout: 3000 }).catch(() => {});
  verifica('pide confirmación y todavía no suma', sql("SELECT existencia FROM materiales WHERE nombre='UI Varilla 3/8'") === '0.000');
  await confirmar('Sí, confirmar recepción');
  await pagina.waitForURL('**/admin/compras/detalle?id=*');
  verifica('suma 12.5 al material', sql("SELECT existencia FROM materiales WHERE nombre='UI Varilla 3/8'") === '12.500');
  await pagina.goto(BASE + '/admin/compras/recepciones');
  verifica('la pantalla de recepciones lista el material', (await pagina.locator('#materiales').innerText()).includes('UI Varilla 3/8'));

  console.log('Semiterminado de un proveedor: relacionar después, recibir parcial, terminar');
  await pagina.goto(BASE + '/admin/compras/crear');
  await pagina.selectOption('#proveedor_id', provId);
  await pagina.fill('#referencia', 'UI-CANASTILLA');
  const semi = pagina.locator('[data-bloque-tipo="semiterminado"]');
  await semi.locator('input[name="descripcion[]"]').fill('Canastilla sin pintar');
  await semi.locator('input[name="cantidad[]"]').fill('10');
  await semi.locator('input[name="costo[]"]').fill('100');
  const idCanastilla = await guardarCompra();
  verifica('se registra sin relacionar con el catálogo', sql(`SELECT COUNT(*) FROM orden_compra_partidas WHERE orden_id=${idCanastilla} AND producto_id IS NULL`) === '1');
  verifica('el detalle lo marca «Sin relacionar»', (await pagina.content()).includes('Sin relacionar con el catálogo'));
  await pagina.goto(BASE + '/admin/compras/recibir?id=' + idCanastilla);
  verifica('permite recibir sin relacionar (solo registra cuánto llegó)', (await pagina.locator('input[name^="cantidad["]').count()) > 0);
  await pagina.goto(BASE + '/admin/compras/detalle?id=' + idCanastilla);
  await pagina.locator('select[name="producto_id"]').selectOption(prodId);
  await Promise.all([pagina.waitForLoadState(), pagina.click('form[action="/admin/compras/accion"] button:has-text("Relacionar")')]);
  verifica('se relaciona después con el producto', sql(`SELECT producto_id FROM orden_compra_partidas WHERE orden_id=${idCanastilla}`) === prodId);
  verifica('el nombre del proveedor y el del catálogo siguen separados',
    sql(`SELECT descripcion FROM orden_compra_partidas WHERE orden_id=${idCanastilla}`) === 'Canastilla sin pintar'
    && sql(`SELECT nombre FROM productos WHERE id=${prodId}`) === 'UI Canastilla terminada');

  await pagina.goto(BASE + '/admin/compras/recibir?id=' + idCanastilla);
  await pagina.locator('input[name^="cantidad["]').fill('4');
  await pagina.click('button[type=submit]');
  await confirmar('Sí, confirmar recepción');
  await pagina.waitForURL('**/admin/compras/detalle?id=*');
  verifica('recepción parcial: 4 por terminar', sql(`SELECT stock_por_terminar FROM productos WHERE id=${prodId}`) === '4');
  verifica('la existencia para venta NO cambia (10)', sql(`SELECT stock FROM productos WHERE id=${prodId}`) === '10');
  verifica('estado parcial, quedan 6', sql(`SELECT estado FROM ordenes_compra WHERE id=${idCanastilla}`) === 'parcial'
    && (await pagina.content()).includes('Recibida parcial'));

  await pagina.goto(BASE + '/admin/compras/recibir?id=' + idCanastilla);
  await pagina.locator('input[name^="cantidad["]').fill('9');
  await pagina.click('button[type=submit]');
  await confirmar('Sí, confirmar recepción');
  verifica('más de lo pendiente se rechaza sin confirmación explícita', sql(`SELECT stock_por_terminar FROM productos WHERE id=${prodId}`) === '4'
    && (await pagina.content()).includes('excede lo pendiente'));

  await pagina.goto(BASE + '/admin/compras/recepciones#por-terminar');
  verifica('«por terminar» lista el producto con 4', (await pagina.locator('#por-terminar').innerText()).includes('UI Canastilla terminada'));
  await pagina.locator('#por-terminar tr:has-text("UI Canastilla") input[name="cantidad"]').fill('3');
  await pagina.locator('#por-terminar tr:has-text("UI Canastilla") button:has-text("Pasar a disponibles")').click();
  await confirmar('Sí, pasar a disponibles');
  verifica('terminar 3: 1 por terminar y 13 disponibles', sql(`SELECT CONCAT(stock_por_terminar,'/',stock) FROM productos WHERE id=${prodId}`) === '1/13');

  console.log('Factura (CFDI y PDF), duplicados y enlace único con contabilidad');
  await pagina.goto(BASE + '/admin/compras/detalle?id=' + idPintura);
  const gastosAntes = sql('SELECT COUNT(*) FROM gastos');
  await pagina.setInputFiles('#comp-archivo', [{ name: 'nota.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n') }]);
  await Promise.all([pagina.waitForLoadState(), pagina.click('form[action="/admin/compras/comprobante"] button:has-text("Subir archivos")')]);
  verifica('un PDF se adjunta pero NO vuelve la compra «con CFDI»', sql(`SELECT estado_cfdi FROM ordenes_compra WHERE id=${idPintura}`) === 'pendiente_cfdi');
  verifica('envió la compra a contabilidad una vez', sql('SELECT COUNT(*) FROM gastos') === String(Number(gastosAntes) + 1));
  await pagina.setInputFiles('#comp-archivo', [{ name: 'factura.xml', mimeType: 'text/xml', buffer: cfdi(uuid, '116.00') }]);
  await Promise.all([pagina.waitForLoadState(), pagina.click('form[action="/admin/compras/comprobante"] button:has-text("Subir archivos")')]);
  verifica('el XML con timbre la deja «Con CFDI»', sql(`SELECT estado_cfdi FROM ordenes_compra WHERE id=${idPintura}`) === 'con_cfdi');
  verifica('sin duplicar el gasto', sql('SELECT COUNT(*) FROM gastos') === String(Number(gastosAntes) + 1));
  verifica('la revisión fiscal sigue «por validar» (no se asume deducible)',
    sql(`SELECT g.revision_fiscal FROM gastos g JOIN ordenes_compra o ON o.gasto_id=g.id WHERE o.id=${idPintura}`) === 'por_validar');

  await pagina.goto(BASE + '/admin/compras/detalle?id=' + idVarilla);
  await pagina.setInputFiles('#comp-archivo', [{ name: 'otra.xml', mimeType: 'text/xml', buffer: cfdi(uuid, '300.00') }]);
  await Promise.all([pagina.waitForLoadState(), pagina.click('form[action="/admin/compras/comprobante"] button:has-text("Subir archivos")')]);
  verifica('el mismo UUID en otra compra se rechaza', sql(`SELECT estado_cfdi FROM ordenes_compra WHERE id=${idVarilla}`) !== 'con_cfdi'
    && sql(`SELECT COUNT(*) FROM gastos WHERE uuid_fiscal='${uuid}' AND estado='vigente'`) === '1');

  console.log('Pagos parciales');
  await pagina.goto(BASE + '/admin/compras/detalle?id=' + idPintura + '#pagos');
  await pagina.fill('#pago-importe', '16');
  await pagina.locator('form[action="/admin/compras/pago"]:has(#pago-importe) button[type=submit]').click();
  await confirmar('Sí, registrar pago');
  verifica('pago parcial de 16: saldo 100 y estado parcial', sql(`SELECT IFNULL(SUM(importe),0) FROM pagos_compra WHERE orden_id=${idPintura} AND estado='vigente'`) === '16.00'
    && (await pagina.content()).includes('Pago parcial'));
  const gastosPago = sql('SELECT COUNT(*) FROM gastos');
  await pagina.fill('#pago-importe', '100');
  await pagina.locator('form[action="/admin/compras/pago"]:has(#pago-importe) button[type=submit]').click();
  await confirmar('Sí, registrar pago');
  verifica('pago final: pagada', (await pagina.content()).includes('Pagada'));
  verifica('los pagos no crearon gastos', sql('SELECT COUNT(*) FROM gastos') === gastosPago);
  verifica('el gasto único quedó pagado', sql(`SELECT g.estado_pago FROM gastos g JOIN ordenes_compra o ON o.gasto_id=g.id WHERE o.id=${idPintura}`) === 'pagado');
  await pagina.locator('#pagos details:has-text("Cancelar pago") summary').first().click();
  await pagina.locator('#pagos details[open] input[name="motivo"]').fill('Transferencia rechazada');
  await pagina.locator('#pagos details[open] button[type=submit]').click();
  await confirmar('Sí, cancelar pago');
  verifica('cancelar un pago devuelve el saldo y lo conserva como cancelado',
    sql(`SELECT COUNT(*) FROM pagos_compra WHERE orden_id=${idPintura} AND estado='cancelado'`) === '1'
    && sql(`SELECT g.estado_pago FROM gastos g JOIN ordenes_compra o ON o.gasto_id=g.id WHERE o.id=${idPintura}`) === 'pendiente');

  console.log('Listas y filtros');
  await pagina.goto(BASE + '/admin/compras/pedidos?q=UI-&tipo=insumo');
  verifica('filtra por tipo insumo (2 compras)', (await pagina.locator('table.tabla tbody tr').count()) === 2);
  await pagina.goto(BASE + '/admin/compras/pedidos?q=UI-&pago=parcial');
  verifica('filtra por pago parcial (la pintura)', (await pagina.locator('table.tabla tbody tr').count()) === 1 && (await pagina.content()).includes('UI-PINTURA'));
  await pagina.goto(BASE + '/admin/compras/facturas?q=UI-&cfdi=con_cfdi');
  verifica('facturas: filtra por «Con CFDI»', (await pagina.locator('table.tabla tbody tr').count()) === 1);
  await pagina.goto(BASE + '/admin/compras/pagos?proveedor=' + provId);
  verifica('pagos: muestra compras con saldo y el historial', (await pagina.locator('table.tabla').count()) === 2);

  console.log('Expediente del proveedor');
  await pagina.goto(BASE + '/admin/proveedores/detalle?id=' + provId);
  const exp = await pagina.locator('main, body').first().innerText();
  verifica('muestra compras, recepciones, facturas y pagos del proveedor', ['Compras', 'Pendiente de recibir', 'Facturas pendientes', 'Pagos'].every((t) => exp.includes(t)));
  const folioPintura = sql(`SELECT folio FROM ordenes_compra WHERE id=${idPintura}`);
  verifica('lista sus compras con su folio', (await pagina.locator('#compras').innerText()).includes(folioPintura), folioPintura);
  await pagina.goto(BASE + '/admin/proveedores');
  await pagina.goto(BASE + '/admin/proveedores/detalle?id=' + provId);
  const borrar = await pagina.request.post(BASE + '/admin/proveedores/eliminar', { form: { id: provId, _csrf: await pagina.evaluate(() => document.querySelector('input[name=_csrf]')?.value || '') } });
  verifica('un proveedor con compras no se elimina', sql(`SELECT COUNT(*) FROM proveedores WHERE id=${provId}`) === '1', String(borrar.status()));

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of ['/admin/compras', '/admin/compras/pedidos', '/admin/compras/crear', '/admin/compras/recepciones', '/admin/compras/facturas', '/admin/compras/pagos',
    '/admin/compras/detalle?id=' + idPintura, '/admin/compras/recibir?id=' + idCanastilla, '/admin/proveedores/detalle?id=' + provId, '/admin/proveedores/categorias']) {
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

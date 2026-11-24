/**
 * probar_contabilidad_rapida.mjs — Acciones rápidas de Contabilidad.
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_contabilidad_rapida.mjs
 *
 * Comprueba la reestructuración no destructiva:
 *   · Ventas y cobros trae Cobrar / Facturar / Abrir, y el modal de cobro
 *     registra el abono sin salir de la lista;
 *   · Gastos trae Pagar / Documentos / Editar / Abrir, y el modal de pago
 *     registra el abono;
 *   · los filtros rápidos de facturación, cobro y pago funcionan;
 *   · el resumen enlaza sus tarjetas y ofrece «Cerrar el mes»;
 *   · Pendientes de facturar ofrece Facturar y Abrir venta;
 *   · en celular no hay desborde horizontal.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') {
    console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).');
    process.exit(2);
}

const EMAIL = 'prueba.contabilidad.rapida@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const CONCEPTO_GASTO = 'T52 Gasto de prueba rápida';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();

let ok = 0;
let mal = 0;
const verifica = (nombre, condicion, detalle = '') => {
    if (condicion) { ok++; console.log(`  ✓ ${nombre}`); }
    else { mal++; console.log(`  ✗ ${nombre}${detalle ? ' → ' + detalle : ''}`); }
};

let venta = 0;
let gasto = 0;
let usuario = 0;

const limpiar = () => {
    if (usuario > 0) {
        sql(`DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${usuario}))`);
        sql(`DELETE FROM pagos_gasto WHERE gasto_id IN (SELECT id FROM gastos WHERE usuario_id=${usuario})`);
        sql(`DELETE FROM movimientos_inventario WHERE usuario_id=${usuario} OR venta_id IN (SELECT id FROM ventas WHERE usuario_id=${usuario})`);
        sql(`DELETE FROM ingresos WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${usuario})`);
        sql(`DELETE FROM venta_detalles WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${usuario})`);
        sql(`DELETE FROM ventas WHERE usuario_id=${usuario}`);
        sql(`DELETE FROM gastos WHERE usuario_id=${usuario}`);
        sql(`DELETE FROM usuarios WHERE id=${usuario}`);
    }
    sql('UPDATE productos SET stock = 100 WHERE id = 37');
};

limpiar();

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba contabilidad rápida','${EMAIL}','${hash}',1)`);
usuario = Number(sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`));
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');

sql('UPDATE productos SET stock = 100 WHERE id = 37');
execFileSync('php', ['-r', `require 'includes/app.php';
App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>0,'descuento_pct'=>0],[37=>['cantidad'=>1,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`], { env: { ...process.env, DB_NAME: DB } });
venta = Number(sql(`SELECT MAX(id) FROM ventas WHERE usuario_id=${usuario}`));
const folio = sql(`SELECT folio FROM ventas WHERE id=${venta}`);

const creado = execFileSync('php', ['-r', `require 'includes/app.php';
App\\Gastos::crear([
  'fecha' => date('Y-m-d'), 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T52 Proveedor',
  'concepto' => '${CONCEPTO_GASTO}', 'subtotal' => '1000', 'iva_tasa' => '16',
  'estado_pago' => 'pendiente', 'comprobante_fiscal' => 0,
], ${usuario}); echo App\\Gastos::$ultimoId;`], { env: { ...process.env, DB_NAME: DB }, encoding: 'utf8' }).trim();
gasto = Number(creado);

const navegador = await chromium.launch();
const errores = [];
try {
    const pagina = await (await navegador.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    pagina.on('pageerror', (e) => errores.push(String(e)));
    pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

    await pagina.goto(BASE + '/login');
    await pagina.fill('#email', EMAIL);
    await pagina.fill('#contrasena', CLAVE);
    await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

    console.log('1. Ventas y cobros: acciones en la fila');
    await pagina.goto(`${BASE}/admin/contabilidad/ingresos`, { waitUntil: 'domcontentloaded' });
    const filaVenta = pagina.locator('tr', { hasText: folio });
    verifica('la fila trae Cobrar', await filaVenta.locator('a[data-modal-form^="tpl-cobro-"]').count() === 1);
    verifica('la fila trae Facturar', await filaVenta.locator('a:has-text("Facturar")').count() === 1);
    verifica('la fila trae Abrir', await filaVenta.locator('a:has-text("Abrir")').count() === 1);

    console.log('2. El modal de cobro registra el abono');
    await filaVenta.locator('a[data-modal-form^="tpl-cobro-"]').click();
    await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 10000 });
    verifica('el modal usa el formulario real de cobro',
        await pagina.locator('#modal-cuerpo form[action="/admin/contabilidad/ingresos/accion"]').count() === 1);
    const importeCobro = await pagina.locator('#modal-cuerpo input[name="importe"]').inputValue();
    verifica('el importe viene precargado con el saldo', Number(importeCobro) === 100, importeCobro);
    await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
        pagina.click('[data-confirmar-modal]'),
    ]);
    verifica('el cobro quedó registrado',
        sql(`SELECT COUNT(*) FROM cobros WHERE ingreso_id=(SELECT id FROM ingresos WHERE venta_id=${venta})`) === '1');
    const textoVenta = (await pagina.locator('tr', { hasText: folio }).innerText()).toLowerCase();
    verifica('la fila ya muestra el cobro', textoVenta.includes('pagada') || textoVenta.includes('cobrado'), textoVenta.slice(0, 120));

    console.log('3. Filtros rápidos de Ventas y cobros');
    await pagina.goto(`${BASE}/admin/contabilidad/ingresos?cobro=pendiente`, { waitUntil: 'domcontentloaded' });
    verifica('«Por cobrar» ya no incluye la venta cobrada', await pagina.locator('tr', { hasText: folio }).count() === 0);
    await pagina.goto(`${BASE}/admin/contabilidad/ingresos?facturacion=no_facturada`, { waitUntil: 'domcontentloaded' });
    verifica('el filtro de facturación conserva la venta sin factura', await pagina.locator('tr', { hasText: folio }).count() === 1);

    console.log('4. Gastos: acciones en la fila');
    await pagina.goto(`${BASE}/admin/contabilidad/gastos`, { waitUntil: 'domcontentloaded' });
    const filaGasto = pagina.locator('tr', { hasText: CONCEPTO_GASTO });
    verifica('la fila trae Pagar', await filaGasto.locator('a[data-modal-form^="tpl-pago-"]').count() === 1);
    verifica('la fila trae Documentos', await filaGasto.locator('a:has-text("Documentos")').count() === 1);
    verifica('la fila trae Editar y Abrir',
        await filaGasto.locator('a:has-text("Editar")').count() === 1
        && await filaGasto.locator('a:has-text("Abrir")').count() === 1);

    console.log('5. El modal de pago registra el abono');
    await filaGasto.locator('a[data-modal-form^="tpl-pago-"]').click();
    await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 10000 });
    verifica('el modal usa el formulario real de pago',
        await pagina.locator('#modal-cuerpo form[action="/admin/contabilidad/gastos/accion"]').count() === 1);
    const importePago = await pagina.locator('#modal-cuerpo input[name="importe"]').inputValue();
    verifica('el importe viene precargado con el saldo', Number(importePago) === 1160, importePago);
    await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
        pagina.click('[data-confirmar-modal]'),
    ]);
    verifica('el abono quedó registrado',
        sql(`SELECT COUNT(*) FROM pagos_gasto WHERE gasto_id=${gasto} AND estado='vigente'`) === '1');
    verifica('el gasto quedó pagado', sql(`SELECT estado_pago FROM gastos WHERE id=${gasto}`) === 'pagado');

    console.log('6. Filtros rápidos de Gastos');
    await pagina.goto(`${BASE}/admin/contabilidad/gastos?pago=pendiente`, { waitUntil: 'domcontentloaded' });
    verifica('«Por pagar» ya no incluye el gasto pagado', await pagina.locator('tr', { hasText: CONCEPTO_GASTO }).count() === 0);
    await pagina.goto(`${BASE}/admin/contabilidad/gastos?pago=pagado`, { waitUntil: 'domcontentloaded' });
    verifica('«Pagados» sí lo incluye', await pagina.locator('tr', { hasText: CONCEPTO_GASTO }).count() === 1);

    console.log('7. Resumen y Pendientes de facturar');
    await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
    verifica('el resumen trae «Cerrar el mes»', await pagina.locator('a:has-text("Cerrar el mes")').count() >= 1);
    verifica('las tarjetas del resumen enlazan a su lista', await pagina.locator('a.fin-kpi').count() >= 3);
    await pagina.goto(`${BASE}/admin/contabilidad/sin-facturar`, { waitUntil: 'domcontentloaded' });
    verifica('Pendientes de facturar ofrece Facturar', await pagina.locator('a:has-text("Facturar")').count() >= 1);
    verifica('Pendientes de facturar ofrece Abrir venta', await pagina.locator('a:has-text("Abrir venta")').count() >= 1);

    console.log('8. Móvil y consola');
    await pagina.setViewportSize({ width: 390, height: 800 });
    for (const ruta of ['/admin/contabilidad', '/admin/contabilidad/ingresos', '/admin/contabilidad/gastos']) {
        await pagina.goto(BASE + ruta, { waitUntil: 'domcontentloaded' });
        const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        verifica(`sin desborde horizontal en ${ruta}`, !desborde);
    }
    verifica('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (e) {
    mal++;
    console.log('  ✗ se interrumpió → ' + e.message.split('\n')[0]);
} finally {
    limpiar();
    await navegador.close();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

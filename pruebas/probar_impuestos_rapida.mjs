/**
 * probar_impuestos_rapida.mjs — Acciones rápidas de Impuestos.
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_impuestos_rapida.mjs
 *
 * Comprueba la reestructuración no destructiva:
 *   · «Ventas y gastos» abre la venta o el gasto y ofrece Cobrar/Pagar;
 *   · los filtros de tipo, pago y facturación (y la exportación) los respetan;
 *   · «Documentos fiscales» es pestaña de Impuestos y ofrece Abrir/Facturar;
 *   · el resumen del mes enlaza sus vistas y dice si falta la declaración;
 *   · «Retenciones y declaraciones» muestra los 12 meses y preselecciona el mes;
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

const EMAIL = 'prueba.impuestos.rapida@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const CONCEPTO_GASTO = 'T53 Gasto de prueba fiscal';
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
let anio = 0;
let mes = 0;

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
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba impuestos rápida','${EMAIL}','${hash}',1)`);
usuario = Number(sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`));
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');

sql('UPDATE productos SET stock = 100 WHERE id = 37');
execFileSync('php', ['-r', `require 'includes/app.php';
App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>0,'descuento_pct'=>0],[37=>['cantidad'=>1,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`], { env: { ...process.env, DB_NAME: DB } });
venta = Number(sql(`SELECT MAX(id) FROM ventas WHERE usuario_id=${usuario}`));
const folio = sql(`SELECT folio FROM ventas WHERE id=${venta}`);
const fechaVenta = sql(`SELECT fecha FROM ventas WHERE id=${venta}`);
anio = Number(fechaVenta.slice(0, 4));
mes = Number(fechaVenta.slice(5, 7));

const creado = execFileSync('php', ['-r', `require 'includes/app.php';
App\\Gastos::crear([
  'fecha' => date('Y-m-d'), 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T53 Proveedor',
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

    console.log('1. El mes fiscal reúne sus vistas');
    await pagina.goto(`${BASE}/admin/fiscal?anio=${anio}&mes=${mes}`, { waitUntil: 'domcontentloaded' });
    verifica('la pestaña Documentos fiscales existe',
        await pagina.locator('.entregas-tabs .chip:has-text("Documentos fiscales")').count() >= 1);
    verifica('el mes enlaza a Ventas y gastos',
        await pagina.locator(`a[href*="/admin/fiscal/movimientos?anio=${anio}&mes=${mes}"]`).count() >= 1);
    verifica('el mes enlaza a Documentos fiscales',
        await pagina.locator(`a[href*="/admin/fiscal/documentos?anio=${anio}&mes=${mes}"]`).count() >= 1);
    verifica('el mes indica el estado de la declaración',
        await pagina.locator('.chip-grupo a:has-text("Declaración:")').count() >= 1);

    console.log('2. Ventas y gastos: acciones por fila');
    await pagina.goto(`${BASE}/admin/fiscal/movimientos?anio=${anio}&mes=${mes}`, { waitUntil: 'domcontentloaded' });
    const filaVenta = pagina.locator('tr', { hasText: folio });
    const filaGasto = pagina.locator('tr', { hasText: CONCEPTO_GASTO });
    verifica('la venta trae Cobrar', await filaVenta.locator('a:has-text("Cobrar")').count() === 1);
    verifica('la venta trae Abrir y Documentos',
        await filaVenta.locator('a:has-text("Abrir")').count() === 1
        && await filaVenta.locator('a:has-text("Documentos")').count() === 1);
    verifica('el gasto trae Pagar', await filaGasto.locator('a:has-text("Pagar")').count() === 1);
    verifica('el gasto trae Abrir y Documentos',
        await filaGasto.locator('a:has-text("Abrir")').count() === 1
        && await filaGasto.locator('a:has-text("Documentos")').count() === 1);

    console.log('3. Filtros y exportación');
    await pagina.goto(`${BASE}/admin/fiscal/movimientos?anio=${anio}&mes=${mes}&tipo_mov=Gasto`, { waitUntil: 'domcontentloaded' });
    verifica('«Solo gastos» muestra el gasto', await pagina.locator('tr', { hasText: CONCEPTO_GASTO }).count() === 1);
    verifica('«Solo gastos» oculta la venta', await pagina.locator('tr', { hasText: folio }).count() === 0);

    await pagina.goto(`${BASE}/admin/fiscal/movimientos?anio=${anio}&mes=${mes}&estado=pagado`, { waitUntil: 'domcontentloaded' });
    verifica('«Pagadas» no incluye lo pendiente',
        await pagina.locator('tr', { hasText: folio }).count() === 0
        && await pagina.locator('tr', { hasText: CONCEPTO_GASTO }).count() === 0);

    await pagina.goto(`${BASE}/admin/fiscal/movimientos?anio=${anio}&mes=${mes}&facturacion=facturada`, { waitUntil: 'domcontentloaded' });
    verifica('«Facturadas» excluye gastos y ventas sin factura',
        await pagina.locator('tr', { hasText: folio }).count() === 0
        && await pagina.locator('tr', { hasText: CONCEPTO_GASTO }).count() === 0);

    const csv = await pagina.request.get(`${BASE}/admin/fiscal/exportar?anio=${anio}&mes=${mes}&tipo=movimientos&tipo_mov=Gasto&formato=csv`);
    const textoCsv = await csv.text();
    verifica('la exportación respeta el filtro de tipo',
        !textoCsv.includes(folio) && textoCsv.includes(CONCEPTO_GASTO), textoCsv.slice(0, 120));

    console.log('4. Documentos fiscales');
    await pagina.goto(`${BASE}/admin/fiscal/documentos?anio=${anio}&mes=${mes}&grupo=sin_factura`, { waitUntil: 'domcontentloaded' });
    verifica('la pestaña está activa',
        (await pagina.locator('.entregas-tabs .chip[aria-current="page"]').first().textContent()).includes('Documentos fiscales'));
    const filaDocumento = pagina.locator('tr', { hasText: folio });
    verifica('la venta sin factura aparece con sus acciones',
        await filaDocumento.locator('a:has-text("Abrir venta")').count() === 1
        && await filaDocumento.locator('a:has-text("Facturar")').count() === 1);

    console.log('5. Retenciones y declaraciones');
    await pagina.goto(`${BASE}/admin/fiscal/registros?anio=${anio}&mes=${mes}`, { waitUntil: 'domcontentloaded' });
    verifica('muestra los 12 meses', await pagina.locator('nav[aria-label="Declaraciones por mes"] .chip').count() === 12);
    verifica('el mes consultado queda activo',
        await pagina.locator('nav[aria-label="Declaraciones por mes"] .chip--activo').count() === 1);
    verifica('el formulario de declaración preselecciona el mes',
        await pagina.locator('#dec-mes').inputValue() === String(mes));

    console.log('6. Móvil y consola');
    await pagina.setViewportSize({ width: 390, height: 800 });
    for (const ruta of ['/admin/fiscal', '/admin/fiscal/movimientos', '/admin/fiscal/documentos', '/admin/fiscal/registros']) {
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

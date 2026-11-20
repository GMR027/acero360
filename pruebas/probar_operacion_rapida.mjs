/**
 * probar_operacion_rapida.mjs — Accesos rápidos de «Operación Comercial».
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_operacion_rapida.mjs
 *
 * Comprueba la reestructuración no destructiva:
 *   · el listado de Ventas trae Entrega, Entregar, Expediente, Acta y Facturar;
 *   · «Entregar» abre el expediente con el modal de entrega ya abierto;
 *   · el expediente reúne documento, entrega, incidencias, facturación e historial;
 *   · Preparación y Entregas por cliente enlazan al mismo modal;
 *   · el seguimiento se filtró dentro de Ventas (la ruta vieja redirige);
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

const EMAIL = 'prueba.operacion.rapida@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();

let ok = 0;
let mal = 0;
const verifica = (nombre, condicion, detalle = '') => {
    if (condicion) { ok++; console.log(`  ✓ ${nombre}`); }
    else { mal++; console.log(`  ✗ ${nombre}${detalle ? ' → ' + detalle : ''}`); }
};

const limpiar = () => {
    const u = sql(`SELECT IFNULL(MAX(id),0) FROM usuarios WHERE email='${EMAIL}'`);
    sql(`DELETE FROM venta_devoluciones WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE r FROM entrega_incidencia_renglones r JOIN entrega_incidencias i ON i.id=r.incidencia_id WHERE i.venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE FROM entrega_incidencias WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE FROM movimientos_inventario WHERE usuario_id=${u} OR venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE FROM auditoria WHERE entidad='seguimiento' OR (entidad='venta' AND entidad_id IN (SELECT id FROM ventas WHERE usuario_id=${u}))`);
    sql(`DELETE FROM ingresos WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE FROM venta_detalles WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE f FROM entrega_fotos f JOIN entregas e ON e.id=f.entrega_id JOIN ventas v ON v.id=e.venta_id WHERE v.usuario_id=${u}`);
    sql(`DELETE r FROM entrega_renglones r JOIN entregas e ON e.id=r.entrega_id JOIN ventas v ON v.id=e.venta_id WHERE v.usuario_id=${u}`);
    sql(`DELETE FROM entregas WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
    sql(`DELETE FROM ventas WHERE usuario_id=${u}`);
    sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
};

limpiar();

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba rápida','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');
sql('UPDATE productos SET stock = 100 WHERE id = 37');
execFileSync('php', ['-r', `require 'includes/app.php';
App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>0,'descuento_pct'=>0],[37=>['cantidad'=>10,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`], { env: { ...process.env, DB_NAME: DB } });
const venta = sql('SELECT MAX(id) FROM ventas');
const folio = sql(`SELECT folio FROM ventas WHERE id=${venta}`);

const navegador = await chromium.launch();
const errores = [];
try {
    const contexto = await navegador.newContext({ viewport: { width: 1280, height: 900 } });
    const pagina = await contexto.newPage();
    pagina.on('pageerror', (e) => errores.push(String(e)));
    pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

    await pagina.goto(BASE + '/login');
    await pagina.fill('#email', EMAIL);
    await pagina.fill('#contrasena', CLAVE);
    await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

    console.log('1. Preparación y entregas por cliente enlazan al modal');
    await pagina.goto(`${BASE}/admin/entregas`, { waitUntil: 'domcontentloaded' });
    const filaPrep = pagina.locator('tr', { hasText: folio });
    const hrefPrep = await filaPrep.locator('a:has-text("Entregar")').getAttribute('href');
    verifica('Preparación trae «Entregar» en la fila',
        (hrefPrep || '').includes(`/admin/ventas/detalle?id=${venta}`) && (hrefPrep || '').includes('accion=entrega'),
        String(hrefPrep));

    await pagina.goto(`${BASE}/admin/entregas/clientes`, { waitUntil: 'domcontentloaded' });
    const hrefCliente = await pagina.locator(`a:has-text("Registrar entrega")`).first().getAttribute('href');
    verifica('Entregas por cliente abre el mismo expediente con el modal',
        (hrefCliente || '').includes(`/admin/ventas/detalle?id=${venta}`) && (hrefCliente || '').includes('accion=entrega'),
        String(hrefCliente));

    console.log('2. Acciones rápidas en el listado de Ventas');
    await pagina.goto(`${BASE}/admin/ventas`, { waitUntil: 'domcontentloaded' });
    const fila = pagina.locator('tr', { hasText: folio });
    verifica('la fila trae Entregar', await fila.locator('a:has-text("Entregar")').count() === 1);
    verifica('la fila trae Expediente', await fila.locator('a:has-text("Expediente")').count() === 1);
    verifica('la fila trae Facturar', await fila.locator('a:has-text("Facturar")').count() === 1);
    verifica('sin entregas todavía no hay Acta', await fila.locator('a:has-text("Acta")').count() === 0);
    const textoFila = (await fila.innerText()).toLowerCase();
    verifica('la fila muestra el estado de entrega',
        textoFila.includes('por entregar'), textoFila.slice(0, 120));

    console.log('3. «Entregar» abre el expediente con el modal listo');
    await fila.locator('a:has-text("Entregar")').click();
    await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 10000 });
    verifica('el modal de entrega se abre solo desde la fila',
        await pagina.locator('#modal-cuerpo form[action="/admin/entregas/entregar"]').count() === 1);
    verifica('la URL conserva el origen para volver a Ventas',
        pagina.url().includes(`/admin/ventas/detalle?id=${venta}`) && pagina.url().includes('volver=ventas'), pagina.url());

    // Se entrega todo desde el mismo modal.
    await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
        pagina.click('[data-confirmar-modal]'),
    ]);
    verifica('la entrega quedó registrada', sql(`SELECT COUNT(*) FROM entregas WHERE venta_id=${venta}`) === '1');
    verifica('se entregaron las 10 piezas',
        sql(`SELECT COALESCE(SUM(cantidad),0) FROM entrega_renglones r JOIN entregas e ON e.id=r.entrega_id WHERE e.venta_id=${venta}`) === '10');

    console.log('4. El expediente reúne todo');
    for (const seccion of ['resumen', 'entrega', 'fotos-entrega', 'incidencias', 'cambios', 'facturacion', 'historial']) {
        verifica(`trae #${seccion}`, await pagina.locator(`#${seccion}`).count() === 1);
    }
    verifica('las pestañas del expediente son 5', await pagina.locator('.expediente-tabs .chip').count() === 5);
    verifica('el acta de la venta está en el encabezado',
        await pagina.locator('a:has-text("Acta de entrega")').count() >= 1);

    console.log('5. Después de entregar, el listado cambia');
    await pagina.goto(`${BASE}/admin/ventas`, { waitUntil: 'domcontentloaded' });
    const filaDespues = pagina.locator('tr', { hasText: folio });
    verifica('aparece el botón Acta', await filaDespues.locator('a:has-text("Acta")').count() === 1);
    const textoDespues = (await filaDespues.innerText()).toLowerCase();
    verifica('el estado de entrega ya es Entregado',
        textoDespues.includes('entregado'), textoDespues.slice(0, 120));
    const hrefActa = await filaDespues.locator('a:has-text("Acta")').getAttribute('href');
    verifica('el Acta apunta al PDF de la venta',
        hrefActa === `/admin/entregas-seguimiento/acta?id=${venta}`, String(hrefActa));

    console.log('6. Filtros de entrega y redirección del seguimiento');
    await pagina.goto(`${BASE}/admin/ventas?entrega=entregado`, { waitUntil: 'domcontentloaded' });
    verifica('el filtro «Entregadas» incluye la venta',
        await pagina.locator('tr', { hasText: folio }).count() === 1);
    await pagina.goto(`${BASE}/admin/ventas?entrega=pendiente`, { waitUntil: 'domcontentloaded' });
    verifica('el filtro «Por entregar» ya no la incluye',
        await pagina.locator('tr', { hasText: folio }).count() === 0);

    await pagina.goto(`${BASE}/admin/entregas-seguimiento?estado=pendiente`, { waitUntil: 'domcontentloaded' });
    verifica('la ruta vieja de seguimiento redirige a Ventas con su filtro',
        pagina.url().includes('/admin/ventas') && pagina.url().includes('entrega=pendiente'), pagina.url());

    console.log('7. Móvil');
    await pagina.setViewportSize({ width: 390, height: 800 });
    for (const ruta of ['/admin/ventas', `/admin/ventas/detalle?id=${venta}`]) {
        await pagina.goto(BASE + ruta, { waitUntil: 'domcontentloaded' });
        const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        verifica(`sin desborde horizontal en ${ruta.split('?')[0]}`, !desborde);
    }

    console.log('8. Consola');
    verifica('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (e) {
    mal++;
    console.log('  ✗ se interrumpió → ' + e.message.split('\n')[0]);
} finally {
    limpiar();
    sql('UPDATE productos SET stock = 100 WHERE id = 37');
    await navegador.close();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

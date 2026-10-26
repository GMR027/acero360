/**
 * pruebas/probar_operacion.mjs — Sección única «Operación Comercial» y su hub por venta.
 *
 *   1. Un solo enlace de menú (no tres) que cubre entregas, seguimiento y ventas.
 *   2. Pestañas de 4 en las vistas hermanas (preparación, por cliente, seguimiento, ventas).
 *   3. El detalle de venta enlaza al hub y ya no trae el bloque de devoluciones.
 *   4. El hub abre en MODAL entrega, incidencia y devolución (mismos formularios
 *      que las páginas sueltas) y los envía: faltante devuelve a inventario y
 *      fuerza la corrección de factura; devolución sólo notifica.
 *   5. El modal de entrega admite una o varias fotos del acta: quedan en
 *      entrega_fotos + public/image y la galería aparece en el seguimiento.
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_operacion.mjs   (servidor en :8017)
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, unlinkSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.operacion@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const RAIZ = new URL('..', import.meta.url).pathname;
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const limpiar = () => {
  const u = sql(`SELECT IFNULL(MAX(id),0) FROM usuarios WHERE email='${EMAIL}'`);
  sql(`DELETE FROM venta_devoluciones WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE r FROM entrega_incidencia_renglones r JOIN entrega_incidencias i ON i.id=r.incidencia_id WHERE i.venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM entrega_incidencias WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM movimientos_inventario WHERE usuario_id=${u} OR venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM auditoria WHERE entidad='seguimiento' OR (entidad='venta' AND entidad_id IN (SELECT id FROM ventas WHERE usuario_id=${u})) OR (entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})))`);
  sql(`DELETE FROM ingresos WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM venta_detalles WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  // Entregas y sus fotos del acta: primero los archivos de disco y luego las
  // filas (entregas → ventas es RESTRICT, hay que vaciarlas antes de borrar la venta).
  const fotos = sql(`SELECT GROUP_CONCAT(f.archivo SEPARATOR '|') FROM entrega_fotos f
                     JOIN entregas e ON e.id = f.entrega_id
                     JOIN ventas v ON v.id = e.venta_id WHERE v.usuario_id=${u}`);
  if (fotos) {
    for (const archivo of fotos.split('|')) {
      try { unlinkSync(`${RAIZ}public/image/${archivo}`); } catch { /* ya no está */ }
    }
  }
  sql(`DELETE f FROM entrega_fotos f JOIN entregas e ON e.id=f.entrega_id JOIN ventas v ON v.id=e.venta_id WHERE v.usuario_id=${u}`);
  sql(`DELETE r FROM entrega_renglones r JOIN entregas e ON e.id=r.entrega_id JOIN ventas v ON v.id=e.venta_id WHERE v.usuario_id=${u}`);
  sql(`DELETE FROM entregas WHERE venta_id IN (SELECT id FROM ventas WHERE usuario_id=${u})`);
  sql(`DELETE FROM ventas WHERE usuario_id=${u}`);
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
};
limpiar();

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba operación','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');
sql('UPDATE productos SET stock = 100 WHERE id = 37');
execFileSync('php', ['-r', `require '${RAIZ}/includes/app.php';
App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>0,'descuento_pct'=>0],[37=>['cantidad'=>10,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`], { env: { ...process.env, DB_NAME: DB } });
const venta = sql('SELECT MAX(id) FROM ventas');
// La venta queda facturada: el motivo «faltante» debe FORZAR la corrección de factura.
sql(`UPDATE ingresos SET estado_facturacion='facturada', uuid_fiscal='OP-FACT-${venta}' WHERE venta_id=${venta}`);
const stock = () => parseInt(sql('SELECT stock FROM productos WHERE id = 37'), 10);
const s0 = stock();

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

  console.log('1. Un solo menú «Operación Comercial»');
  const textosMenu = await pagina.locator('.sidebar__texto').allTextContents();
  verifica('existe UN enlace «Operación Comercial»', textosMenu.filter((t) => t.trim() === 'Operación Comercial').length === 1, textosMenu.join(' | '));
  verifica('desaparecieron «Pantalla de entrega»', !textosMenu.some((t) => t.includes('Pantalla de entrega')));
  verifica('desapareció «Seguimiento de entregas» del menú', !textosMenu.some((t) => t.includes('Seguimiento de entregas')));
  verifica('desapareció «Ventas y devoluciones»', !textosMenu.some((t) => t.includes('Ventas y devoluciones')));

  console.log('2. Pestañas de la sección (4 vistas hermanas)');
  await pagina.goto(`${BASE}/admin/entregas`, { waitUntil: 'domcontentloaded' });
  const chips = await pagina.locator('.entregas-tabs .chip').allTextContents();
  verifica('cuatro pestañas en preparación', chips.length === 4, chips.join(' | '));
  verifica('la pestaña activa es Preparación', (await pagina.locator('.entregas-tabs .chip[aria-current="page"]').textContent()).includes('Preparación'));
  await pagina.goto(`${BASE}/admin/ventas`, { waitUntil: 'domcontentloaded' });
  verifica('las ventas también llevan la barra', await pagina.locator('.entregas-tabs .chip').count() === 4);
  verifica('la pestaña activa es Ventas', (await pagina.locator('.entregas-tabs .chip[aria-current="page"]').textContent()).includes('Ventas'));
  verifica('el menú resalta Operación Comercial en /admin/ventas',
    await pagina.locator('.sidebar__enlace--activo .sidebar__texto', { hasText: 'Operación Comercial' }).count() === 1);

  console.log('3. El detalle de venta apunta al hub');
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${venta}`, { waitUntil: 'domcontentloaded' });
  verifica('botón «Operación de esta venta»', (await pagina.locator(`a[href*="entregas-seguimiento/venta?id=${venta}"]`).count()) >= 1);
  verifica('ya NO trae el bloque #cambios (evita dos vías)', await pagina.locator('#cambios').count() === 0);

  console.log('4. Modales del hub');
  await pagina.goto(`${BASE}/admin/entregas-seguimiento/venta?id=${venta}`, { waitUntil: 'domcontentloaded' });
  verifica('el hub muestra las tres acciones', await pagina.locator('[data-modal-form]').count() === 3,
    String(await pagina.locator('[data-modal-form]').count()));

  await pagina.click(`a[data-modal-form="tpl-entrega"]`);
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  verifica('modal de entrega con su formulario real',
    await pagina.locator('#modal-cuerpo form[action="/admin/entregas/entregar"]').count() === 1
    && (await pagina.locator('#modal-cuerpo input[name="volver"]').inputValue()) === 'seguimiento');
  verifica('la fecha y las piezas están precargadas', await pagina.locator('#modal-cuerpo input[name="fecha"]').count() === 1
    && (await pagina.locator('#modal-cuerpo input[name="cantidad[37]"]').inputValue()) === '10');
  verifica('el formulario del modal admite varias fotos del acta',
    await pagina.locator('#modal-cuerpo input[name="fotos[]"][multiple]').count() === 1
    && (await pagina.locator('#modal-cuerpo form[action="/admin/entregas/entregar"]').getAttribute('enctype')) === 'multipart/form-data');
  await pagina.click('[data-cerrar-modal]');

  await pagina.click(`a[data-modal-form="tpl-incidencia"]`);
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  verifica('modal de incidencia con data-incidencia', await pagina.locator('#modal-cuerpo form[data-incidencia]').count() === 1);

  // Faltante en venta facturada → la corrección de factura se marca sola…
  await pagina.selectOption('#modal-cuerpo select[name="motivo"]', 'producto_faltante');
  verifica('«faltante» fuerza la casilla de corregir factura (JS)',
    await pagina.locator('#modal-cuerpo input[name="factura_requerida"]:checked').count() === 1);
  // …y el servidor la fuerza aunque se desmarque.
  await pagina.uncheck('#modal-cuerpo input[name="factura_requerida"]');
  await pagina.fill('#modal-cuerpo input[name="cantidad[37]"]', '2');
  await Promise.all([
    pagina.waitForURL(/entregas-seguimiento\/venta\?id=\d+#incidencias/, { timeout: 15000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  const inc = sql(`SELECT COUNT(*) FROM entrega_incidencias WHERE venta_id=${venta} AND motivo='producto_faltante' AND factura_requerida=1`);
  verifica('incidencia registrada con la corrección FORZADA por el servidor', inc === '1', inc);
  verifica('el faltante devolvió 2 piezas al inventario', stock() === s0 + 2, `${s0} → ${stock()}`);
  verifica('quedó anotada en venta_devoluciones',
    sql(`SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id=${venta} AND producto_id=37`) === '1');

  // Devolución desde el modal: sólo notificación.
  await pagina.click(`a[data-modal-form="tpl-devolucion"]`);
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  await pagina.fill('#modal-cuerpo input[name="cantidad[37]"]', '1');
  await Promise.all([
    pagina.waitForURL(/entregas-seguimiento\/venta\?id=\d+/, { timeout: 15000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  verifica('la devolución NO mueve el inventario', stock() === s0 + 2, `${s0 + 2} → ${stock()}`);
  verifica('quedó anotada como notificación',
    sql(`SELECT COUNT(*) FROM venta_devoluciones WHERE venta_id=${venta} AND producto_id=37`) === '2');
  verifica('el hub refleja el historial (incidencia + devolución)',
    await pagina.locator('#cambios tbody tr').count() === 2,
    String(await pagina.locator('#cambios tbody tr').count()));

  console.log('5. Fotos del acta de entrega (modal de entrega)');
  await pagina.click(`a[data-modal-form="tpl-entrega"]`);
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  await pagina.setInputFiles('#modal-cuerpo input[name="fotos[]"]', [
    { name: 'acta-1.jpg', mimeType: 'image/jpeg', buffer: readFileSync(`${RAIZ}public/image/producto-bb1.jpg`) },
    { name: 'acta-2.jpg', mimeType: 'image/jpeg', buffer: readFileSync(`${RAIZ}public/image/producto-ra1.jpg`) },
  ]);
  // Al confirmar, la entrega se registra con sus fotos y el servidor devuelve
  // al seguimiento, donde ya debe verse la galería (antes no había ninguna).
  await Promise.all([
    pagina.locator('.seguimiento-fotos__imagen').first().waitFor({ timeout: 15000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  const archivos = sql(`SELECT GROUP_CONCAT(f.archivo SEPARATOR '|') FROM entrega_fotos f
                        JOIN entregas e ON e.id = f.entrega_id WHERE e.venta_id=${venta}`);
  const lista = archivos ? archivos.split('|') : [];
  verifica('las 2 fotos quedaron registradas contra la entrega', lista.length === 2, archivos || 'ninguna');
  verifica('los archivos viven en public/image con nombre del servidor',
    lista.length === 2 && lista.every((n) => n.startsWith('entrega-') && existsSync(`${RAIZ}public/image/${n}`)),
    lista.join(' | '));
  verifica('la galería muestra las 2 fotos en el seguimiento',
    await pagina.locator('.seguimiento-fotos__imagen').count() === 2,
    String(await pagina.locator('.seguimiento-fotos__imagen').count()));

  console.log('6. Consola');
  verifica('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (e) {
  mal += 1;
  console.log('  ✗ se interrumpió → ' + e.message);
} finally {
  limpiar();
  sql('UPDATE productos SET stock = 100 WHERE id = 37');
  await navegador.close();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

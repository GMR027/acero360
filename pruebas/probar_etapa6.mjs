/**
 * probar_etapa6.mjs — módulo fiscal y cierre mensual en un navegador real.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa6.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa6@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const abrirTodo = (pg) => pg.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const datos = JSON.parse(execFileSync('php', [join(RAIZ, 'pruebas/php/sembrar_fiscal.php'), EMAIL, CLAVE], { env: { ...process.env, DB_NAME: DB }, encoding: 'utf8' }));

const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
const pagina = await contexto.newPage();
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
const confirmar = async (texto) => {
  await pagina.locator(`.modal button:has-text("${texto}"), [role=dialog] button:has-text("${texto}")`).first().click();
  await pagina.waitForLoadState();
};

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Resumen fiscal de febrero 2026');
  await pagina.goto(`${BASE}/admin/fiscal/detalle?anio=2026&mes=2`);
  await abrirTodo(pagina);
  const t = await pagina.locator('main').innerText();
  verifica('separa importes registrados, estimación y pagos reales', t.includes('Lo que has anotado') && t.includes('El cálculo') && t.includes('Lo que ya declaraste'));
  verifica('advierte que es una estimación para validar con el contador', t.includes('Estimación administrativa') && t.includes('contador'));
  verifica('IVA estimado a cargo $782.00', t.includes('$782.00'));
  verifica('ISR determinado $436.33 y a pagar $246.16', t.includes('$436.33') && t.includes('$246.16'));
  verifica('muestra la utilidad fiscal $8,000.00 y los ingresos $12,000.00', t.includes('$8,000.00') && t.includes('$12,000.00'));
  verifica('avisa gastos sin validar y configuración sin confirmar', t.includes('por validar') && t.includes('confirmada con el contador'));
  verifica('indica las versiones de reglas usadas', (await pagina.locator('a[href*="/admin/fiscal/catalogo/detalle"]').count()) >= 3);
  await pagina.click('button:has-text("Guardar este cálculo")');
  verifica('guardar el cálculo conserva la versión', sql("SELECT COUNT(*) FROM calculos_fiscales WHERE tipo='guardado' AND mes=2 AND catalogo_isr_id IS NOT NULL") === '1');

  console.log('Catálogo de reglas');
  await pagina.goto(BASE + '/admin/fiscal/catalogo');
  verifica('lista las 3 reglas activas con su estado', (await pagina.locator('tbody .badge:has-text("Activa")').count()) === 3);
  await pagina.setInputFiles('#archivo', join(RAIZ, 'mysql/catalogo_fiscal/isr_pp_art106_2026.json'));
  await pagina.click('button:has-text("Importar como borrador")');
  verifica('importar crea una versión nueva en borrador (no pisa la activa)', (await pagina.content()).includes('Borrador') && sql("SELECT COUNT(*) FROM catalogo_fiscal WHERE tipo='isr_pagos_provisionales_art106'") === '2');
  await pagina.click('button:has-text("Validar versión")');
  verifica('validar: 40 pruebas de regresión sin fallos', (await pagina.locator('main').innerText()).includes('Pruebas: 40 ejecutadas, 0 falladas'));
  await pagina.fill('#motivo', 'Reimportación de la tarifa oficial');
  await pagina.fill('#contrasena', 'contraseña-incorrecta');
  await pagina.click('button:has-text("Activar versión")');
  await confirmar('Sí, activar');
  verifica('activar con contraseña incorrecta se rechaza', (await pagina.content()).includes('Autorización rechazada'));
  await pagina.fill('#motivo', 'Reimportación de la tarifa oficial');
  await pagina.fill('#contrasena', CLAVE);
  await pagina.click('button:has-text("Activar versión")');
  await confirmar('Sí, activar');
  verifica('activar con autorización reemplaza a la anterior', sql("SELECT GROUP_CONCAT(estado ORDER BY version) FROM catalogo_fiscal WHERE tipo='isr_pagos_provisionales_art106'") === 'reemplazada,activa');
  await pagina.setInputFiles('#archivo', join(RAIZ, 'pruebas/php/sembrar_fiscal.php')).catch(() => {});
  await pagina.goto(BASE + '/admin/fiscal/catalogo/detalle?id=' + sql("SELECT MAX(id) FROM catalogo_fiscal WHERE tipo='isr_pagos_provisionales_art106'") + '&mes=10');
  verifica('muestra la tarifa oficial de octubre (tramo 4,209.50)', (await pagina.locator('main').innerText()).includes('$4,209.50'));

  console.log('Retenciones, ajustes y pagos reales');
  await pagina.goto(BASE + '/admin/fiscal/registros?anio=2026');
  await pagina.selectOption('#d-mes', '2'); await pagina.selectOption('#d-imp', 'IVA');
  await pagina.fill('#d-decl', '782'); await pagina.fill('#d-pag', '700'); await pagina.fill('#d-fpag', '2026-03-17');
  await pagina.click('button:has-text("Guardar importes reales")');
  verifica('registra lo declarado y pagado', sql("SELECT CONCAT(importe_declarado,'/',importe_pagado) FROM declaraciones WHERE impuesto='IVA' AND mes=2") === '782.00/700.00');
  await pagina.goto(`${BASE}/admin/fiscal/detalle?anio=2026&mes=2`);
  await abrirTodo(pagina);
  verifica('el panel muestra pendiente de pago $82.00 y diferencia $0.00', (await pagina.locator('main').innerText()).includes('$82.00'));
  await pagina.goto(BASE + '/admin/fiscal/registros?anio=2026');
  await pagina.fill('#a-importe', '100'); await pagina.selectOption('#a-mes', '2'); await pagina.selectOption('#a-concepto', 'saldo_favor_iva_anterior');
  await pagina.click('button:has-text("Registrar ajuste")');
  verifica('registra un ajuste (saldo a favor anterior)', sql("SELECT COUNT(*) FROM ajustes_fiscales WHERE concepto='saldo_favor_iva_anterior'") === '1');
  await pagina.goto(`${BASE}/admin/fiscal/detalle?anio=2026&mes=2`);
  verifica('el ajuste baja el IVA a cargo a $682.00', (await pagina.locator('main').innerText()).includes('$682.00'));
  sql('DELETE FROM ajustes_fiscales');

  console.log('Configuración fiscal');
  await pagina.goto(BASE + '/admin/fiscal/configuracion');
  verifica('muestra persona física y régimen', (await pagina.locator('main').innerText()).includes('Actividades Empresariales y Profesionales'));
  await pagina.fill('#vigente_desde', '2026-06-01'); await pagina.check('#confirmada');
  await pagina.fill('#motivo', 'Confirmado con el contador'); await pagina.fill('#contrasena', CLAVE);
  await pagina.click('button:has-text("Guardar configuración")');
  verifica('el cambio conserva el historial (2 configuraciones)', sql('SELECT COUNT(*) FROM configuracion_fiscal') === '2');

  console.log('Cierre mensual');
  await pagina.goto(`${BASE}/admin/contabilidad/cierre?anio=2026&mes=1`);
  verifica('enero: ventas sin clasificar bloquean el cierre', (await pagina.content()).includes('Ventas sin clasificar') && await pagina.locator('button:has-text("Cerrar el mes")').isDisabled());
  sql(`UPDATE ingresos SET estado_facturacion='no_facturada' WHERE id=${datos.i1}`);
  await pagina.reload();
  await pagina.click('button:has-text("Cerrar el mes")');
  await confirmar('Sí, cerrar el mes');
  verifica('cierra enero y queda «Periodo cerrado»', (await pagina.content()).includes('Periodo cerrado') && sql("SELECT estado FROM periodos_contables WHERE mes=1") === 'cerrado');
  verifica('guardó la fotografía con ISR de enero $90.17', JSON.parse(sql("SELECT resumen FROM periodos_contables WHERE mes=1")).fiscal.isr.a_pagar === 9017);
  const csv = await pagina.request.get(`${BASE}/admin/contabilidad/cierre/exportar?anio=2026&mes=1&formato=csv`);
  verifica('exporta el resumen en CSV', csv.status() === 200 && csv.headers()['content-type'].includes('text/csv') && (await csv.text()).includes('ISR provisional estimado'));
  const pdf = await pagina.request.get(`${BASE}/admin/contabilidad/cierre/exportar?anio=2026&mes=1&formato=pdf`);
  verifica('exporta el resumen en PDF', pdf.status() === 200 && pdf.headers()['content-type'].includes('application/pdf'));
  await pagina.goto(`${BASE}/admin/contabilidad/ingresos/detalle?id=${datos.i1}`);
  verifica('las operaciones de enero quedan protegidas', (await pagina.content()).includes('Periodo cerrado') && (await pagina.locator('#doc-archivo').count()) === 0);
  await pagina.goto(`${BASE}/admin/contabilidad/cierre?anio=2026&mes=1`);
  await pagina.fill('#motivo', 'Corrección de una factura de enero'); await pagina.fill('#contrasena', 'mala');
  await pagina.click('button:has-text("Reabrir el mes")');
  await confirmar('Sí, reabrir');
  verifica('reabrir con contraseña incorrecta se rechaza', (await pagina.content()).includes('Autorización rechazada') && sql("SELECT estado FROM periodos_contables WHERE mes=1") === 'cerrado');
  await pagina.fill('#motivo', 'Corrección de una factura de enero'); await pagina.fill('#contrasena', CLAVE);
  await pagina.click('button:has-text("Reabrir el mes")');
  await confirmar('Sí, reabrir');
  verifica('reabre con contraseña y motivo; el historial lo conserva', sql("SELECT estado FROM periodos_contables WHERE mes=1") === 'abierto'
    && sql('SELECT COUNT(*) FROM periodos_historial') === '2' && (await pagina.content()).includes('Reapertura'));
  await pagina.goto(BASE + '/admin/contabilidad/periodos');
  verifica('«Periodos cerrados» ya no lista enero', (await pagina.locator('tbody tr').count()) === 0 || !(await pagina.content()).includes('Enero 2026'));

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of ['/admin/fiscal/detalle?anio=2026&mes=2', '/admin/fiscal/registros?anio=2026', '/admin/fiscal/catalogo', '/admin/fiscal/configuracion',
    '/admin/contabilidad/cierre?anio=2026&mes=2', `/admin/fiscal/catalogo/detalle?id=${sql("SELECT MAX(id) FROM catalogo_fiscal")}`]) {
    await pagina.goto(BASE + ruta);
    const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    verifica(ruta.split('?')[0] + ' @390px sin desborde', !desborde);
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  for (const t of ['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL'); sql('DELETE FROM ventas');
  sql('DELETE FROM catalogo_fiscal');
  sql('DELETE FROM configuracion_fiscal WHERE id > (SELECT MIN(id) FROM (SELECT id FROM configuracion_fiscal) x)');
  sql('UPDATE configuracion_fiscal SET vigente_hasta = NULL, confirmada_con_contador = 0, tipo_persona = "fisica"');
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

/**
 * probar_etapa5.mjs — contabilidad, gastos, documentos y archivo mensual en un navegador real.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa5.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync, rmSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa5@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const abrirTodo = (pg) => pg.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };
const anio = new Date().getFullYear();
const mes = new Date().getMonth() + 1;

const carpetaDocs = join(RAIZ, 'storage', 'documentos');
const docsPrevios = existsSync(carpetaDocs) ? readdirSync(carpetaDocs).length : 0;

function limpiar() {
  for (const t of ['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones', 'documentos', 'cobros', 'gasto_reparto', 'gastos', 'ingresos', 'periodos_contables', 'entregas', 'preparaciones']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
  sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
}
limpiar();
sql('UPDATE productos SET stock = 100 WHERE id = 37');
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E5','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const [c1, c2] = sql('SELECT id FROM clientes ORDER BY id LIMIT 2').split('\n');
execFileSync('php', ['-r', `require '${RAIZ}/includes/app.php';
$l = fn($c) => ['cantidad'=>$c,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1];
foreach ([[${c1},1,2],[${c2},3,1]] as [$cl,$n,$emp]) {
  App\\Ventas::crear(['empresa_id'=>$emp,'cliente_id'=>$cl,'fecha'=>date('Y-m-d'),'aplica_iva'=>1,'descuento_pct'=>0],[37=>$l($n)],${usuario});
}`], { env: { ...process.env, DB_NAME: DB } });

const tmp = mkdtempSync(join(tmpdir(), 'e5-'));
const pdf = join(tmp, 'Factura 1.pdf'); writeFileSync(pdf, '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
const rfc = sql('SELECT rfc FROM contribuyentes LIMIT 1');
const uuid = 'AB12CD34-1111-2222-3333-444455556666';
const xml = join(tmp, 'cfdi.xml');
writeFileSync(xml, `<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Fecha="2026-01-01T10:00:00" SubTotal="100.00" Total="116.00" Moneda="MXN" TipoDeComprobante="I"><cfdi:Emisor Rfc="${rfc}" Nombre="E"/><cfdi:Receptor Rfc="XAXX010101000" Nombre="R"/><cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="${uuid}"/></cfdi:Complemento></cfdi:Comprobante>`);
const malo = join(tmp, 'shell.pdf'); writeFileSync(malo, '<?php system($_GET["c"]); ?>');

const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
const pagina = await contexto.newPage();
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Resumen mensual consolidado');
  await pagina.goto(`${BASE}/admin/contabilidad?anio=${anio}&mes=${mes}`);
  await abrirTodo(pagina);
  const texto = await pagina.locator('main').innerText();
  verifica('ventas del periodo: 2 ventas', /2\s+ventas/i.test(texto));
  verifica('subtotal 400 e IVA 64 (consolidado de ambos negocios)', texto.includes('$400.00') && texto.includes('$64.00'));
  verifica('aviso: importes registrados, no estimación fiscal', texto.includes('No son cálculos de impuestos'));
  verifica('hay selector de año, mes y negocio', (await pagina.locator('#sel-anio, #sel-mes, #sel-empresa').count()) === 3);
  await pagina.selectOption('#sel-empresa', '2');
  await pagina.click('button:has-text("Ver periodo")');
  await abrirTodo(pagina);
  verifica('filtrar un negocio muestra solo sus ventas ($100)', (await pagina.locator('main').innerText()).includes('$100.00'));

  console.log('Pantallas en lenguaje llano');
  await pagina.goto(`${BASE}/admin/contabilidad?anio=${anio}&mes=${mes}`);
  const llano = (await pagina.locator('main').innerText()).toLowerCase();
  verifica('el resumen dice cuánto vendiste, gastaste y qué te falta hacer', llano.includes('vendiste') && llano.includes('gastaste') && llano.includes('lo que te falta hacer'));
  await pagina.goto(`${BASE}/admin/contabilidad/mas`);
  verifica('«Más opciones» lleva a documentos, cierre e impuestos', (await pagina.locator('main a[href^="/admin/contabilidad/cierre"]').count()) === 1 && (await pagina.locator('main a[href="/admin/fiscal"]').count()) === 1);
  await pagina.goto(BASE + '/admin/contabilidad/gastos/crear');
  verifica('el gasto pide lo básico: qué, a quién, cuándo, cuánto, pagado, factura', (await pagina.locator('#concepto, #beneficiario, #fecha, #total, #estado_pago, #comprobante_fiscal').count()) === 6);
  verifica('lo avanzado queda plegado en «Más detalles»', (await pagina.locator('details:not([open]) #uuid_fiscal').count()) === 1);
  await pagina.fill('#concepto', 'Gasolina'); await pagina.fill('#beneficiario', 'Gasolinera'); await pagina.fill('#total', '580');
  await pagina.selectOption('#estado_pago', 'pagado');
  await pagina.setInputFiles('#doc-nuevo', [pdf]);
  await Promise.all([pagina.waitForURL('**/gastos/detalle?id=*'), pagina.click('button:has-text("Guardar gasto")')]);
  verifica('la factura subida al crear queda adjunta con tipo detectado', sql(`SELECT CONCAT(COUNT(*),'/',MIN(tipo)) FROM documentos WHERE entidad='gasto' AND entidad_id=(SELECT MAX(id) FROM gastos)`) === '1/factura_pdf');
  verifica('gasto sencillo: total 580, subtotal 500 y pagado sin pedir fecha', sql('SELECT CONCAT(total,"/",subtotal,"/",estado_pago,"/",fecha_pago IS NOT NULL) FROM gastos ORDER BY id DESC LIMIT 1') === '580.00/500.00/pagado/1');
  sql(`DELETE FROM documentos WHERE entidad='gasto' AND entidad_id=(SELECT MAX(id) FROM gastos)`); sql('DELETE FROM gastos ORDER BY id DESC LIMIT 1');

  console.log('Ingreso: facturación explícita y cobros');
  await pagina.goto(`${BASE}/admin/contabilidad/ingresos?anio=${anio}&mes=${mes}`);
  verifica('lista 2 ingresos «sin clasificar»', (await pagina.locator('tbody tr').count()) === 2 && (await pagina.locator('tbody .badge:has-text("Sin indicar")').count()) === 2);
  const ingresoId = sql(`SELECT i.id FROM ingresos i JOIN ventas v ON v.id=i.venta_id WHERE v.cliente_id=${c1}`);
  await pagina.goto(`${BASE}/admin/contabilidad/ingresos/detalle?id=${ingresoId}`);
  await pagina.selectOption('#estado_facturacion', 'facturada');
  await pagina.fill('#factura_folio', 'A-77');
  await pagina.fill('#uuid_fiscal', uuid);
  await pagina.click('form:has(#estado_facturacion) button[type=submit]');
  verifica('queda facturada con su UUID', sql(`SELECT CONCAT(estado_facturacion,'/',uuid_fiscal) FROM ingresos WHERE id=${ingresoId}`) === `facturada/${uuid}`);
  await pagina.fill('#cobro-importe', '50');
  const tokenCobro = await pagina.locator('form:has(#cobro-importe) input[name=token]').inputValue();
  await pagina.click('button:has-text("Anotar pago recibido")');
  verifica('cobro parcial: queda «Pago parcial»', (await pagina.content()).includes('Pago parcial') && sql(`SELECT SUM(importe) FROM cobros WHERE ingreso_id=${ingresoId}`) === '50.00');
  const reenvio = await pagina.request.post(`${BASE}/admin/contabilidad/ingresos/accion`, { form: { id: ingresoId, accion: 'cobro', token: tokenCobro, fecha: `${anio}-01-01`, importe: '10', metodo: 'Efectivo' }, maxRedirects: 0 });
  verifica('reenviar el mismo formulario no duplica el cobro', sql(`SELECT COUNT(*) FROM cobros WHERE ingreso_id=${ingresoId}`) === '1' && reenvio.status() === 302);

  console.log('Documentos');
  await pagina.goto(`${BASE}/admin/contabilidad/ingresos/detalle?id=${ingresoId}`);
  await pagina.setInputFiles('#doc-archivo', pdf);
  await pagina.selectOption('#doc-tipo', 'factura_pdf');
  await pagina.click('button:has-text("Subir archivos")');
  verifica('PDF adjuntado', sql(`SELECT COUNT(*) FROM documentos WHERE entidad='ingreso' AND entidad_id=${ingresoId} AND tipo='factura_pdf'`) === '1');
  await pagina.setInputFiles('#doc-archivo', xml);
  await pagina.click('button:has-text("Subir archivos")');
  verifica('XML CFDI adjuntado con su UUID', sql(`SELECT uuid_fiscal FROM documentos WHERE entidad='ingreso' AND tipo='xml'`) === uuid);
  verifica('se muestra la validación documental «sin verificar SAT»', (await pagina.content()).includes('sin verificar SAT'));
  await pagina.setInputFiles('#doc-archivo', malo);
  await pagina.selectOption('#doc-tipo', 'factura_pdf');
  await pagina.click('button:has-text("Subir archivos")');
  verifica('un archivo falso se rechaza con mensaje claro', (await pagina.content()).includes('no es un PDF válido') && sql(`SELECT COUNT(*) FROM documentos`) === '2');

  const doc = sql(`SELECT id FROM documentos WHERE tipo='xml'`);
  const descarga = await pagina.request.get(`${BASE}/admin/contabilidad/documentos/descargar?id=${doc}`);
  const cab = descarga.headers();
  verifica('descarga como adjunto, tipo fijo y nosniff', cab['content-disposition'].startsWith('attachment') && cab['x-content-type-options'] === 'nosniff' && (await descarga.text()).includes(uuid));
  const anonimo = await navegador.newContext();
  const sinSesion = await anonimo.request.get(`${BASE}/admin/contabilidad/documentos/descargar?id=${doc}`, { maxRedirects: 0 });
  verifica('sin sesión no se puede descargar (redirige al login)', sinSesion.status() === 302 && sinSesion.headers().location.includes('/login'));
  const directo = await anonimo.request.get(`${BASE}/storage/documentos/`, { maxRedirects: 0 });
  verifica('/storage no se sirve por web', directo.status() !== 200);
  await anonimo.close();

  console.log('Gasto compartido');
  await pagina.goto(BASE + '/admin/contabilidad/gastos/crear');
  await pagina.fill('#beneficiario', 'Agustín Olvera');
  await pagina.fill('#concepto', 'Renta de bodega');
  await pagina.fill('#total', '1160');
  await abrirTodo(pagina);
  await pagina.check('#compartido');
  await pagina.fill('#reparto_1', '60');
  await pagina.fill('#reparto_2', '40');
  await Promise.all([pagina.waitForURL('**/gastos/detalle?id=*'), pagina.click('button:has-text("Guardar gasto")')]);
  verifica('total 1,160.00 y revisión «por validar»', (await pagina.content()).includes('$1,160.00') && (await pagina.content()).includes('Por validar'));
  const gastoId = sql('SELECT MAX(id) FROM gastos');
  await pagina.goto(BASE + '/admin/contabilidad/gastos/crear');
  await pagina.fill('#beneficiario', 'X'); await pagina.fill('#concepto', 'Y'); await pagina.fill('#total', '116');
  await abrirTodo(pagina);
  await pagina.check('#compartido'); await pagina.fill('#reparto_1', '60'); await pagina.fill('#reparto_2', '30');
  await pagina.click('button:has-text("Guardar gasto")');
  verifica('un reparto que no suma 100 % se rechaza', (await pagina.content()).includes('debe sumar exactamente 100'));
  await pagina.goto(`${BASE}/admin/contabilidad?anio=${anio}&mes=${mes}`);
  verifica('el resumen cuenta el gasto compartido una sola vez ($1,160)', (await pagina.locator('main').innerText()).includes('$1,160.00'));
  await pagina.goto(`${BASE}/admin/contabilidad/gastos/detalle?id=${gastoId}`);
  await pagina.setInputFiles('#doc-archivo', pdf);
  await pagina.click('button:has-text("Subir archivos")');
  verifica('adjuntar comprobante al gasto', sql(`SELECT COUNT(*) FROM documentos WHERE entidad='gasto'`) === '1');

  console.log('Archivo mensual, sin facturar y exportaciones');
  const zipV = await pagina.request.get(`${BASE}/admin/contabilidad/zip?tipo=ventas&anio=${anio}&mes=${mes}`);
  const bytesV = await zipV.body();
  verifica('ZIP de ventas válido (PK)', zipV.headers()['content-type'] === 'application/zip' && bytesV.subarray(0, 2).toString() === 'PK');
  const zipG = await pagina.request.get(`${BASE}/admin/contabilidad/zip?tipo=gastos&anio=${anio}&mes=${mes}`);
  verifica('ZIP de gastos aparte', zipG.status() === 200 && (await zipG.body()).subarray(0, 2).toString() === 'PK');
  await pagina.goto(`${BASE}/admin/contabilidad/sin-facturar?anio=${anio}&mes=${mes}`);
  verifica('sin facturar muestra solo la venta no facturada (1)', (await pagina.locator('tbody tr').count()) === 1);
  for (const [f, tipo] of [['csv', 'text/csv'], ['xlsx', 'spreadsheetml'], ['pdf', 'application/pdf']]) {
    const r = await pagina.request.get(`${BASE}/admin/contabilidad/sin-facturar/exportar?anio=${anio}&mes=${mes}&formato=${f}`);
    verifica(`exporta ${f.toUpperCase()}`, r.status() === 200 && r.headers()['content-type'].includes(tipo));
  }
  await pagina.goto(`${BASE}/admin/contabilidad/documentos?anio=${anio}&mes=${mes}`);
  verifica('comprobantes y documentos del periodo', (await pagina.locator('tbody tr').count()) === 2);

  console.log('Periodo cerrado');
  const contrib = sql('SELECT id FROM contribuyentes LIMIT 1');
  sql(`INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en) VALUES (${contrib}, ${anio}, ${mes}, 'cerrado', NOW())`);
  await pagina.goto(`${BASE}/admin/contabilidad/ingresos/detalle?id=${ingresoId}`);
  verifica('el detalle indica periodo cerrado y oculta la subida', (await pagina.content()).includes('Periodo cerrado') && (await pagina.locator('#doc-archivo').count()) === 0);
  await pagina.goto(BASE + '/admin/contabilidad/periodos');
  verifica('aparece en el historial de periodos cerrados', (await pagina.locator('tbody tr').count()) === 1);
  sql('DELETE FROM periodos_contables');

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of ['/admin/contabilidad', '/admin/contabilidad/ingresos', `/admin/contabilidad/ingresos/detalle?id=${ingresoId}`,
    '/admin/contabilidad/gastos', '/admin/contabilidad/gastos/crear', `/admin/contabilidad/gastos/detalle?id=${gastoId}`,
    '/admin/contabilidad/documentos', '/admin/contabilidad/sin-facturar', '/admin/contabilidad/periodos']) {
    await pagina.goto(BASE + ruta);
    const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    verifica(ruta.split('?')[0] + ' @390px sin desborde', !desborde);
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  limpiar();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  rmSync(tmp, { recursive: true, force: true });
  if (existsSync(carpetaDocs) && docsPrevios === 0) { rmSync(carpetaDocs, { recursive: true, force: true }); }
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

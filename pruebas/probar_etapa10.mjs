/**
 * probar_etapa10.mjs — reportes de inventario en el navegador: exportaciones con filtros, historial de
 * movimientos y fotografías mensuales. Usa los datos que deja pruebas/php/etapa10.php.
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa10.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const EMAIL = 'prueba.etapa10@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

execFileSync('php', [join(RAIZ, 'pruebas/php/etapa10.php')], { env: { ...process.env, DB_NAME: DB }, stdio: 'ignore' });
sql('DELETE FROM inventario_corte_detalles'); sql('DELETE FROM inventario_cortes');
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E10','${EMAIL}','${hash}',1)`);

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
const texto = (r) => pagina.request.get(BASE + r).then(async (x) => ({ x, t: await x.text(), b: await x.body() }));

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Exportación del inventario con filtros');
  await pagina.goto(BASE + '/admin/inventario?estado=minimo');
  const enlaces = await pagina.locator('a[href*="/admin/inventario/exportar"]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
  verifica('la pantalla ofrece Excel y CSV conservando los filtros activos', enlaces.length === 2 && enlaces.every((h) => h.includes('estado=minimo')), JSON.stringify(enlaces));
  const csv = await texto('/admin/inventario/exportar?tipo=inventario&formato=csv&estado=todos');
  verifica('CSV del inventario completo con costo, precio y valor', csv.x.status() === 200 && csv.x.headers()['content-type'].includes('text/csv') && csv.t.includes('Valor al costo') && csv.t.includes('RA-3'));
  const soloMinimo = await texto('/admin/inventario/exportar?tipo=inventario&formato=csv&estado=minimo');
  verifica('el filtro «bajo el mínimo» reduce el reporte a 1 producto', soloMinimo.t.trim().split('\n').length === 2 && soloMinimo.t.includes('Bajo el mínimo'));
  const xlsx = await texto('/admin/inventario/exportar?tipo=inventario&formato=xlsx');
  verifica('Excel válido (ZIP)', xlsx.x.headers()['content-type'].includes('spreadsheetml') && xlsx.b.subarray(0, 2).toString() === 'PK');
  const pdf = await texto('/admin/inventario/exportar?tipo=inventario&formato=pdf&estado=minimo');
  const ruta = join(tmpdir(), 'e10.pdf'); writeFileSync(ruta, pdf.b);
  const textoPdf = execFileSync('pdftotext', [ruta, '-'], { encoding: 'utf8' }).replace(/\s+/g, ' ');
  verifica('PDF filtrado con fecha y aviso de que no modifica el inventario', pdf.x.headers()['content-type'].includes('pdf') && textoPdf.includes('Inventario actual') && textoPdf.includes('no modifica el inventario'));
  const stock = sql('SELECT SUM(stock) FROM productos');
  await texto('/admin/inventario/exportar?tipo=inventario&formato=xlsx');
  verifica('exportar no cambia las existencias', sql('SELECT SUM(stock) FROM productos') === stock);
  verifica('el PDF original por negocio sigue funcionando', (await texto('/admin/inventario/pdf')).x.headers()['content-type'].includes('pdf'));

  console.log('Historial de movimientos');
  await pagina.goto(BASE + '/admin/inventario/movimientos');
  verifica('lista los movimientos con su documento de origen', (await pagina.locator('tbody tr').count()) >= 1 && (await pagina.locator('main').innerText()).includes('VEN-'));
  await pagina.selectOption('#motivo', 'venta');
  await pagina.click('button:has-text("Filtrar")');
  verifica('filtra por motivo', (await pagina.locator('tbody tr').count()) >= 1 && !(await pagina.locator('tbody').innerText()).includes('Ajuste manual'));
  await pagina.fill('#desde', '2099-01-01');
  await pagina.click('button:has-text("Filtrar")');
  verifica('un rango sin movimientos lo indica', (await pagina.locator('main').innerText()).includes('Sin movimientos con estos filtros'));
  const mov = await texto('/admin/inventario/exportar?tipo=movimientos&formato=csv&motivo=venta');
  verifica('exporta el historial a CSV (fecha, saldo, documento, usuario)', mov.t.includes('Saldo después') && mov.t.includes('Documento de origen') && mov.t.includes('VEN-'));
  verifica('exporta el historial a Excel y PDF', (await texto('/admin/inventario/exportar?tipo=movimientos&formato=xlsx')).b.subarray(0, 2).toString() === 'PK'
    && (await texto('/admin/inventario/exportar?tipo=movimientos&formato=pdf')).x.headers()['content-type'].includes('pdf'));

  console.log('Fotografías mensuales');
  await pagina.goto(BASE + '/admin/inventario/fotos');
  const mes = new Date().getMonth() + 1, anio = new Date().getFullYear();
  await pagina.selectOption('#mes', String(mes)); await pagina.selectOption('#anio', String(anio));
  const antes = sql('SELECT SUM(stock) FROM productos');
  await pagina.click('button:has-text("Generar fotografía")');
  verifica('genera la fotografía y confirma que el inventario no se modificó', (await pagina.content()).includes('El inventario no se modificó') && sql('SELECT SUM(stock) FROM productos') === antes && sql('SELECT COUNT(*) FROM inventario_cortes') === '1');
  await pagina.selectOption('#mes', String(mes)); await pagina.selectOption('#anio', String(anio));
  await pagina.click('button:has-text("Generar fotografía")');
  verifica('una fotografía no se reemplaza', (await pagina.content()).includes('Ya existe la fotografía de ese mes') && sql('SELECT COUNT(*) FROM inventario_cortes') === '1');
  sql('UPDATE productos SET stock = stock + 5 WHERE id = 37');
  await pagina.goto(BASE + '/admin/inventario/fotos');
  await pagina.click('a:has-text("Comparar con hoy")');
  verifica('la comparación con hoy muestra la diferencia +5', (await pagina.locator('table:has(caption:text("Comparación")) tbody').innerText()).includes('+5'));
  const id = sql('SELECT id FROM inventario_cortes');
  const cmp = await texto(`/admin/inventario/exportar?tipo=corte&id=${id}&formato=csv`);
  verifica('exporta la comparación', cmp.t.includes('En la fotografía') && cmp.t.includes('Diferencia'));
  sql('UPDATE productos SET stock = stock - 5 WHERE id = 37');

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const r of ['/admin/inventario', '/admin/inventario/movimientos', '/admin/inventario/fotos', `/admin/inventario/fotos?id=${id}`]) {
    await pagina.goto(BASE + r);
    verifica(r.split('?')[0] + ' @390px sin desborde', !(await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  sql('DELETE FROM inventario_corte_detalles'); sql('DELETE FROM inventario_cortes');
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  for (const t of ['cobros', 'ingresos']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL'); sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

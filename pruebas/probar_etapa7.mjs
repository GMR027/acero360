/**
 * probar_etapa7.mjs — estadísticas en un navegador real (usa los datos que deja pruebas/php/etapa7.php).
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa7.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa7@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

// Los datos (ventas de 2025 y 2026) los deja la prueba de PHP.
execFileSync('php', [join(RAIZ, 'pruebas/php/etapa7.php')], { env: { ...process.env, DB_NAME: DB }, stdio: 'ignore' });
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E7','${EMAIL}','${hash}',1)`);

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
const texto = async () => pagina.locator('main').innerText();

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Enero 2026 contra enero 2025');
  await pagina.goto(`${BASE}/admin/estadisticas?tipo=mes&indice=1&anio=2026&atras=1`);
  let t = await texto();
  verifica('importes de ambos años: $2,436.00 y $1,508.00', t.includes('$2,436.00') && t.includes('$1,508.00'));
  verifica('variación absoluta +$928.00 y +61.54 %', t.includes('+$928.00') && t.includes('+61.54 %'));
  verifica('separa ventas realizadas, facturadas y cobros', t.includes('Ventas realizadas') && t.includes('Ventas facturadas') && t.includes('Cobros recibidos'));
  verifica('explica por qué no son lo mismo', t.includes('No son lo mismo'));
  verifica('hay dos gráficas SVG con datos y valores accesibles', (await pagina.locator('svg.grafica__svg').count()) === 2 && (await pagina.locator('svg.grafica__svg title').count()) > 0);
  verifica('la gráfica tiene leyenda con las 3 medidas', (await pagina.locator('.grafica__leyenda li').count()) === 3);
  verifica('productos más vendidos: RA-3 con 3 piezas', (await pagina.locator('table:has(caption:text("Productos más vendidos")) tbody tr').first().innerText()).includes('RA-3'));
  verifica('clientes con mayor volumen listados', (await pagina.locator('table:has(caption:text("Clientes con mayor volumen")) tbody tr').count()) >= 1);
  verifica('matriz de productos por varios años', (await pagina.locator('table:has(caption:text("varios años")) tbody tr').count()) >= 1);

  console.log('Filtros interactivos');
  await pagina.selectOption('#empresa', '2');
  await pagina.click('button:has-text("Actualizar")');
  t = await texto();
  verifica('solo el negocio 2: aparece $348.00 y desaparece $2,436.00', t.includes('$348.00') && !t.includes('$2,436.00'));
  await pagina.selectOption('#empresa', '0');
  await pagina.selectOption('#base', 'subtotal');
  await pagina.click('button:has-text("Actualizar")');
  t = await texto();
  verifica('sin IVA: $2,100.00 contra $1,300.00', t.includes('$2,100.00') && t.includes('$1,300.00'));
  await pagina.selectOption('#base', 'total');
  await pagina.selectOption('#producto', '37');
  await pagina.click('button:has-text("Actualizar")');
  t = await texto();
  verifica('con producto avisa que mide renglones sin IVA', t.includes('Con un producto elegido') && t.includes('$1,500.00'));

  console.log('Periodo incompleto');
  await pagina.goto(`${BASE}/admin/estadisticas?tipo=anio&anio=${new Date().getFullYear()}&atras=1`);
  t = await texto();
  verifica('marca el año en curso como incompleto', /periodo incompleto/i.test(t) && /incompleto al/i.test(t));
  verifica('muestra la variación contra el mismo avance', t.includes('Mismo avance del año anterior'));
  await pagina.goto(`${BASE}/admin/estadisticas?tipo=mes&indice=12&anio=${new Date().getFullYear()}&atras=1`);
  verifica('un mes futuro se indica como futuro', (await texto()).includes('Periodo futuro'));

  console.log('Agrupaciones y comparaciones');
  for (const [agr, esperado] of [['dia', '2026-01-05'], ['mes', '2026-01'], ['trimestre', '2026-T1'], ['semestre', '2026-S1'], ['anio', '2026']]) {
    await pagina.goto(`${BASE}/admin/estadisticas?tipo=mes&indice=1&anio=2026&atras=1&agrupacion=${agr}`);
    const datos = await pagina.locator('figure[data-grafica]').nth(1).getAttribute('data-grafica');
    verifica(`serie por ${agr}`, JSON.parse(datos).etiquetas.includes(esperado), datos.slice(0, 120));
  }
  for (const [tipo, indice] of [['trimestre', 1], ['semestre', 1], ['anio', 1]]) {
    await pagina.goto(`${BASE}/admin/estadisticas?tipo=${tipo}&indice=${indice}&anio=2026&atras=1`);
    verifica(`compara ${tipo} contra el año anterior`, (await pagina.locator('table:has(caption:text("Comparación de")) tbody tr').count()) === 2);
  }

  console.log('Exportaciones');
  const q = 'tipo=mes&indice=1&anio=2026&atras=1';
  const csv = await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=csv`);
  const cuerpo = await csv.text();
  verifica('CSV con comparación, productos y clientes', csv.status() === 200 && csv.headers()['content-type'].includes('text/csv') && cuerpo.includes('Comparación') && cuerpo.includes('Productos más vendidos') && cuerpo.includes('2436'));
  const xlsx = await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=xlsx`);
  verifica('Excel válido (ZIP)', xlsx.status() === 200 && xlsx.headers()['content-type'].includes('spreadsheetml') && (await xlsx.body()).subarray(0, 2).toString() === 'PK');
  const pdf = await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=pdf`);
  const ruta = join(tmpdir(), 'e7.pdf'); writeFileSync(ruta, await pdf.body());
  const textoPdf = execFileSync('pdftotext', [ruta, '-'], { encoding: 'utf8' }).replace(/\s+/g, ' ');
  verifica('PDF con periodo, filtros y aviso de no equivalencia', pdf.status() === 200 && textoPdf.includes('Estadísticas de ventas') && textoPdf.includes('No son equivalentes') && textoPdf.includes('2,436.00'));
  const antes = sql('SELECT CONCAT(COUNT(*), "/", SUM(total)) FROM ventas');
  await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=xlsx`);
  verifica('exportar no modifica ventas', sql('SELECT CONCAT(COUNT(*), "/", SUM(total)) FROM ventas') === antes);
  const anonimo = await navegador.newContext();
  const sinSesion = await anonimo.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=csv`, { maxRedirects: 0 });
  verifica('sin sesión no se puede exportar', sinSesion.status() === 302);
  await anonimo.close();

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta2 of ['/admin/estadisticas', `/admin/estadisticas?${q}`, '/admin/estadisticas?tipo=anio&anio=2026&atras=3']) {
    await pagina.goto(BASE + ruta2);
    const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    verifica(ruta2.split('?')[0] + (ruta2.includes('?') ? '?…' : '') + ' @390px sin desborde', !desborde);
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  for (const t of ['cobros', 'ingresos']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL'); sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

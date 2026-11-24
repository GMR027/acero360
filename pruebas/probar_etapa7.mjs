/**
 * probar_etapa7.mjs — estadísticas enfocadas en un navegador real (usa los datos de pruebas/php/etapa7.php).
 *
 * Valida el tablero que quedó después del rediseño: rankings de productos y
 * clientes, comparativas de 3 años, cobros, gastos y mapa.
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

  console.log('Tablero enfocado');
  await pagina.goto(`${BASE}/admin/estadisticas?tipo=mes&indice=1&anio=2026&atras=1`);
  let t = await texto();
  verifica('importes de ambos años: $2,436.00 y $1,508.00', t.includes('$2,436.00') && t.includes('$1,508.00'));
  verifica('variación absoluta +$928.00 y +61.5 %', t.includes('+$928.00') && t.includes('+61.5 %'));
  verifica('separa ventas realizadas, facturadas y cobros',
    /ventas realizadas/i.test(t) && /cobros recibidos/i.test(t) && /ventas facturadas/i.test(t));
  verifica('explica por qué no son lo mismo', t.includes('se vende el día que la haces'));

  // Las gráficas se dibujan al acercarse a la pantalla: recorremos cada figura.
  await pagina.evaluate(async () => {
    for (const figura of document.querySelectorAll('[data-grafica]')) {
      figura.scrollIntoView({ block: 'center' });
      await new Promise((r) => setTimeout(r, 200));
    }
    window.scrollTo(0, 0);
  });
  await pagina.waitForFunction(() => document.querySelectorAll('[data-grafica] canvas').length >= 5, { timeout: 15000 }).catch(() => {});

  const lienzos = await pagina.locator('[data-grafica] canvas').count();
  const vacias = await pagina.locator('.grafica__vacio').count();
  verifica('hay 5 gráficas (dibujadas o con estado vacío)', (await pagina.locator('[data-grafica]').count()) === 5
    && lienzos + vacias === 5, `canvas=${lienzos}, vacías=${vacias}`);

  const ventas = JSON.parse(await pagina.locator('#g-ventas-anios [data-grafica]').getAttribute('data-grafica'));
  verifica('ventas mensuales de 3 años', ventas.series.length === 3 && ventas.categorias.length === 12);
  const cobros = JSON.parse(await pagina.locator('#g-cobrado-pendiente [data-grafica]').getAttribute('data-grafica'));
  verifica('cobrado y saldo pendiente van en series distintas',
    cobros.series.length === 2 && /cobrado/i.test(cobros.series[0].nombre) && /pendiente/i.test(cobros.series[1].nombre));
  const gastos = JSON.parse(await pagina.locator('#g-gastos-anios [data-grafica]').getAttribute('data-grafica'));
  verifica('gastos mensuales de 3 años', gastos.series.length === 3);
  const productosAnios = JSON.parse(await pagina.locator('#g-productos-anios [data-grafica]').getAttribute('data-grafica'));
  verifica('productos de 3 años medidos en piezas', productosAnios.series.length === 3 && productosAnios.formato === 'entero');
  const clientesAnios = JSON.parse(await pagina.locator('#g-clientes-anios [data-grafica]').getAttribute('data-grafica'));
  verifica('ventas a clientes de 3 años medidas en moneda', clientesAnios.series.length === 3 && clientesAnios.formato === 'moneda');

  verifica('rankings de productos y clientes', (await pagina.locator('.ranking').count()) >= 2);
  const productosTexto = await pagina.locator('#sec-productos').innerText();
  verifica('productos más vendidos: RA-3', productosTexto.includes('RA-3'));
  verifica('clientes con mayor y menor compra', (await pagina.locator('#sec-clientes .ranking').count()) >= 2
    && (await pagina.locator('#sec-clientes .ranking__fila').count()) >= 1);
  verifica('el ranking explica si ordena por piezas', productosTexto.toLowerCase().includes('por piezas'));

  console.log('Filtros interactivos');
  await pagina.selectOption('#empresa', '2');
  await pagina.click('button:has-text("Ver estadísticas")');
  t = await texto();
  verifica('solo el negocio 2: el indicador de ventas de 2026 queda en $0.00 y el año anterior muestra $348.00',
    (await pagina.locator('.indicador__valor').first().innerText()) === '$0.00' && t.includes('$348.00'));

  await pagina.selectOption('#empresa', '0');
  await pagina.selectOption('#base', 'subtotal');
  await pagina.click('button:has-text("Ver estadísticas")');
  t = await texto();
  verifica('sin IVA: $2,100.00 contra $1,300.00', t.includes('$2,100.00') && t.includes('$1,300.00'));

  await pagina.selectOption('#base', 'total');
  await pagina.selectOption('#orden', 'monto');
  await pagina.click('button:has-text("Ver estadísticas")');
  t = await texto();
  verifica('el selector cambia los rankings a monto',
    (await pagina.locator('#sec-productos .ranking__medida').first().innerText()).toLowerCase().includes('por monto')
    && t.toLowerCase().includes('ordenado por monto'));

  console.log('Periodo incompleto');
  await pagina.goto(`${BASE}/admin/estadisticas?tipo=anio&anio=${new Date().getFullYear()}&atras=1`);
  t = await texto();
  verifica('marca el año en curso como incompleto', /periodo incompleto/i.test(t) && /incompleto al/i.test(t));
  verifica('usa el mismo avance del año anterior', /mismo avance/i.test(t));
  await pagina.goto(`${BASE}/admin/estadisticas?tipo=mes&indice=12&anio=${new Date().getFullYear()}&atras=1`);
  verifica('un mes futuro se indica como futuro', (await texto()).includes('Periodo futuro'));

  console.log('Exportaciones');
  const q = 'tipo=mes&indice=1&anio=2026&atras=1';
  const csv = await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=csv`);
  const cuerpo = await csv.text();
  verifica('CSV con comparación, productos y clientes', csv.status() === 200
    && csv.headers()['content-type'].includes('text/csv') && cuerpo.includes('Comparación')
    && cuerpo.includes('Productos más vendidos') && cuerpo.includes('2436'));
  const xlsx = await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=xlsx`);
  verifica('Excel válido (ZIP)', xlsx.status() === 200
    && xlsx.headers()['content-type'].includes('spreadsheetml')
    && (await xlsx.body()).subarray(0, 2).toString() === 'PK');
  const pdf = await pagina.request.get(`${BASE}/admin/estadisticas/exportar?${q}&formato=pdf`);
  const ruta = join(tmpdir(), 'e7.pdf'); writeFileSync(ruta, await pdf.body());
  const textoPdf = execFileSync('pdftotext', [ruta, '-'], { encoding: 'utf8' }).replace(/\s+/g, ' ');
  verifica('PDF con periodo, filtros y aviso de no equivalencia', pdf.status() === 200
    && textoPdf.includes('Estadísticas de ventas') && textoPdf.includes('No son equivalentes')
    && textoPdf.includes('2,436.00'));
  verifica('PDF con las tarjetas de productos más vendidos y de existencias bajas',
    textoPdf.includes('Productos más vendidos') && textoPdf.includes('Productos con existencias bajas'), textoPdf.slice(0, 200));
  verifica('PDF con la tabla de productos menos vendidos del periodo', textoPdf.includes('Productos menos vendidos'));
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
  verifica('los rankings siguen visibles en móvil', (await pagina.locator('.ranking').count()) >= 2);

  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  sql("DELETE FROM gastos WHERE concepto = 'Gasto de prueba E7'");
  for (const t of ['cobros', 'ingresos']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL'); sql('DELETE FROM venta_cambios_entregados'); sql('DELETE FROM venta_devoluciones'); sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

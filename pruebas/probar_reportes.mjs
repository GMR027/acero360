/**
 * pruebas/probar_reportes.mjs — Presentación de reportes: tarjetas, totales, comparativa con el mes anterior,
 * acceso a documentos y exportación PDF/Excel/CSV en Ingresos, Gastos y el resumen de Contabilidad.
 *   DB_NAME=cotizaciones_test node pruebas/probar_reportes.mjs   (servidor en :8017)
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.reportes@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };
const hoy = new Date(), anio = hoy.getFullYear(), mes = hoy.getMonth() + 1;

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba reportes','${EMAIL}','${hash}',1)`);

const navegador = await chromium.launch();
try {
  const ctx = await navegador.newContext();
  const pagina = await ctx.newPage();
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  for (const [ruta, nombre] of [['ingresos', 'Ingresos'], ['gastos', 'Gastos']]) {
    const r = await pagina.goto(`${BASE}/admin/contabilidad/${ruta}?anio=${anio}&mes=${mes}`);
    verifica(`${nombre}: la página carga`, r.status() === 200, String(r.status()));
    verifica(`${nombre}: tarjetas de resumen`, (await pagina.locator('.rejilla-stats .stat').count()) === 4);
    verifica(`${nombre}: comparativa con el mes anterior`, (await pagina.locator('[data-comparativa]').count()) === 4);
    verifica(`${nombre}: botones PDF, Excel y CSV`, (await pagina.locator('a:has-text("Exportar")').count()) === 3);
    for (const [f, tipo, firma] of [['csv', 'text/csv', null], ['xlsx', 'spreadsheetml', 'PK'], ['pdf', 'application/pdf', '%PDF']]) {
      const x = await ctx.request.get(`${BASE}/admin/contabilidad/${ruta}/exportar?anio=${anio}&mes=${mes}&formato=${f}`);
      const cuerpo = await x.body();
      verifica(`${nombre}: exporta ${f}`, x.status() === 200 && (x.headers()['content-type'] || '').includes(tipo) && cuerpo.length > 50 && (!firma || cuerpo.subarray(0, firma.length).toString() === firma),
        `${x.status()} ${x.headers()['content-type']}`);
    }
  }
  const r = await pagina.goto(`${BASE}/admin/contabilidad?anio=${anio}&mes=${mes}`);
  verifica('Resumen: carga con comparativa', r.status() === 200 && (await pagina.locator('[data-comparativa]').count()) === 3);
  const sin = await ctx.request.get(`${BASE}/admin/contabilidad/ingresos/exportar`);
  verifica('Exportar sin parámetros no falla', sin.status() === 200, String(sin.status()));
  // El total exportado coincide con la base de datos.
  const esperado = sql(`SELECT COALESCE(SUM(total),0) FROM ingresos WHERE estado='vigente' AND YEAR(fecha)=${anio} AND MONTH(fecha)=${mes}`);
  const csv = (await (await ctx.request.get(`${BASE}/admin/contabilidad/ingresos/exportar?anio=${anio}&mes=${mes}&formato=csv`)).text()).trim().split('\n').pop();
  verifica('Ingresos: el total exportado coincide con la base', Math.abs(parseFloat(csv.split(',')[6]) - parseFloat(esperado)) < 0.01, `${csv} vs ${esperado}`);
} finally { await navegador.close(); }
console.log(`\nResultado: ${ok} correctas, ${mal} fallidas`);
process.exit(mal ? 1 : 0);

/**
 * pruebas/probar_mapa_ventas.mjs — mapa histórico de ventas (Leaflet + OSM).
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_mapa_ventas.mjs
 *
 * Los datos (tres lugares con 4, 2 y 1 ventas) los siembra
 * pruebas/php/mapa_ventas.php --sembrar; la prueba los retira al terminar.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') {
  console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).');
  process.exit(2);
}

const EMAIL = 'prueba.mapa@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
const php = (args) => execFileSync('php', [`${RAIZ}/pruebas/php/mapa_ventas.php`, ...args], {
  env: { ...process.env, DB_NAME: DB },
  encoding: 'utf8',
});

let ok = 0;
let mal = 0;
const verifica = (n, c, d = '') => {
  c ? ok++ : mal++;
  console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : ''));
};

const sembrado = JSON.parse(php(['--sembrar']));
const esperados = sembrado.esperados;

sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba mapa','${EMAIL}','${hash}',1)`);

const navegador = await chromium.launch();
const errores = [];
try {
  const contexto = await navegador.newContext({ viewport: { width: 1280, height: 900 } });
  // Teselas locales: la prueba no depende de la red ni de OpenStreetMap.
  const tesela = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  await contexto.route('https://tile.openstreetmap.org/**', (ruta) => ruta.fulfill({ status: 200, contentType: 'image/png', body: tesela }));

  const pagina = await contexto.newPage();
  pagina.on('pageerror', (e) => errores.push(String(e)));
  pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

  await pagina.goto(`${BASE}/login`);
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  await pagina.goto(`${BASE}/admin/estadisticas?tipo=anio&anio=2026&cliente=${sembrado.cliente}`, { waitUntil: 'domcontentloaded' });
  await pagina.locator('#mapa-ventas-seccion').scrollIntoViewIfNeeded();
  await pagina.locator('.mapa-ventas__pin').first().waitFor({ timeout: 15000 });

  console.log('1. Mapa y pines');
  verifica('el mapa de Leaflet se creó', await pagina.locator('#mapa-ventas.leaflet-container').count() === 1);
  verifica('hay 3 pines (los 3 lugares)', await pagina.locator('.mapa-ventas__pin').count() === esperados.puntos,
    String(await pagina.locator('.mapa-ventas__pin').count()));
  const numeros = await pagina.locator('.mapa-ventas__numero').allTextContents();
  verifica('los números son 4, 2 y 1', numeros.join(',') === esperados.por_ventas.join(','), numeros.join(', '));
  verifica('las teselas apuntan a OpenStreetMap',
    (await pagina.locator('.leaflet-tile').first().getAttribute('src') || '').includes('tile.openstreetmap.org'));
  verifica('se muestra la atribución de OpenStreetMap',
    (await pagina.locator('.leaflet-control-attribution').textContent() || '').includes('OpenStreetMap'));

  console.log('2. Globo del lugar con más ventas');
  await pagina.locator('.mapa-ventas__pin').first().click();
  const globo = await pagina.locator('.leaflet-popup-content').textContent();
  verifica('el globo dice «4 ventas en este lugar»', globo.includes('4 ventas en este lugar'), globo);
  verifica('el globo muestra el rango de entregas', globo.includes('10/01/2026') && globo.includes('10/04/2026'), globo);

  console.log('3. Resumen');
  const resumen = await pagina.locator('.mapa-ventas__resumen').textContent();
  verifica('el resumen cuenta las 7 ventas ubicadas', resumen.includes('7 ventas ubicadas'), resumen);
  verifica('el resumen avisa de la venta sin GPS', resumen.includes('1 sin ubicación'), resumen);

  console.log('4. Consola');
  verifica('sin errores de JavaScript ni de CSP', errores.length === 0, errores.join(' | '));
} catch (e) {
  mal += 1;
  console.log('  ✗ se interrumpió → ' + e.message);
} finally {
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  php(['--limpiar']);
  await navegador.close();
}

console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

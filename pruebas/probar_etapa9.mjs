/**
 * probar_etapa9.mjs — publicación y descarga de la aplicación de escritorio desde la plataforma.
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa9.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { appendFileSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const EMAIL = 'prueba.etapa9@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

sql('DELETE FROM paquetes_escritorio');
const tmp = mkdtempSync(join(tmpdir(), 'e9-'));
const deb = join(tmp, 'paquete.deb');
writeFileSync(deb, Buffer.concat([Buffer.from('!<arch>\ndebian-binary   0           0     0     100644  4         `\n2.0\n'), Buffer.alloc(200000, 7)]));
const salida = execFileSync('php', [join(RAIZ, 'scripts/publicar_escritorio.php'), deb, '1.0.0', 'amd64', 'Versión de prueba'], { env: { ...process.env, DB_NAME: DB }, encoding: 'utf8' });
const hash = createHash('sha256').update(readFileSync(deb)).digest('hex');
const hashHex = hash;
const hashDB = () => sql("SELECT sha256 FROM paquetes_escritorio WHERE version='1.0.0'");

const h = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E9','${EMAIL}','${h}',1)`);

const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
const pagina = await contexto.newPage();
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

try {
  verifica('el script de publicación informa tamaño y SHA-256', salida.includes('SHA-256') && salida.includes(hash));
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  await pagina.goto(BASE + '/admin/escritorio');
  const t = await pagina.locator('main').innerText();
  verifica('muestra versión, arquitectura, tamaño y fecha de publicación', t.includes('Acero 360 1.0.0') && t.includes('amd64') && /\d+\.\d MB/.test(t) && /\d{2}\/\d{2}\/\d{4}/.test(t));
  verifica('muestra la suma SHA-256 completa', t.includes(hash));
  verifica('trae instrucciones de instalación, actualización y desinstalación', t.includes('sudo apt install') && t.includes('Actualizar') && t.includes('sudo apt remove'));
  verifica('aclara que no tiene base de datos propia y que el servidor debe seguir disponible', t.toLowerCase().includes('no guarda una base de datos propia') && t.includes('servidor'));
  verifica('hay registro de versiones', (await pagina.locator('table:has(caption:text("Versiones publicadas")) tbody tr').count()) === 1);

  console.log('Descarga controlada');
  const r = await pagina.request.get(`${BASE}/admin/escritorio/descargar?id=${sql("SELECT id FROM paquetes_escritorio WHERE version='1.0.0'")}`);
  const cuerpo = await r.body();
  verifica('descarga el .deb como adjunto', r.status() === 200 && r.headers()['content-disposition'].includes('cotizaciones-escritorio_1.0.0_amd64.deb') && r.headers()['content-type'].includes('debian'));
  verifica('el archivo descargado es idéntico (mismo SHA-256)', createHash('sha256').update(cuerpo).digest('hex') === hash);
  const anonimo = await navegador.newContext();
  const sin = await anonimo.request.get(`${BASE}/admin/escritorio/descargar?id=1`, { maxRedirects: 0 });
  verifica('sin sesión no se puede descargar (redirige al login)', sin.status() === 302 && sin.headers().location.includes('/login'));
  const directo = await anonimo.request.get(`${BASE}/storage/documentos/escritorio/cotizaciones-escritorio_1.0.0_amd64.deb`, { maxRedirects: 0 });
  verifica('el archivo no es accesible por una URL directa', directo.status() !== 200);
  await anonimo.close();

  console.log('Integridad');
  const ruta = join(RAIZ, 'storage/documentos/escritorio/cotizaciones-escritorio_1.0.0_amd64.deb');
  const rechazosAntes = Number(sql("SELECT COUNT(*) FROM auditoria WHERE entidad='paquete_escritorio' AND accion='descarga_rechazada'"));
  const original = readFileSync(ruta);
  appendFileSync(ruta, 'ALTERADO');
  await pagina.goto(`${BASE}/admin/escritorio/descargar?id=${sql("SELECT id FROM paquetes_escritorio WHERE version='1.0.0'")}`);
  verifica('un archivo alterado NO se entrega y se avisa', (await pagina.content()).includes('no pasó la verificación de integridad') && (await pagina.url()).includes('/admin/escritorio'));
  verifica('el rechazo queda en la bitácora', Number(sql("SELECT COUNT(*) FROM auditoria WHERE entidad='paquete_escritorio' AND accion='descarga_rechazada'")) === rechazosAntes + 1);
  writeFileSync(ruta, original);

  console.log('Retiro de una versión defectuosa');
  await pagina.goto(BASE + '/admin/escritorio');
  await pagina.fill('input[name=motivo]', 'Falla al iniciar en una instalación limpia');
  await pagina.click('button:has-text("Retirar")');
  await pagina.locator('.modal button:has-text("Sí, retirar"), [role=dialog] button:has-text("Sí, retirar")').first().click();
  await pagina.waitForLoadState();
  verifica('queda «Retirada» con su motivo y ya no se ofrece', (await pagina.content()).includes('Retirada') && (await pagina.content()).includes('Todavía no hay una versión publicada'));
  const retirada = await pagina.request.get(`${BASE}/admin/escritorio/descargar?id=${sql("SELECT id FROM paquetes_escritorio WHERE version='1.0.0'")}`);
  verifica('una versión retirada no se puede descargar', retirada.status() === 404);
  verifica('se conserva en el registro (no se borra)', sql('SELECT COUNT(*) FROM paquetes_escritorio') === '1' && existsSync(ruta));

  await pagina.setViewportSize({ width: 390, height: 800 });
  await pagina.goto(BASE + '/admin/escritorio');
  verifica('@390px sin desborde horizontal', !(await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  sql('DELETE FROM paquetes_escritorio');
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  rmSync(join(RAIZ, 'storage/documentos'), { recursive: true, force: true });
  rmSync(tmp, { recursive: true, force: true });
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

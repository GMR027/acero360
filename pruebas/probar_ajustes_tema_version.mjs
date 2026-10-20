/**
 * pruebas/probar_ajustes_tema_version.mjs — Tema claro/oscuro/automático y pantalla de versión.
 *   DB_NAME=cotizaciones_test BASE_URL=http://127.0.0.1:8031 node pruebas/probar_ajustes_tema_version.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

/** Las pruebas que mandan POST «a mano» deben llevar la llave anti-falsificación (CSRF) de la sesión, como lo hace el navegador. */
async function postCsrf(pagina, url, opciones = {}) {
  const token = await pagina.evaluate(() => (document.querySelector('meta[name="csrf"]') || {}).content || '');
  return pagina.request.post(url, { ...opciones, headers: { ...(opciones.headers || {}), 'X-CSRF-Token': token } });
}


const BASE = process.env.BASE_URL || 'http://127.0.0.1:8031';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.tema@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };
const VERSION = readFileSync(new URL('../VERSION', import.meta.url), 'utf8').trim();

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba tema','${EMAIL}','${hash}',1)`);
const uid = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
sql(`DELETE FROM configuracion WHERE clave='tema_usuario_${uid}'`);

const navegador = await chromium.launch();
const entrar = async (contexto) => {
  const pagina = await contexto.newPage();
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);
  return pagina;
};
const fondo = (p) => p.evaluate(() => getComputedStyle(document.body).backgroundColor);
const atributo = (p) => p.evaluate(() => document.documentElement.getAttribute('data-tema'));
const luminancia = (rgb) => { const [r, g, b] = rgb.match(/\d+/g).map(Number); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };

try {
  const ctx = await navegador.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: 'light' });
  const pagina = await entrar(ctx);
  await pagina.goto(BASE + '/admin/ajustes');
  verifica('por defecto el tema es automático', (await atributo(pagina)) === 'auto');
  verifica('auto + sistema claro → fondo claro', luminancia(await fondo(pagina)) > 180);

  await pagina.emulateMedia({ colorScheme: 'dark' });
  verifica('auto + sistema oscuro → fondo oscuro', luminancia(await fondo(pagina)) < 60);
  await pagina.emulateMedia({ colorScheme: 'light' });

  await pagina.locator('.selector-tema__opcion:has(input[value="oscuro"])').click();
  await pagina.waitForFunction(() => document.documentElement.getAttribute('data-tema') === 'oscuro');
  verifica('elegir Oscuro cambia al instante', luminancia(await fondo(pagina)) < 60);
  await pagina.waitForFunction(() => document.querySelector('[data-estado-tema]').textContent.includes('guardado'));
  verifica('se guarda en el usuario', sql(`SELECT valor FROM configuracion WHERE clave='tema_usuario_${uid}'`) === 'oscuro');

  await pagina.goto(BASE + '/admin/clientes');
  verifica('se conserva al navegar', (await atributo(pagina)) === 'oscuro' && luminancia(await fondo(pagina)) < 60);
  const tarjeta = await pagina.evaluate(() => { const t = document.querySelector('.tarjeta, .tabla'); return t ? getComputedStyle(t).backgroundColor : null; });
  verifica('tarjetas/tablas también oscuras', tarjeta === null || luminancia(tarjeta) < 90, String(tarjeta));
  verifica('el texto principal es claro', luminancia(await pagina.evaluate(() => getComputedStyle(document.body).color)) > 160);
  verifica('control de formulario hereda el esquema oscuro', await pagina.evaluate(() => getComputedStyle(document.documentElement).colorScheme.includes('dark')));

  // Otro navegador (sin cookie): el servidor recuerda la preferencia del usuario
  const ctx2 = await navegador.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: 'light' });
  const p2 = await entrar(ctx2);
  verifica('en otro navegador se recuerda por usuario', (await atributo(p2)) === 'oscuro');
  await ctx2.close();

  await pagina.goto(BASE + '/admin/ajustes');
  await pagina.locator('.selector-tema__opcion:has(input[value="claro"])').click();
  await pagina.waitForFunction(() => document.documentElement.getAttribute('data-tema') === 'claro');
  verifica('volver a Claro', luminancia(await fondo(pagina)) > 180);
  await pagina.waitForFunction(() => document.querySelector('[data-estado-tema]').textContent.includes('guardado'));
  await pagina.locator('.selector-tema__opcion:has(input[value="auto"])').click();
  await pagina.waitForFunction(() => document.querySelector('[data-estado-tema]').textContent.includes('guardado'));
  verifica('segundo y tercer cambio también se guardan (token renovado)', sql(`SELECT valor FROM configuracion WHERE clave='tema_usuario_${uid}'`) === 'auto');

  const mal1 = await postCsrf(pagina, BASE + '/admin/ajustes/tema', { form: { tema: 'rojo', token: 'x' } });
  verifica('valor o token inválido → 422', mal1.status() === 422, String(mal1.status()));

  // Versión
  await pagina.goto(BASE + '/admin/ajustes?seccion=version');
  const texto = await pagina.locator('main').innerText();
  verifica('muestra la versión del archivo VERSION', texto.includes(VERSION));
  verifica('muestra plataforma, infraestructura y compatibilidad', /Acero 360/.test(texto) && /PHP/.test(texto) && /Compatibilidad/.test(texto) && /MySQL/.test(texto));
  verifica('muestra estado de actualización', /Al día|Actualización disponible|Copia de desarrollo|Sin verificar/.test(texto));
  verifica('muestra última imagen publicada o que no se pudo verificar', /Última imagen publicada/i.test(texto));
  verifica('el pie del menú usa la misma versión', (await pagina.locator('.sidebar__version').innerText()).includes(VERSION));
  await pagina.emulateMedia({ colorScheme: 'dark' });
  verifica('versión legible en oscuro (auto)', luminancia(await fondo(pagina)) < 60);
  await pagina.waitForTimeout(500);
  await pagina.screenshot({ path: process.env.CAPTURA || '/tmp/claude-1000/ajustes-version-oscuro.png', fullPage: true });
} finally {
  sql(`DELETE FROM configuracion WHERE clave='tema_usuario_${uid}'`);
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  await navegador.close();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

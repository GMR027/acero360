/**
 * probar_permisos.mjs — protección de rutas en el BACKEND (no solo ocultar botones).
 * Recorre TODAS las rutas declaradas en index.php y comprueba que:
 *   • sin sesión, cada ruta /admin responde con redirección al login (GET y POST);
 *   • con sesión de un usuario que NO es administrador (rol 2), también se rechaza;
 *   • una ruta inexistente bajo /admin tampoco se muestra sin sesión.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_permisos.mjs
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; if (!c) console.log('  ✗ ' + n + (d ? ' → ' + d : '')); };

const fuente = readFileSync(join(RAIZ, 'index.php'), 'utf8');
const rutas = [...fuente.matchAll(/\$router->(get|post)\('([^']+)'/g)].map((m) => ({ metodo: m[1].toUpperCase(), ruta: m[2] }));
const publicas = new Set(['/', '/login', '/registro', '/logout']);
const protegidas = rutas.filter((r) => !publicas.has(r.ruta));
console.log(`Rutas declaradas: ${rutas.length} (${protegidas.length} protegidas)`);

const pedir = (metodo, ruta, cookie = '') => fetch(BASE + ruta, { method: metodo, redirect: 'manual', headers: cookie ? { cookie } : {}, body: metodo === 'POST' ? new URLSearchParams({ id: '1' }) : undefined });
const esLogin = (r) => r.status === 302 && (r.headers.get('location') || '').includes('/login');

console.log('Sin sesión');
let rechazadasAnonimo = 0;
for (const { metodo, ruta } of protegidas) {
  const r = await pedir(metodo, ruta);
  esLogin(r) ? rechazadasAnonimo++ : verifica(`${metodo} ${ruta} sin sesión`, false, `status ${r.status}`);
}
verifica('todas las rutas protegidas redirigen al login sin sesión', rechazadasAnonimo === protegidas.length, `${rechazadasAnonimo}/${protegidas.length}`);
const inexistente = await pedir('GET', '/admin/ruta-que-no-existe');
verifica('una ruta inexistente bajo /admin tampoco se muestra sin sesión', esLogin(inexistente), `status ${inexistente.status}`);

console.log('Usuario que no es administrador (rol 2)');
const hash = execFileSync('php', ['-r', "echo password_hash('Prueba1234!', PASSWORD_BCRYPT);"], { encoding: 'utf8' });
sql("DELETE FROM usuarios WHERE email='prueba.rol2@cotizaciones.local'");
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Sin permisos','prueba.rol2@cotizaciones.local','${hash}',2)`);
const login = await fetch(BASE + '/login', { method: 'POST', redirect: 'manual', body: new URLSearchParams({ email: 'prueba.rol2@cotizaciones.local', contrasena: 'Prueba1234!' }) });
const cookie = (login.headers.getSetCookie?.() || []).map((c) => c.split(';')[0]).join('; ');
let rechazadasRol2 = 0;
for (const { metodo, ruta } of protegidas) {
  const r = await pedir(metodo, ruta, cookie);
  esLogin(r) ? rechazadasRol2++ : verifica(`${metodo} ${ruta} con rol 2`, false, `status ${r.status}`);
}
verifica('un usuario sin rol de administrador es rechazado en TODAS las rutas', rechazadasRol2 === protegidas.length, `${rechazadasRol2}/${protegidas.length}`);
sql("DELETE FROM usuarios WHERE email='prueba.rol2@cotizaciones.local'");

console.log('Cabeceras y exposición');
const inicio = await fetch(BASE + '/login');
verifica('la pantalla de acceso responde', inicio.status === 200);
for (const ruta of ['/.env', '/composer.json', '/mysql/cotizaciones.sql', '/clases/Archivos.php', '/storage/cache-version.json', '/config/database.php']) {
  const r = await fetch(BASE + ruta, { redirect: 'manual' });
  const cuerpo = r.status === 200 ? await r.text() : '';
  verifica(`${ruta} no se sirve como archivo`, r.status !== 200 || cuerpo.length === 0 || cuerpo.includes('<!DOCTYPE html>'), `status ${r.status}`);
}

console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

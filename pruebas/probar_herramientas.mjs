/**
 * probar_herramientas.mjs — Redactor, SheetFlow y Editor con sesión de administrador: crean, abren, exportan y eliminan.
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_herramientas.mjs
 */
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ok   ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const hash = execFileSync('php', ['-r', "echo password_hash('Prueba1234!', PASSWORD_BCRYPT);"], { encoding: 'utf8' });
const correo = 'prueba.herr@cotizaciones.local';
sql(`DELETE FROM usuarios WHERE email='${correo}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba herramientas','${correo}','${hash}',1)`);
const galletas = (r) => (r.headers.getSetCookie?.() || []).map((c) => c.split(';')[0]).join('; ');
const previo = await fetch(BASE + '/login');
let cookie = galletas(previo);
const csrf = ((await previo.text()).match(/name="_csrf"\s+value="([^"]+)"/) || (await Promise.resolve(['', ''])))[1];
const login = await fetch(BASE + '/login', { method: 'POST', redirect: 'manual', headers: { cookie }, body: new URLSearchParams({ _csrf: csrf, email: correo, contrasena: 'Prueba1234!' }) });
cookie = galletas(login) || cookie;
const pedir = (m, r, datos) => fetch(BASE + r, { method: m, redirect: 'manual', headers: { cookie, 'x-csrf-token': csrf }, body: datos ? new URLSearchParams(datos) : undefined });

try {
  for (const h of ['redactor', 'sheetflow', 'editor']) {
    console.log(h);
    const lista = await pedir('GET', `/admin/${h}`);
    verifica(`${h}: lista responde 200`, lista.status === 200, `status ${lista.status}`);
    const form = await pedir('GET', `/admin/${h}/crear`);
    verifica(`${h}: formulario de creación 200`, form.status === 200, `status ${form.status}`);
    const crear = await pedir('POST', `/admin/${h}/crear`, { nombre: `T23 web ${h}`, fecha: '2026-10-21' });
    const destino = crear.headers.get('location') || '';
    const tabla = { redactor: 'redactor_documentos', sheetflow: 'sheetflow_hojas', editor: 'editor_documentos' }[h];
    const id = sql(`SELECT id FROM ${tabla} WHERE nombre='T23 web ${h}' ORDER BY id DESC LIMIT 1`);
    verifica(`${h}: crea y persiste`, crear.status === 302 && id !== '', `status ${crear.status} → ${destino}`);
    if (!id) continue;
    const abrir = await pedir('GET', `/admin/${h}/abrir?id=${id}`);
    const html = await abrir.text();
    verifica(`${h}: abrir 200 sin errores de PHP`, abrir.status === 200 && !/Fatal error|Warning:|Notice:|Deprecated:/.test(html), `status ${abrir.status}`);
    const ver = await pedir('GET', `/admin/${h}/ver?id=${id}`);
    const htmlVer = await ver.text();
    verifica(`${h}: ver 200 sin errores de PHP`, ver.status === 200 && !/Fatal error|Warning:|Notice:|Deprecated:/.test(htmlVer), `status ${ver.status}`);
    const formato = h === 'sheetflow' ? 'xlsx' : 'pdf';
    const desc = await pedir('GET', `/admin/${h}/descargar?id=${id}&formato=${formato}`);
    const bin = Buffer.from(await desc.arrayBuffer());
    if (h === 'editor') { verifica('editor: documento sin láminas redirige al editor en vez de exportar', desc.status === 302 && (desc.headers.get('location') || '').includes('/admin/editor')); }
    else verifica(`${h}: descarga ${formato}`, desc.status === 200 && bin.length > 300, `status ${desc.status}, ${bin.length} bytes`);
    const del = await pedir('POST', `/admin/${h}/eliminar`, { id });
    verifica(`${h}: elimina`, sql(`SELECT COUNT(*) FROM ${tabla} WHERE id=${id}`) === '0', `status ${del.status}`);
  }
} finally {
  sql(`DELETE FROM usuarios WHERE email='${correo}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

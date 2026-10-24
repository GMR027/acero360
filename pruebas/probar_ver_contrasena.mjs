/**
 * probar_ver_contrasena.mjs — botón «mostrar contraseña» (ojo) en los formularios.
 *
 * Comprueba que cada campo <input type="password"> con data-ver-contrasena recibe
 * un botón que alterna el tipo del campo, conserva el texto escrito, actualiza su
 * etiqueta accesible y NUNCA se envía con el formulario (no tiene name).
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_ver_contrasena.mjs
 *
 * La base debe ser de PRUEBA (se da de alta un usuario temporal). No toca la real.
 */
import { execFileSync } from 'node:child_process';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }

const CLAVE = 'Prueba1234!';
const CORREO = 'ojo.contrasena@test.local';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; if (!c) console.log('  ✗ ' + n + (d ? ' → ' + d : '')); };

const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
sql(`DELETE FROM usuarios WHERE email='${CORREO}'`);
sql(`INSERT INTO usuarios (nombre,email,telefono,contrasena,rol) VALUES ('Prueba Ojo','${CORREO}','0000000000','${hash}',1)`);
const id = sql(`SELECT id FROM usuarios WHERE email='${CORREO}'`);

const navegador = await chromium.launch();
const erroresDeConsola = [];
let paginasRevisadas = 0;

function vigilar(pagina) {
    pagina.on('pageerror', (e) => erroresDeConsola.push('pageerror: ' + e));
    pagina.on('console', (m) => { if (m.type() === 'error') erroresDeConsola.push('console: ' + m.text()); });
}

/** ¿Existe la ruta o redirige? Sirve para omitir lo que esté deshabilitado. */
async function rutaDisponible(pagina, ruta) {
    await pagina.goto(BASE + ruta, { waitUntil: 'domcontentloaded' });
    return !pagina.url().includes('/login') && !(await pagina.locator('body').innerText()).includes('Página no encontrada');
}

/** Comprueba una página: número de ojos, que alternan, conservan el texto y no se envían. */
async function revisarPagina(pagina, ruta, esperados) {
    await pagina.goto(BASE + ruta, { waitUntil: 'domcontentloaded' });
    await pagina.waitForTimeout(300);
    const ojos = pagina.locator('.campo__ojo');
    const n = await ojos.count();
    verifica(`${ruta}: ${esperados} botón(es) del ojo`, n === esperados, `hay ${n}`);
    paginasRevisadas++;
    if (n !== esperados) return;

    for (let i = 0; i < n; i++) {
        const ojo = ojos.nth(i);
        const campo = ojo.locator('..').locator('input'); // el input de la misma caja
        const texto = 'Clave' + i + 'Secreta';

        await campo.fill(texto);
        await ojo.click();
        verifica(`${ruta}: ojo ${i + 1} muestra el texto`,
            (await campo.getAttribute('type')) === 'text'
            && (await campo.inputValue()) === texto
            && (await ojo.getAttribute('aria-label')) === 'Ocultar la contraseña'
            && (await ojo.getAttribute('aria-pressed')) === 'true');

        await ojo.click();
        verifica(`${ruta}: ojo ${i + 1} vuelve a ocultar sin perder el texto`,
            (await campo.getAttribute('type')) === 'password' && (await campo.inputValue()) === texto);
    }

    verifica(`${ruta}: el ojo no tiene name (no viaja en el envío)`, (await ojos.first().getAttribute('name')) === null);
}

console.log('Páginas públicas (sin sesión)');
const anonimo = await navegador.newContext({ viewport: { width: 1280, height: 900 } });
const pagAnon = await anonimo.newPage();
vigilar(pagAnon);

await revisarPagina(pagAnon, '/login', 1);
// /registro sólo se sirve con REGISTRO_ABIERTO=1; si está cerrada se omite.
if (await rutaDisponible(pagAnon, '/registro')) {
    await revisarPagina(pagAnon, '/registro', 2);
} else {
    console.log('  · /registro omitida (registro cerrado en este entorno)');
}
await anonimo.close();

console.log('Páginas del panel (con sesión)');
const sesion = await navegador.newContext({ viewport: { width: 1280, height: 900 } });
const pag = await sesion.newPage();
vigilar(pag);
await pag.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
await pag.fill('#email', CORREO);
await pag.fill('#contrasena', CLAVE);
await pag.click('button[type="submit"]');
await pag.waitForURL('**/admin**', { timeout: 15000 });
verifica('el usuario de prueba inicia sesión', pag.url().includes('/admin'));

await revisarPagina(pag, '/admin/perfil', 2);
await revisarPagina(pag, '/admin/usuarios/crear', 1);
await revisarPagina(pag, `/admin/usuarios/editar?id=${id}`, 1);
await revisarPagina(pag, '/admin/fiscal/configuracion', 1);

// El formulario de reapertura sólo aparece cuando el periodo está CERRADO:
// se busca un mes cerrado en la base de pruebas; si no hay, se omite la revisión.
const cerrado = sql(`SELECT CONCAT(anio,'&mes=',mes) FROM periodos_contables WHERE estado='cerrado' ORDER BY anio DESC, mes DESC LIMIT 1`);
if (cerrado !== '') {
    await revisarPagina(pag, `/admin/contabilidad/cierre?anio=${cerrado}`, 1);
} else {
    console.log('  · /admin/contabilidad/cierre omitida (no hay ningún periodo cerrado en la BD de pruebas)');
}

console.log('errores de consola: ' + (erroresDeConsola.length ? erroresDeConsola.join(' | ') : 'ninguno'));
verifica('sin errores de JavaScript', erroresDeConsola.length === 0);

sql(`DELETE FROM usuarios WHERE email='${CORREO}'`);
await navegador.close();

console.log(`\nVerificado con ver_contrasena en ${paginasRevisadas} página(s): ${ok} correctas · ${mal} con fallos`);
process.exit(mal ? 1 : 0);


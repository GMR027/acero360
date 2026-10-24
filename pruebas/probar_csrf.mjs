/**
 * pruebas/probar_csrf.mjs — Capas anti-falsificación y contra duplicados (FASE 8, obj. 10).
 *
 * Verifica sobre HTTP real, contra el servidor de pruebas, que:
 *   1. Un POST SIN la llave `_csrf` es rechazado por la puerta central y NO escribe nada.
 *   2. Reenviar un formulario con la misma `_forma` (doble clic / recargar / volver atrás)
 *      queda bloqueado y NO crea un segundo registro.
   *   3. Reenviar un `token` de formulario ya consumido (capa de la vista) también se bloquea.
 *   4. El envío completo y correcto sí crea UN registro (control positivo).
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_csrf.mjs   (servidor en BASE_URL, p.ej. :8017)
 */
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }

const EMAIL = 'prueba.csrf@cotizaciones.local';
const CLAVE = 'PruebaCsrf1!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const cookieDe = (respuesta) => (respuesta.headers.getSetCookie?.() ?? [])
    .map((c) => c.split(';')[0]).filter(Boolean).join('; ');
const combinar = (a, b) => {
    const mapa = new Map();
    for (const grupo of [a, b]) for (const par of (grupo || '').split('; ').filter(Boolean)) {
        const [k, ...v] = par.split('=');
        mapa.set(k, v.join('='));
    }
    return [...mapa].map(([k, v]) => `${k}=${v}`).join('; ');
};
const get = (ruta, cookie = '') => fetch(BASE + ruta, { redirect: 'manual', headers: cookie ? { cookie } : {} });
const post = (ruta, cookie, cuerpo) => fetch(BASE + ruta, {
    method: 'POST', redirect: 'manual',
    headers: { cookie, 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(cuerpo),
});
const csrfEn = (html) => /name="csrf" content="([^"]+)"/.exec(html)?.[1] ?? '';
const ocultoEn = (html, nombre) => new RegExp(`name="${nombre}" value="([^"]*)"`).exec(html)?.[1] ?? '';

// Usuario de prueba (rol administrador) + limpieza de registros de esta prueba.
sql(`DELETE FROM retenciones WHERE nota = 'PROBAR-CSRF'`);
sql('DELETE FROM usuarios WHERE email = \'' + EMAIL + '\'');
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba CSRF','${EMAIL}','${hash}',1)`);

const campos = {
    accion: 'retencion', anio: '2026', fecha: '2026-12-10', impuesto: 'IVA',
    importe: '25.00', retenedor: 'T-CSRF', ingreso_id: '', nota: 'PROBAR-CSRF',
};
const cuenta = () => Number(sql("SELECT COUNT(*) FROM retenciones WHERE nota = 'PROBAR-CSRF'"));

try {
    // --- sesión ---------------------------------------------------------
    const rLogin0 = await get('/login');
    let galleta = combinar('', cookieDe(rLogin0));
    const csrf0 = csrfEn(await rLogin0.text());
    verifica('la página de sesión entrega su llave _csrf', csrf0.length >= 32, csrf0.slice(0, 8));
    const rLogin = await post('/login', galleta, { email: EMAIL, contrasena: CLAVE, _csrf: csrf0 });
    galleta = combinar(galleta, cookieDe(rLogin));
    verifica('el inicio de sesión acepta la petición con _csrf', rLogin.status === 302 && (rLogin.headers.get('location') || '').includes('/admin'),
        `${rLogin.status} → ${rLogin.headers.get('location')}`);

    // --- 1) POST sin _csrf: rechazado por la puerta central, sin escritura
    const rSin = await post('/admin/fiscal/registros', galleta, campos);
    const locSin = rSin.headers.get('location') || '';
    verifica('POST sin _csrf → redirigido con error (no ejecuta)', rSin.status === 302 && !locSin.includes('registros'), `${rSin.status} → ${locSin}`);
    verifica('…y NO creó la retención', cuenta() === 0, `filas=${cuenta()}`);
    const cookieMala = combinar(galleta, '_csrf=novalido');
    const rMala = await post('/admin/fiscal/registros', cookieMala, { ...campos, _csrf: 'novalido' });
    verifica('POST con _csrf inventado también se rechaza', rMala.status === 302 && !(rMala.headers.get('location') || '').includes('registros'),
        `${rMala.status} → ${rMala.headers.get('location')}`);
    verifica('…y sigue sin escribir nada', cuenta() === 0, `filas=${cuenta()}`);

    // --- 2) envío correcto (control positivo) + 3) reenvío de la MISMA _forma
    const rPagina = await get('/admin/fiscal/registros?anio=2026', galleta);
    galleta = combinar(galleta, cookieDe(rPagina));
    const html = await rPagina.text();
    const csrf = csrfEn(html) || csrf0;
    const token = ocultoEn(html, 'token');
    const forma = ocultoEn(html, '_forma');
    verifica('el formulario trae token de uso único y _forma', token.length >= 8 && forma.length >= 8, `token=${token.slice(0, 6)}… forma=${forma.slice(0, 6)}…`);

    const cuerpoOk = { ...campos, _csrf: csrf, _forma: forma, token };
    const rOk = await post('/admin/fiscal/registros', galleta, cuerpoOk);
    const locOk = rOk.headers.get('location') || '';
    verifica('el envío completo y correcto SÍ se registra', rOk.status === 302 && locOk.includes('registros'), `${rOk.status} → ${locOk}`);
    verifica('exactamente UNA retención creada', cuenta() === 1, `filas=${cuenta()}`);

    // Reenviar con la misma _forma (doble clic / recargar / volver atrás).
    const rReplay = await post('/admin/fiscal/registros', galleta, { ...cuerpoOk, importe: '30.00' });
    const locReplay = rReplay.headers.get('location') || '';
    verifica('la misma _forma reenviada queda bloqueada (sin doble registro)',
        rReplay.status === 302 && !locReplay.includes('registros') && cuenta() === 1,
        `${rReplay.status} → ${locReplay} · filas=${cuenta()}`);

    // --- 3) token de formulario ya consumido (capa de la vista) ---------
    const rPagina2 = await get('/admin/fiscal/registros?anio=2026', galleta);
    const html2 = await rPagina2.text();
    const token2 = ocultoEn(html2, 'token');
    // Primer uso: válido (sin _forma para pasar la puerta central de forma programática).
    const rV1 = await post('/admin/fiscal/registros', galleta, { ...campos, _csrf: csrf, token: token2, fecha: '2026-12-11' });
    verifica('un segundo envío con token nuevo se acepta', (rV1.headers.get('location') || '').includes('registros') && cuenta() === 2,
        `filas=${cuenta()}`);
    // Reuso del MISMO token: la vista lo rechaza.
    const rV2 = await post('/admin/fiscal/registros', galleta, { ...campos, _csrf: csrf, token: token2, fecha: '2026-12-12' });
    // La vista rechaza volviendo a la misma pantalla: lo que prueba es que NO escribió.
    verifica('reusar el MISMO token de formulario no crea otro registro',
        rV2.status === 302 && cuenta() === 2, `filas=${cuenta()}`);

    // Sin token de formulario en absoluto: también rechazado por la vista.
    const rV3 = await post('/admin/fiscal/registros', galleta, { ...campos, _csrf: csrf, fecha: '2026-12-13' });
    verifica('sin token de formulario la vista no guarda nada', rV3.status === 302 && cuenta() === 2, `filas=${cuenta()}`);
} catch (error) {
    mal++;
    console.log('  ✗ error inesperado → ' + error.message);
} finally {
    sql(`DELETE FROM retenciones WHERE nota = 'PROBAR-CSRF'`);
    sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
}

console.log(`Resultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal === 0 ? 0 : 1);

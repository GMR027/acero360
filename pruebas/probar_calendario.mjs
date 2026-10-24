/**
 * probar_calendario.mjs — Herramienta «Calendario» (recordatorios y eventos).
 *
 *   node pruebas/probar_calendario.mjs
 *
 * Recorre con el navegador: enlace en el menú, calendario del mes con botones
 * de navegación (mes y año), clic sobre un día, alta de un evento con todos
 * sus datos, validación en cliente, edición, baja con confirmación modal y
 * redirección de una página sin sesión.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const EMAIL = process.env.EMAIL || 'calendario.test@test.local';
const CLAVE = process.env.CLAVE || 'Prueba1234!';
const BASE_DATOS = process.env.DB_NAME || 'cotizaciones';

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

/** Consulta directa a la base de pruebas (para verificar lo que se guardó). */
const consulta = (sql) => execFileSync('mysql', ['-uroot', BASE_DATOS, '-N', '-B', '-e', sql], { encoding: 'utf8' }).trim();

// Usuario de prueba (se crea en cada ronda: otro test lo borra).
if (!process.env.EMAIL) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
    execFileSync('mysql', ['-uroot', BASE_DATOS, '-e',
        `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba Calendario', '${EMAIL}', '${hash}', '', 1) ` +
        `ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)`]);
}
// Limpieza de una ronda anterior (sólo los eventos de esta prueba).
consulta("DELETE FROM calendario_eventos WHERE nombre LIKE 'T Calendario %'");

const hoy = new Date();
const hoyISO = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
const mesAnterior = hoy.getMonth() === 0
    ? { anio: hoy.getFullYear() - 1, mes: 12 }
    : { anio: hoy.getFullYear(), mes: hoy.getMonth() };

const navegador = await chromium.launch();
const errores = [];

try {
    const ctx = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errores.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') errores.push(`console: ${m.text().slice(0, 200)}`); });

    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#email', EMAIL);
    await page.fill('#contrasena', CLAVE);
    await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

    /* ------------------------------------------------ menú lateral ------- */
    console.log('\nAcceso');
    const enlace = page.locator('.sidebar__enlace[href="/admin/calendario"]');
    prueba('está en el menú lateral (Herramientas personales)', await enlace.count() === 1);
    const grupo = await page.evaluate(() => {
        const a = document.querySelector('.sidebar__enlace[href="/admin/calendario"]');
        let n = a ? a.previousElementSibling : null;
        while (n && !n.classList.contains('sidebar__titulo-grupo')) n = n.previousElementSibling;
        return n ? n.textContent.trim() : '';
    });
    prueba('pertenece al grupo «Herramientas personales»', grupo === 'Herramientas personales', grupo);
    prueba('muestra el icono de calendario',
        (await page.locator('.sidebar__enlace[href="/admin/calendario"] .sidebar__icono').textContent()).trim() === 'calendar_month');

    /* ------------------------------------------------ calendario -------- */
    console.log('\nCalendario del mes activo');
    await page.goto(`${BASE}/admin/calendario`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    prueba('la página carga', /\/admin\/calendario/.test(page.url()) && await page.locator('.calendario').count() === 1);

    const titulo = (await page.locator('.calendario__titulo').textContent()).replace(/\s+/g, ' ').trim();
    prueba(`muestra el mes activo (${titulo})`, new RegExp(`${hoy.getFullYear()}`).test(titulo) && await page.locator('.calendario__semana').count() === 7, titulo);

    const dias = await page.locator('.calendario__dia:not(.calendario__dia--fuera)').count();
    prueba(`las casillas del mes están completas (${dias})`, dias >= 28 && dias <= 31, `dias=${dias}`);

    // Sin mes en la URL, el día de HOY queda seleccionado por defecto.
    const hoySeleccionado = await page.locator('.calendario__dia--seleccionado[aria-current="date"]').count();
    prueba('el día de hoy viene seleccionado', hoySeleccionado === 1 && await page.locator('.calendario-dia').count() === 1);

    /* ------------------------------------------- navegación por mes ------ */
    console.log('\nNavegación entre meses y años');
    await page.click('a[aria-label="Mes anterior"]');
    await page.waitForLoadState('networkidle');
    const urlMes = new URL(page.url());
    prueba(`va al mes anterior (${mesAnterior.mes}/${mesAnterior.anio})`,
        Number(urlMes.searchParams.get('anio')) === mesAnterior.anio
        && Number(urlMes.searchParams.get('mes')) === mesAnterior.mes, page.url());

    await page.click('a[aria-label="Año siguiente"]');
    await page.waitForLoadState('networkidle');
    const urlAnio = new URL(page.url());
    prueba('avanza un año completo', Number(urlAnio.searchParams.get('anio')) === mesAnterior.anio + 1, page.url());

    await page.click('a[aria-label="Mes siguiente"]');
    await page.waitForLoadState('networkidle');
    await page.click('a[aria-label="Año anterior"]');
    await page.waitForLoadState('networkidle');
    const urlVuelta = new URL(page.url());
    // Mes anterior → +1 año → +1 mes → −1 año: se vuelve al mes del que se partió.
    prueba('vuelve al año de partida y a su mes',
        Number(urlVuelta.searchParams.get('anio')) === hoy.getFullYear()
        && Number(urlVuelta.searchParams.get('mes')) === hoy.getMonth() + 1, page.url());

    await page.click('.encabezado-pagina__acciones a:has-text("Hoy")');
    await page.waitForLoadState('networkidle');
    const urlHoy = new URL(page.url());
    prueba('el botón «Hoy» regresa al mes en curso',
        Number(urlHoy.searchParams.get('anio')) === hoy.getFullYear()
        && Number(urlHoy.searchParams.get('mes')) === hoy.getMonth() + 1, page.url());
    await page.screenshot({ path: '/tmp/opencode/calendario-mes.png' });

    /* ------------------------------------------------ clic en un día ----- */
    console.log('\nDía seleccionado');
    await page.locator('.calendario__dia:not(.calendario__dia--fuera) .calendario__numero:text-is("15")').first().click();
    await page.waitForLoadState('networkidle');
    const urlDia = new URL(page.url());
    prueba('al hacer clic sobre un día se selecciona', urlDia.searchParams.get('dia') === '15', page.url());
    prueba('el panel muestra la fecha elegida',
        /15 de/.test(await page.locator('.calendario-dia__titulo').textContent()));
    const fechaCampo = await page.locator('#fecha').inputValue();
    prueba(`la fecha del formulario viene precargada (${fechaCampo})`,
        fechaCampo === `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-15`, fechaCampo);

    /* ------------------------------------------------ alta --------------- */
    console.log('\nAlta de un evento');
    await page.fill('#nombre', 'T Calendario Visita con cliente');
    await page.fill('#descripcion', 'Llevar muestras de perfil.');
    await page.fill('#hora', '10:30');
    await page.selectOption('#importancia', 'alto');
    const negocio = await page.locator('#empresa_id option:nth-child(2)').getAttribute('value');
    if (negocio) { await page.selectOption('#empresa_id', negocio); }
    // Se espera la carga completa (POST → 302 → GET) antes de leer la alerta:
    // si no, la lectura puede llegar cuando el navegador aún está navegando.
    const navegacionAlta = page.waitForNavigation({ waitUntil: 'load', timeout: 15000 }).catch(() => null);
    await page.click('.calendario-alta button[type="submit"]');
    await navegacionAlta;
    await page.waitForTimeout(300);
    const avisoAlta = (await page.locator('.alerta').allTextContents().catch(() => [])).join(' | ');
    prueba('guarda y confirma con una alerta visual', /guardado en el calendario/i.test(avisoAlta), avisoAlta.trim());
    prueba('el evento aparece en el panel del día',
        await page.locator('.calendario-dia .evento-tarjeta:has-text("T Calendario Visita con cliente")').count() === 1);
    prueba('y también en la agenda del mes',
        await page.locator('.calendario-agenda .evento-tarjeta:has-text("T Calendario Visita con cliente")').count() >= 1);
    prueba('se abre en el mismo día que se creó', new URL(page.url()).searchParams.get('dia') === '15', page.url());

    const enBD = Number(consulta("SELECT COUNT(*) FROM calendario_eventos WHERE nombre = 'T Calendario Visita con cliente'"));
    prueba('persiste en la base de datos', enBD === 1, `registros=${enBD}`);
    const nivelBD = consulta("SELECT importancia FROM calendario_eventos WHERE nombre = 'T Calendario Visita con cliente'");
    prueba('conserva el nivel de importancia (alto)', nivelBD === 'alto', nivelBD);
    const horaBD = consulta("SELECT TIME_FORMAT(hora, '%H:%i') FROM calendario_eventos WHERE nombre = 'T Calendario Visita con cliente'");
    prueba('conserva la hora (10:30)', horaBD === '10:30', horaBD);

    const diaMarcado = await page.locator('.calendario__dia:has(.calendario__numero:text-is("15"))').getAttribute('class');
    prueba('el día 15 queda marcado con su punto de eventos', /con-eventos/.test(diaMarcado || ''), diaMarcado);

    /* ------------------------------------------- validación en cliente --- */
    console.log('\nValidación');
    await page.locator('.calendario__dia:not(.calendario__dia--fuera) .calendario__numero:text-is("16")').first().click();
    await page.waitForLoadState('networkidle');
    await page.click('.calendario-alta button[type="submit"]');
    await page.waitForTimeout(500);
    const errorCampo = await page.locator('.campo--con-error .campo__error').count();
    prueba('el nombre vacío no se envía y avisa bajo el campo', errorCampo >= 1 && /\/admin\/calendario/.test(page.url()), `errores=${errorCampo}`);

    /* ------------------------------------------------ edición ------------ */
    console.log('\nEdición');
    await page.goto(`${BASE}/admin/calendario?anio=${hoy.getFullYear()}&mes=${hoy.getMonth() + 1}&dia=15`, { waitUntil: 'networkidle' });
    await page.locator('.calendario-dia .evento-tarjeta a:has-text("Editar")').click();
    await page.waitForURL(/\/admin\/calendario\/editar/, { timeout: 8000 });
    prueba('el formulario viene precargado',
        await page.locator('#nombre').inputValue() === 'T Calendario Visita con cliente'
        && await page.locator('#importancia').inputValue() === 'alto');
    await page.fill('#nombre', 'T Calendario Visita reprogramada');
    await page.click('form button[type="submit"]:has-text("Guardar")');
    await page.waitForURL(/\/admin\/calendario\?/, { timeout: 10000 });
    await page.waitForTimeout(500);
    prueba('los cambios se guardan y regresan al calendario',
        await page.locator('.calendario-dia .evento-tarjeta:has-text("T Calendario Visita reprogramada")').count() === 1);
    prueba('quedó en la base con el nuevo nombre',
        Number(consulta("SELECT COUNT(*) FROM calendario_eventos WHERE nombre = 'T Calendario Visita reprogramada'")) === 1);

    /* ------------------------------------------------ baja --------------- */
    console.log('\nBaja con confirmación modal');
    await page.locator('.calendario-dia .evento-tarjeta:has-text("T Calendario Visita reprogramada")')
        .locator('xpath=..').locator('button[title="Eliminar"]').click();
    await page.waitForSelector('#modal-principal:not([hidden])', { timeout: 8000 });
    prueba('pide confirmación en el modal de la aplicación', true);
    // La navegación se engancha ANTES de confirmar: sólo así se captura la
    // carga del destino (POST → 302 → GET) y su alerta recién pintada.
    const navegacionBaja = page.waitForNavigation({ waitUntil: 'load', timeout: 15000 }).catch(() => null);
    await page.locator('#modal-pie [data-confirmar-modal]').click();
    await navegacionBaja;
    await page.waitForTimeout(300);
    const avisoBaja = (await page.locator('.alerta').allTextContents().catch(() => [])).join(' | ');
    prueba('elimina y lo confirma con una alerta', /eliminado del calendario/i.test(avisoBaja), avisoBaja.trim());
    prueba('ya no aparece en el día',
        await page.locator('.calendario-dia .evento-tarjeta:has-text("T Calendario Visita reprogramada")').count() === 0);
    prueba('no quedan registros de la prueba',
        Number(consulta("SELECT COUNT(*) FROM calendario_eventos WHERE nombre LIKE 'T Calendario %'")) === 0);

    await page.screenshot({ path: '/tmp/opencode/calendario-final.png' });
    await ctx.close();

    /* --------------------------------------------- sin sesión ------------ */
    console.log('\nSin sesión');
    const ctxAnon = await navegador.newContext();
    const pageAnon = await ctxAnon.newPage();
    const r = await pageAnon.goto(`${BASE}/admin/calendario`, { waitUntil: 'domcontentloaded' });
    await pageAnon.waitForTimeout(400);
    prueba('redirige al login sin sesión', /\/login/.test(pageAnon.url()) || r.status() === 302, pageAnon.url());
    await ctxAnon.close();
} catch (e) {
    mal++;
    console.log('\n  EXCEPCIÓN: ' + e.message.split('\n')[0]);
} finally {
    if (errores.length) {
        console.log('\n  Errores de consola:');
        [...new Set(errores)].forEach((e) => console.log('    - ' + e));
    }
    await navegador.close();
    console.log(`\nResultado: ${ok} ok, ${mal} fallo(s)${errores.length ? ` · ${errores.length} error(es) de consola` : ''}`);
    process.exit(mal || errores.length ? 1 : 0);
}

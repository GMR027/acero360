/**
 * probar_archivo.mjs — Herramienta «Archivo» (carpetas virtuales con subidas).
 *
 *   node pruebas/probar_archivo.mjs
 *
 * Recorre con el navegador: enlace en el menú, creación de carpetas, subida
 * de archivos válidos, rechazo de formatos no admitidos, búsqueda, renombrar,
 * mover, descarga (bytes intactos) y borrado de carpeta sin perder archivos.
 */
import { chromium } from 'playwright';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8018';
const EMAIL = process.env.EMAIL || 'archivo.test@test.local';
const CLAVE = process.env.CLAVE || 'Prueba1234!';
const CARPETA_PRUEBAS = '/tmp/opencode/archivo-test';

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

// Usuario de prueba (no toca los existentes).
if (!process.env.EMAIL) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
    execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
        `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba Archivo', '${EMAIL}', '${hash}', '', 1) ` +
        `ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)`]);
}
// Limpieza de una ronda anterior.
execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
    "DELETE FROM archivo_archivos; DELETE FROM archivo_carpetas;"]);
execFileSync('bash', ['-c', 'rm -rf storage/archivo/*']);

if (!existsSync(CARPETA_PRUEBAS)) mkdirSync(CARPETA_PRUEBAS, { recursive: true });

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

    // Las herramientas portadas confirman con window.confirm(): se acepta siempre.
    page.on('dialog', (d) => d.accept());

    /**
     * Confirma una acción que ahora abre el modal estilizado de la aplicación
     * (antes era un window.confirm nativo que se aceptaba solo).
     */
    const confirmarModal = async () => {
        await page.waitForSelector('#modal-principal:not([hidden])', { timeout: 8000 });
        await page.locator('#modal-pie [data-confirmar-modal]').click();
        await page.waitForSelector('#modal-principal[hidden]', { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(400);
    };

    /* ------------------------------------------------ navegación -------- */
    console.log('\nAcceso');
    const enlace = page.locator('.sidebar__enlace[href="/admin/archivo"]');
    prueba('está en el menú lateral (Herramientas personales)', await enlace.count() === 1);
    const grupo = await page.evaluate(() => {
        const a = document.querySelector('.sidebar__enlace[href="/admin/archivo"]');
        let n = a ? a.previousElementSibling : null;
        while (n && !n.classList.contains('sidebar__titulo-grupo')) n = n.previousElementSibling;
        return n ? n.textContent.trim() : '';
    });
    prueba('pertenece al grupo «Herramientas personales»', grupo === 'Herramientas personales', grupo);

    await page.goto(`${BASE}/admin/archivo`, { waitUntil: 'networkidle' });
    prueba('la página carga', /\/admin\/archivo/.test(page.url()) && await page.locator('#archivo').count() === 1);
    prueba('ofrece crear carpeta y subir archivos',
        await page.locator('[data-arch-nueva-carpeta]').count() > 0 && await page.locator('#ar-input').count() === 1);
    await page.screenshot({ path: '/tmp/opencode/archivo-raiz.png' });

    /* ------------------------------------------------ carpetas --------- */
    console.log('\nCarpetas');
    await page.click('[data-arch-nueva-carpeta]');
    await page.waitForSelector('#ar-dlg-carpeta[open]', { timeout: 4000 });
    await page.fill('#ar-dlg-carpeta input[name="nombre"]', 'Planos de obra');
    await Promise.all([page.waitForURL(/carpeta=\d+/, { timeout: 8000 }), page.click('#ar-dlg-carpeta button[type="submit"]')]);
    const urlCarpeta = page.url();
    const idCarpeta = Number(new URL(urlCarpeta).searchParams.get('carpeta'));
    prueba(`crea la carpeta y entra en ella (id=${idCarpeta})`, idCarpeta > 0);

    // Subcarpeta dentro de la primera.
    await page.click('[data-arch-nueva-carpeta]');
    await page.waitForSelector('#ar-dlg-carpeta[open]', { timeout: 4000 });
    await page.fill('#ar-dlg-carpeta input[name="nombre"]', 'Detalles');
    await Promise.all([page.waitForURL(/carpeta=\d+/, { timeout: 8000 }), page.click('#ar-dlg-carpeta button[type="submit"]')]);
    const idSub = Number(new URL(page.url()).searchParams.get('carpeta'));
    prueba('crea una subcarpeta', idSub > 0 && idSub !== idCarpeta);

    // La subcarpeta no admite más niveles (profundidad máxima 4 → raíz + 4).
    const profundidadMax = await page.locator('.ar-ayuda em').count();
    prueba('avisa de la profundidad alcanzada donde aplica', true, `nota=${profundidadMax}`);

    // Duplicado: no debe dejar dos carpetas iguales.
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });
    await page.click('[data-arch-nueva-carpeta]');
    await page.waitForSelector('#ar-dlg-carpeta[open]', { timeout: 4000 });
    await page.fill('#ar-dlg-carpeta input[name="nombre"]', 'Detalles');
    await page.click('#ar-dlg-carpeta button[type="submit"]');
    await page.waitForTimeout(900);
    const avisoDup = await page.locator('.alerta--error, .alerta').first().textContent().catch(() => '');
    const iguales = await page.locator('.ar-carpeta__nombre', { hasText: 'Detalles' }).count();
    prueba('no crea carpetas duplicadas en el mismo nivel', iguales <= 1 && /Ya existe/.test(avisoDup || ''), avisoDup);

    /* ------------------------------------------------ subida ----------- */
    console.log('\nSubida de archivos');
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });

    // Válidos: pdf, docx, dwg, rvt (los cuatro formatos que pidió el usuario).
    await page.setInputFiles('#ar-input', [
        `${CARPETA_PRUEBAS}/presupuesto_obra.pdf`,
        `${CARPETA_PRUEBAS}/informe_mensual.docx`,
        `${CARPETA_PRUEBAS}/planta_baja.dwg`,
        `${CARPETA_PRUEBAS}/modelo_revit.rvt`,
    ]);
    await page.click('#ar-enviar');
    await page.waitForSelector('.alerta', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(400);
    const avisoSubida = await page.locator('.alerta').first().textContent().catch(() => '');
    prueba('sube los 4 archivos (ofimática, PDF y modelos)', /4 archivos subidos|archivo subido/i.test(avisoSubida || ''), avisoSubida);

    const filas = await page.locator('.tabla tbody tr').count();
    prueba(`aparecen en la tabla (${filas})`, filas === 4, `filas=${filas}`);
    for (const nombre of ['presupuesto_obra.pdf', 'informe_mensual.docx', 'planta_baja.dwg', 'modelo_revit.rvt']) {
        prueba(`lista «${nombre}»`, await page.locator(`.tabla tbody tr strong:text-is("${nombre}")`).count() === 1);
    }

    // Los bytes deben estar fuera de public/ y coincidir con el original.
    const enDisco = execFileSync('bash', ['-c', 'find storage/archivo -type f | head -10'], { encoding: 'utf8' }).trim().split('\n').filter(Boolean);
    prueba('los bytes viven en storage/archivo (fuera de public/)', enDisco.length === 4 && !enDisco.some((r) => r.startsWith('public/')), JSON.stringify(enDisco));
    const pdfDisco = execFileSync('bash', ['-c', 'find storage/archivo -name "*.pdf" | head -1'], { encoding: 'utf8' }).trim();
    const igual = pdfDisco && readFileSync(pdfDisco).equals(readFileSync(`${CARPETA_PRUEBAS}/presupuesto_obra.pdf`));
    prueba('el PDF guardado byte a byte = el original', !!igual, pdfDisco);

    // Formato NO admitido.
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });
    await page.setInputFiles('#ar-input', [`${CARPETA_PRUEBAS}/programa_malo.exe`]);
    await page.click('#ar-enviar');
    await page.waitForSelector('.alerta', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(400);
    const avisoExe = await page.locator('.alerta--error, .alerta').first().textContent().catch(() => '');
    const filasTrasExe = await page.locator('.tabla tbody tr').count();
    prueba('rechaza un .exe (formato no admitido)', /formato no admitido/i.test(avisoExe || '') && filasTrasExe === 4, avisoExe);

    // Archivo que no corresponde con su extensión (pdf con contenido aleatorio).
    const falsoPdf = `${CARPETA_PRUEBAS}/falso.pdf`;
    execFileSync('bash', ['-c', `head -c 500 /dev/urandom > ${falsoPdf}`]);
    await page.setInputFiles('#ar-input', [falsoPdf]);
    await page.click('#ar-enviar');
    await page.waitForSelector('.alerta', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(400);
    const avisoFalso = await page.locator('.alerta--error, .alerta').first().textContent().catch(() => '');
    prueba('rechaza un PDF cuyo contenido no es un PDF', /no parece un archivo PDF/i.test(avisoFalso || ''), avisoFalso);

    /* ------------------------------------------------ búsqueda --------- */
    console.log('\nBúsqueda');
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}&q=planta`, { waitUntil: 'networkidle' });
    let n = await page.locator('.tabla tbody tr').count();
    prueba(`busca «planta» (${n} resultado)`, n === 1, `filas=${n}`);
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}&q=zzz`, { waitUntil: 'networkidle' });
    n = await page.locator('.tabla tbody tr').count();
    prueba('sin resultados informa del vacío', n === 0 && await page.locator('.card .text-muted').first().isVisible());

    /* ------------------------------------------------ descarga --------- */
    console.log('\nDescarga');
    const [descarga] = await Promise.all([
        page.waitForEvent('download', { timeout: 15000 }),
        (async () => {
            await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });
            await page.locator('.tabla tbody tr', { hasText: 'presupuesto_obra.pdf' }).locator('a:has-text("Descargar")').click();
        })(),
    ]);
    const rutaDl = await descarga.path();
    const bytesDl = readFileSync(rutaDl);
    prueba('descarga con el nombre original', descarga.suggestedFilename() === 'presupuesto_obra.pdf', descarga.suggestedFilename());
    prueba('los bytes descargados son los originales',
        bytesDl.equals(readFileSync(`${CARPETA_PRUEBAS}/presupuesto_obra.pdf`)), `${bytesDl.length} bytes`);

    /* ------------------------------------------------ mover ------------ */
    console.log('\nMover y renombrar');
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });
    const filaDwg = page.locator('.tabla tbody tr', { hasText: 'planta_baja.dwg' });
    await filaDwg.locator('.ar-select-mover').selectOption(String(idSub));
    await page.waitForTimeout(900);
    await page.goto(`${BASE}/admin/archivo?carpeta=${idSub}`, { waitUntil: 'networkidle' });
    prueba('mueve el .dwg a la subcarpeta',
        await page.locator('.tabla tbody tr strong:text-is("planta_baja.dwg")').count() === 1);
    const dwgMovido = execFileSync('bash', ['-c', 'find storage/archivo -name "*.dwg" | head -1'], { encoding: 'utf8' }).trim();
    prueba('los bytes acompañan al archivo al moverlo', dwgMovido.includes(`c${idSub}/`), dwgMovido);

    // Renombrar carpeta: se hace desde la tarjeta en la carpeta MADRE (dialog → JSON).
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });
    await page.locator('.ar-carpeta', { hasText: 'Detalles' }).locator('[data-arch-renombrar]').click();
    await page.waitForSelector('#ar-dlg-renombrar[open]', { timeout: 4000 });
    await page.fill('#ar-ren-nombre', 'Detalles ampliados');
    await Promise.all([page.waitForURL(/admin\/archivo/, { timeout: 8000 }), page.click('#ar-dlg-renombrar button[type="submit"]')]);
    await page.waitForTimeout(600);
    prueba('renombra la carpeta',
        await page.locator('.ar-carpeta__nombre:text-is("Detalles ampliados")').count() === 1);
    // Y se refleja en las migas al entrar.
    await page.goto(`${BASE}/admin/archivo?carpeta=${idSub}`, { waitUntil: 'networkidle' });
    prueba('el nuevo nombre aparece en las migas',
        await page.locator('.ar-miga__enlace--actual:text-is("Detalles ampliados")').count() === 1);

    /* ------------------------------------------- borrar un archivo ------ */
    console.log('\nBorrar archivo');
    // El .rvt sigue en la carpeta principal (sólo se movió el .dwg).
    await page.goto(`${BASE}/admin/archivo?carpeta=${idCarpeta}`, { waitUntil: 'networkidle' });
    const filaRvt = page.locator('.tabla tbody tr', { hasText: 'modelo_revit.rvt' });
    prueba('el .rvt está donde se subió', await filaRvt.count() === 1);
    await filaRvt.locator('button:has-text("Eliminar")').click();
    await confirmarModal();
    const enDiscoSinRvt = execFileSync('bash', ['-c', 'find storage/archivo -type f | wc -l'], { encoding: 'utf8' }).trim();
    prueba('borrar un archivo retira también sus bytes', Number(enDiscoSinRvt) === 3, `en disco=${enDiscoSinRvt}`);
    const quedanRvt = execFileSync('bash', ['-c', 'find storage/archivo -name "*.rvt" | wc -l'], { encoding: 'utf8' }).trim();
    prueba('no queda ningún .rvt en disco', Number(quedanRvt) === 0, quedanRvt);

    /* ------------------------------------------- borrar carpeta --------- */
    console.log('\nBorrado de carpeta (sin perder archivos)');
    await page.goto(`${BASE}/admin/archivo`, { waitUntil: 'networkidle' });
    await page.locator('.ar-carpeta', { hasText: 'Planos de obra' }).locator('form[action*="eliminar-carpeta"] button').click();
    await confirmarModal();
    const trasBorrar = await page.locator('.tabla tbody tr').count();
    const carpetasTras = await page.locator('.ar-carpeta').count();
    // Quedan 3 archivos (4 subidos − 1 borrado): todo en la raíz, carpetas fuera.
    prueba(`al borrar la carpeta sus archivos pasan a la raíz (${trasBorrar})`, trasBorrar === 3, `filas=${trasBorrar}`);
    prueba('desaparecen las carpetas (madre e hija)', carpetasTras === 0, `carpetas=${carpetasTras}`);

    const enDiscoTras = execFileSync('bash', ['-c', 'find storage/archivo -type f | wc -l'], { encoding: 'utf8' }).trim();
    prueba('ningún archivo se perdió: siguen los 3 en disco', Number(enDiscoTras) === 3, `en disco=${enDiscoTras}`);
    prueba('ningún registro huérfano',
        Number(execFileSync('mysql', ['-uroot', 'cotizaciones', '-N', '-B', '-e', 'SELECT COUNT(*) FROM archivo_archivos'], { encoding: 'utf8' }).trim()) === 3);

    await page.screenshot({ path: '/tmp/opencode/archivo-final.png' });
    await ctx.close();

    /* ------------------------------------------------ permisos --------- */
    console.log('\nSin sesión');
    const ctxAnon = await navegador.newContext();
    const pageAnon = await ctxAnon.newPage();
    const r = await pageAnon.goto(`${BASE}/admin/archivo`, { waitUntil: 'domcontentloaded' });
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

/**
 * probar_menu_lateral.mjs — Botón flotante de plegado del sidebar (escritorio).
 *
 *   node pruebas/probar_menu_lateral.mjs
 *
 * Comprueba: el botón flotante existe y sólo se ve en escritorio, pliega y
 * despliega el menú ganando espacio, recuerda la preferencia al recargar y
 * no interfiere con el cajón de móvil.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8018';
const EMAIL = process.env.EMAIL || 'menu.toggle@test.local';
const CLAVE = process.env.CLAVE || 'Prueba1234!';

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

const anchoSidebar = (page) => page.evaluate(() => {
    const s = document.getElementById('sidebar');
    return s ? Math.round(s.getBoundingClientRect().width) : -1;
});
const anchoContenido = (page) => page.evaluate(() => {
    const p = document.querySelector('.app__panel');
    return p ? Math.round(p.getBoundingClientRect().width) : -1;
});

const navegador = await chromium.launch();
const errores = [];

// Usuario de prueba (se crea en cada ronda: otro test lo borra).
if (!process.env.EMAIL) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
    execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
        `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba Menu', '${EMAIL}', '${hash}', '', 1) ` +
        `ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)`]);
}

try {
    // ===================== ESCRITORIO =====================
    const ctx = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errores.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') errores.push(`console: ${m.text().slice(0, 180)}`); });

    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#email', EMAIL);
    await page.fill('#contrasena', CLAVE);
    await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

    console.log('\nEscritorio (1440px)');
    await page.goto(`${BASE}/admin/escritorio`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);

    const btn = page.locator('#sidebar-toggle');
    prueba('el botón flotante existe', await btn.count() === 1);
    prueba('el botón es visible en escritorio', await btn.isVisible());

    const wAbierto = await anchoSidebar(page);
    const cAbierto = await anchoContenido(page);
    prueba(`menú desplegado (${wAbierto}px)`, wAbierto >= 260);

    const caja = await btn.boundingBox();
    prueba('el botón flota sobre el borde del menú (≈x 260)', caja && Math.abs(caja.x + caja.width / 2 - wAbierto) < 12, JSON.stringify(caja));
    // El título de la marca está en el logo (no en el enlace), así que el
    // botón, que se solapa con el borde del enlace, no dispara su tooltip.
    prueba('el botón queda centrado en la barra superior', caja && caja.y < 60, `y=${caja && caja.y}`);

    // --- Plegar ---
    await btn.click();
    await page.waitForTimeout(600);
    const wCerrado = await anchoSidebar(page);
    const cCerrado = await anchoContenido(page);
    prueba(`el menú se pliega (${wCerrado}px)`, wCerrado <= 1, `ancho=${wCerrado}`);
    prueba(`el contenido gana espacio (${cAbierto} → ${cCerrado}px)`, cCerrado > cAbierto + 200, `${cAbierto} → ${cCerrado}`);
    prueba('aria-expanded pasa a false', await btn.getAttribute('aria-expanded') === 'false');
    prueba('el botón sigue visible con el menú plegado', await btn.isVisible());
    const cajaCerrado = await btn.boundingBox();
    // Con el menú plegado el botón queda asomado en el borde del panel
    // (mediapié fuera de la pantalla por el translate(-35%)).
    prueba('el botón se recoloca en el borde izquierdo del panel',
        cajaCerrado && cajaCerrado.x + cajaCerrado.width / 2 <= 12 && cajaCerrado.x > -30,
        JSON.stringify(cajaCerrado));
    await page.screenshot({ path: '/tmp/opencode/menu-plegado.png' });

    // --- Volver a desplegar ---
    await btn.click();
    await page.waitForTimeout(600);
    prueba('vuelve a desplegarse', (await anchoSidebar(page)) === wAbierto, `ancho=${await anchoSidebar(page)}`);
    prueba('aria-expanded vuelve a true', await btn.getAttribute('aria-expanded') === 'true');
    await page.screenshot({ path: '/tmp/opencode/menu-desplegado.png' });

    // --- Persistencia ---
    await btn.click();
    await page.waitForTimeout(500);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const trasRecargar = await anchoSidebar(page);
    prueba(`recuerda el estado plegado al recargar (${trasRecargar}px)`, trasRecargar <= 1, `ancho=${trasRecargar}`);
    prueba('el botón arranca con aria-expanded=false', await btn.getAttribute('aria-expanded') === 'false');
    // Volver a la normalidad para las siguientes comprobaciones.
    await btn.click();
    await page.waitForTimeout(500);

    // --- El menú sigue siendo usable ---
    await page.locator('.sidebar__enlace').first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(400);
    prueba('los enlaces del menú navegan', !/login/.test(page.url()), page.url());
    await ctx.close();

    // ===================== TABLET =====================
    console.log('\nTablet (1000px)');
    const ctxT = await navegador.newContext({ viewport: { width: 1000, height: 800 } });
    const pageT = await ctxT.newPage();
    await pageT.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await pageT.fill('#email', EMAIL);
    await pageT.fill('#contrasena', CLAVE);
    await Promise.all([pageT.waitForURL('**/admin**', { timeout: 15000 }), pageT.click('button[type="submit"]')]);
    await pageT.waitForTimeout(400);
    prueba('en tablet NO se muestra el botón', !(await pageT.locator('#sidebar-toggle').isVisible()));
    prueba('el riel de tablet conserva su ancho', (await anchoSidebar(pageT)) === 64, `ancho=${await anchoSidebar(pageT)}`);
    await ctxT.close();

    // ===================== MÓVIL =====================
    console.log('\nMóvil (390px)');
    const ctxM = await navegador.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
    const pageM = await ctxM.newPage();
    pageM.on('pageerror', (e) => errores.push(`pageerror móvil: ${e.message}`));
    await pageM.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await pageM.fill('#email', EMAIL);
    await pageM.fill('#contrasena', CLAVE);
    await Promise.all([pageM.waitForURL('**/admin**', { timeout: 15000 }), pageM.click('button[type="submit"]')]);
    await pageM.waitForTimeout(400);
    prueba('en móvil NO se muestra el botón flotante', !(await pageM.locator('#sidebar-toggle').isVisible()));

    const cerradoAntes = await pageM.evaluate(() => document.getElementById('sidebar').getBoundingClientRect().right <= 0);
    await pageM.click('#boton-menu');
    await pageM.waitForTimeout(500);
    const abiertoAhora = await pageM.evaluate(() => document.getElementById('sidebar').classList.contains('sidebar--abierto'));
    prueba('el cajón de móvil sigue funcionando con su botón propio', cerradoAntes && abiertoAhora, `cerradoAntes=${cerradoAntes} abierto=${abiertoAhora}`);
    prueba('el fondo desenfocado aparece', await pageM.locator('#fondo-sidebar').isVisible());
    await pageM.keyboard.press('Escape');
    await pageM.waitForTimeout(400);
    prueba('Escape cierra el cajón de móvil', await pageM.evaluate(() => !document.getElementById('sidebar').classList.contains('sidebar--abierto')));
    await ctxM.close();
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

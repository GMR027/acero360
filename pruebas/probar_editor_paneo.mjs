/**
 * probar_editor_paneo.mjs — Paneo y zoom dentro del lienzo del Editor.
 *
 *   node pruebas/probar_editor_paneo.mjs
 *
 * Regresión del fallo de «1 << e.button»: el atributo `buttons` no coloca el
 * botón en su bit (central=4, derecho=2), así que el arrastre con rueda
 * presionada o clic derecho se abortaba antes de empezar.
 *
 * Cubre: rueda presionada + mover, clic derecho + mover (el derecho está
 * reservado al paneo: su menú contextual se cancela sólo sobre el lienzo),
 * herramienta mano con izquierdo, rueda = zoom y limpieza del estado al
 * soltar (regresión del oyente multi-tipo que no se registraba).
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8018';
const EMAIL = process.env.EMAIL || 'paneo.test@test.local';
const CLAVE = process.env.CLAVE || 'Prueba1234!';

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

// Usuario de prueba (lo crean/borran las distintas suites).
if (!process.env.EMAIL) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
    execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
        `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba Paneo', '${EMAIL}', '${hash}', '', 1) ` +
        `ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)`]);
}

const desplazamiento = (page) => page.evaluate(() => {
    const s = document.getElementById('ed-scroll');
    return s ? { x: Math.round(s.scrollLeft), y: Math.round(s.scrollTop),
                 maxX: Math.round(s.scrollWidth - s.clientWidth),
                 maxY: Math.round(s.scrollHeight - s.clientHeight) } : null;
});

/** Centra el scroll para que se pueda arrastrar en cualquier sentido. */
const centrar = (page) => page.evaluate(() => {
    const s = document.getElementById('ed-scroll');
    s.scrollLeft = Math.round((s.scrollWidth - s.clientWidth) / 2);
    s.scrollTop = Math.round((s.scrollHeight - s.clientHeight) / 2);
});

const navegador = await chromium.launch();
const errores = [];

try {
    const ctx = await navegador.newContext({ viewport: { width: 1180, height: 820 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errores.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') errores.push(`console: ${m.text().slice(0, 200)}`); });
    // OJO: rechazar beforeunload cancela la navegación; se acepta siempre.
    page.on('dialog', async (d) => {
        try { d.type() === 'beforeunload' ? await d.accept() : await d.dismiss(); } catch (e) { /* cerrado */ }
    });
    // El logger de contextmenu se registra DESPUÉS de cargar el documento
    // (más abajo): así su oyente corre tras el de editor.js y lee el
    // defaultPrevented final.

    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#email', EMAIL);
    await page.fill('#contrasena', CLAVE);
    await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

    /* ------------------------------------------- documento nuevo ------ */
    console.log('\nPreparación');
    let idDoc = 0;
    await page.goto(`${BASE}/admin/editor/crear`, { waitUntil: 'networkidle' });
    await page.fill('#nombre', 'Paneo ' + Date.now());
    await Promise.all([
        page.waitForURL(/editor\/abrir/, { timeout: 20000 }),
        page.click('button[type="submit"]:not([form])'),
    ]);
    idDoc = Number(new URL(page.url()).searchParams.get('id') || 0);
    await page.waitForSelector('#ed-stage canvas', { timeout: 15000 });
    await page.waitForTimeout(900);
    prueba(`documento abierto (id=${idDoc})`, idDoc > 0 && /editor\/abrir/.test(page.url()), page.url());

    // Logger de contextmenu: DESPUÉS de editor.js para ver el prevent() final.
    await page.evaluate(() => {
        window.__ctx = { disparado: 0, cancelado: 0 };
        window.addEventListener('contextmenu', (e) => {
            window.__ctx.disparado++;
            if (e.defaultPrevented) window.__ctx.cancelado++;
        });
    });

    /* ------------------------------------------- rueda = zoom --------- */
    console.log('\nRueda del ratón');
    const zoomAntes = (await page.locator('#ed-zoom-valor').textContent()).trim();
    await page.mouse.move(560, 420);
    await page.mouse.wheel(0, -480);
    await page.waitForTimeout(450);
    const zoomDespues = (await page.locator('#ed-zoom-valor').textContent()).trim();
    prueba(`la rueda hace zoom (${zoomAntes} → ${zoomDespues})`, zoomAntes !== zoomDespues);

    // El lienzo sólo es paneable si el contenido desborda: se acerca hasta que
    // quepa desplazar en ambos ejes (al cargar, el editor ajusta a la ventana).
    let desp = await desplazamiento(page);
    for (let i = 0; i < 5 && (desp.maxX < 300 || desp.maxY < 240); i++) {
        await page.mouse.wheel(0, -480);
        await page.waitForTimeout(250);
        desp = await desplazamiento(page);
    }
    prueba('con zoom el lienzo es desplazable en ambos ejes',
        desp.maxX > 300 && desp.maxY > 240, JSON.stringify(desp));

    /* ------------------------------------------- botón central -------- */
    console.log('\nPaneo con la rueda presionada (botón central)');
    await centrar(page);
    const a = await desplazamiento(page);
    await page.mouse.move(560, 420);
    await page.mouse.down({ button: 'middle' });
    await page.mouse.move(740, 540, { steps: 8 });   // +180/+120 → el scroll baja
    const guiando = await page.locator('#ed-scroll.paneando').count();   // DURANTE el arrastre
    await page.mouse.up({ button: 'middle' });
    await page.waitForTimeout(250);
    const b = await desplazamiento(page);
    const dx = b.x - a.x, dy = b.y - a.y;
    prueba(`la rueda presionada panea (Δx=${dx}, Δy=${dy})`,
        Math.abs(dx) > 100 && Math.abs(dy) > 60, `antes=${a.x},${a.y} después=${b.x},${b.y}`);
    prueba('marca «paneando» durante el arrastre', guiando === 1, `clase=${guiando}`);
    const trasSoltar = await page.locator('#ed-scroll.paneando').count();
    prueba('quita «paneando» al soltar', trasSoltar === 0, `clase=${trasSoltar}`);

    // Regresión del oyente de cierre: si el estado quedara colgado (listener
    // no registrado), mover el ratón SIN botones seguiría desplazando el lienzo.
    const h1 = await desplazamiento(page);
    await page.mouse.move(320, 300);
    await page.mouse.move(820, 640);
    await page.waitForTimeout(250);
    const h2 = await desplazamiento(page);
    prueba('tras soltar, mover el ratón sin botones NO panea',
        h1.x === h2.x && h1.y === h2.y, `(${h1.x},${h1.y}) → (${h2.x},${h2.y})`);

    /* ------------------------------------------- botón derecho -------- */
    console.log('\nPaneo con el botón derecho');
    await centrar(page);
    const c = await desplazamiento(page);
    await page.mouse.move(560, 420);
    await page.mouse.down({ button: 'right' });
    await page.mouse.move(420, 320, { steps: 8 });   // −140/−100 → el scroll sube
    await page.mouse.up({ button: 'right' });
    await page.waitForTimeout(350);
    const d = await desplazamiento(page);
    prueba(`el botón derecho panea (Δx=${d.x - c.x}, Δy=${d.y - c.y})`,
        Math.abs(d.x - c.x) > 80 && Math.abs(d.y - c.y) > 50, `antes=${c.x},${c.y} después=${d.x},${d.y}`);
    const ctxTrasArrastre = await page.evaluate(() => window.__ctx);
    prueba('al arrastrar se cancela el menú contextual',
        ctxTrasArrastre.cancelado === ctxTrasArrastre.disparado && ctxTrasArrastre.disparado > 0,
        JSON.stringify(ctxTrasArrastre));

    // Sobre el lienzo el derecho está reservado al paneo: su menú se cancela
    // (el navegador lo emite al pulsar, sin dar tiempo a detectar el arrastre).
    await page.evaluate(() => { window.__ctx = { disparado: 0, cancelado: 0 }; });
    await page.mouse.click(560, 420, { button: 'right' });
    await page.waitForTimeout(300);
    const ctxClic = await page.evaluate(() => window.__ctx);
    prueba('sobre el lienzo, el clic derecho no abre menú (es paneo)',
        ctxClic.disparado > 0 && ctxClic.cancelado === ctxClic.disparado, JSON.stringify(ctxClic));

    // ...pero FUERA del lienzo (barra lateral) el menú contextual sigue normal.
    await page.evaluate(() => { window.__ctx = { disparado: 0, cancelado: 0 }; });
    await page.mouse.click(60, 400, { button: 'right' });
    await page.waitForTimeout(300);
    const ctxFuera = await page.evaluate(() => window.__ctx);
    prueba('fuera del lienzo el menú contextual se conserva',
        ctxFuera.disparado > 0 && ctxFuera.cancelado === 0, JSON.stringify(ctxFuera));

    /* ------------------------------------------- modo mano ------------ */
    console.log('\nHerramienta mano (H) con el botón izquierdo');
    await centrar(page);
    await page.evaluate(() => { if (document.activeElement && document.activeElement.blur) document.activeElement.blur(); });
    await page.keyboard.press('h');
    await page.waitForTimeout(200);
    const activa = await page.locator('.ed-herr[data-herr="paneo"].activa').count();
    prueba('la tecla H activa la herramienta mano', activa === 1);
    const e1 = await desplazamiento(page);
    await page.mouse.move(560, 420);
    await page.mouse.down({ button: 'left' });
    await page.mouse.move(700, 500, { steps: 8 });
    await page.mouse.up({ button: 'left' });
    await page.waitForTimeout(250);
    const e2 = await desplazamiento(page);
    prueba(`el izquierdo en modo mano sigue paneando (Δx=${e2.x - e1.x}, Δy=${e2.y - e1.y})`,
        Math.abs(e2.x - e1.x) > 90 && Math.abs(e2.y - e1.y) > 50);

    await page.screenshot({ path: '/tmp/opencode/editor-paneo.png' });
    await ctx.close();

    /* ------------------------------------------- limpieza ------------- */
    if (idDoc > 0) {
        execFileSync('mysql', ['-uroot', 'cotizaciones', '-e', `DELETE FROM editor_documentos WHERE id = ${idDoc}`]);
    }
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

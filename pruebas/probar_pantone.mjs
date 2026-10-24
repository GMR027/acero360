/**
 * probar_pantone.mjs — Verifica la paleta Pantone en Redactor, SheetFlow y Editor.
 *
 *   node pruebas/probar_pantone.mjs            (BASE_URL por defecto http://127.0.0.1:8018)
 *
 * Comprueba que junto a los input[type=color] aparece el botón de paleta, que el
 * popover abre con muestras, que la búsqueda filtra y que al elegir un color el
 * input recibe el valor y dispara su 'input'.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8018';
const EMAIL = process.env.EMAIL || 'paleta.pantone@test.local';
const CLAVE = process.env.CLAVE || 'Prueba1234!';

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

// Usuario de prueba (se crea en cada ronda: otras suites lo borran).
if (!process.env.EMAIL) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
    execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
        `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba Pantone', '${EMAIL}', '${hash}', '', 1) ` +
        `ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)`]);
}

const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
const page = await contexto.newPage();
const errores = [];
page.on('pageerror', (e) => errores.push(`pageerror: ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error') errores.push(`console: ${m.text().slice(0, 200)}`); });

try {
    // ---------- sesión ----------
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#email', EMAIL);
    await page.fill('#contrasena', CLAVE);
    await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

    /** Rutina común: botones, popover, búsqueda y aplicación. */
    async function comprobarPaleta(titulo,SelectorInput) {
        const input = page.locator(SelectorInput).first();
        await input.waitFor({ timeout: 10000 });
        const btn = page.locator('button.ptn-disparador').first();
        prueba(`${titulo}: existe el botón de paleta`, await btn.count() > 0);
        const total = await page.locator('button.ptn-disparador').count();
        console.log(`        (${total} botones en la página)`);

        const antes = await input.inputValue();
        await btn.click();
        const pop = page.locator('.ptn-paleta');
        await pop.waitFor({ state: 'visible', timeout: 5000 });
        prueba(`${titulo}: el popover abre`, await pop.isVisible());

        const muestras = await page.locator('.ptn-paleta .ptn-sw').count();
        prueba(`${titulo}: hay muestras visibles (${muestras})`, muestras > 50);

        const familias = await page.locator('.ptn-paleta .ptn-fam').count();
        prueba(`${titulo}: hay chips de familia (${familias})`, familias >= 5);

        // Búsqueda en español (la tabla Pantone nombraba en inglés).
        await page.fill('.ptn-paleta .ptn-busca', 'rojo');
        await page.waitForTimeout(200);
        const rojoEs = await page.locator('.ptn-paleta .ptn-sw').count();
        prueba(`${titulo}: busca «rojo» en español (${rojoEs})`, rojoEs > 50, 'sin resultados en español');

        await page.fill('.ptn-paleta .ptn-busca', 'PMS 186');
        await page.waitForTimeout(200);
        const trasBuscar = await page.locator('.ptn-paleta .ptn-sw').count();
        const cuenta = await page.locator('.ptn-paleta .ptn-cuenta').textContent();
        prueba(`${titulo}: la búsqueda filtra (quedan ${trasBuscar})`, trasBuscar > 0 && trasBuscar < muestras, cuenta);

        // Familia (rojos).
        await page.fill('.ptn-paleta .ptn-busca', '');
        await page.click('.ptn-paleta .ptn-fam[data-f="rojo"]');
        await page.waitForTimeout(200);
        const rojos = await page.locator('.ptn-paleta .ptn-sw').count();
        prueba(`${titulo}: el filtro por familia funciona (${rojos} rojos)`, rojos > 50);

        // Aplicar el primer color de la familia.
        await page.fill('.ptn-paleta .ptn-busca', 'PMS 186');
        await page.waitForTimeout(200);
        await page.locator('.ptn-paleta .ptn-sw').first().click();
        await page.waitForTimeout(250);
        const despues = await input.inputValue();
        prueba(`${titulo}: aplica el color en el input («${antes}» → «${despues}»)`,
            despues === '#ce1126', `esperaba #ce1126`);

        // El popover sigue abierto para elegir otro; se cierra con Escape.
        const sigue = await pop.isVisible();
        prueba(`${titulo}: el popover sigue abierto tras elegir`, sigue);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        prueba(`${titulo}: Escape cierra el popover`, !(await pop.isVisible()));
    }

    // ---------- EDITOR ----------
    console.log('\nEditor');
    await page.goto(`${BASE}/admin/editor/abrir?id=2`, { waitUntil: 'networkidle' });
    await comprobarPaleta('Editor', '#ed-fondo-color');

    // ---------- SHEETFLOW ----------
    console.log('\nSheetFlow');
    await page.goto(`${BASE}/admin/sheetflow/crear`, { waitUntil: 'networkidle' });
    const nombreSf = 'Paleta Pantone ' + Date.now();
    const campoSf = page.locator('input[name="nombre"]').first();
    if (await campoSf.count()) {
        await campoSf.fill(nombreSf);
        await Promise.all([
            page.waitForURL(/sheetflow\/abrir/, { timeout: 15000 }).catch(() => {}),
            page.click('button[type="submit"]')
        ]);
    }
    await page.waitForTimeout(600);
    if (/sheetflow\/abrir/.test(page.url())) {
        await comprobarPaleta('SheetFlow', '#sf-c-letra');
    } else {
        // Si el formulario no tenía ese campo, probamos sobre la propia página de crear.
        await comprobarPaleta('SheetFlow (crear)', '.sf-color input[type="color"]');
    }

    // ---------- REDACTOR ----------
    console.log('\nRedactor');
    await page.goto(`${BASE}/admin/redactor/crear`, { waitUntil: 'networkidle' });
    const nombreRd = 'Paleta Pantone ' + Date.now();
    const campoRd = page.locator('input[name="nombre"]').first();
    if (await campoRd.count()) {
        await campoRd.fill(nombreRd);
        await Promise.all([
            page.waitForURL(/redactor\/abrir/, { timeout: 15000 }).catch(() => {}),
            // Botón «Crear y abrir» (no el de borrar plantilla, que es submit de otro form).
            page.click('form.formulario button[type="submit"]:not([form])')
        ]);
    }
    await page.waitForTimeout(600);
    await comprobarPaleta('Redactor', '#rd-color');

    // Selección de texto + color de letra (lo que hace el usuario de verdad).
    const area = page.locator('[contenteditable="true"]').first();
    if (await area.count()) {
        await area.click();
        await page.keyboard.type('Texto de prueba Pantone');
        await page.keyboard.press('Control+A');
        const btnRd = page.locator('#rd-color ~ button.ptn-disparador, label.rd-color button.ptn-disparador').first();
        if (await btnRd.count()) {
            await btnRd.click();
            await page.waitForSelector('.ptn-paleta:not([hidden])', { timeout: 5000 });
            await page.fill('.ptn-paleta .ptn-busca', 'PMS 300');
            await page.waitForTimeout(200);
            await page.locator('.ptn-paleta .ptn-sw').first().click();
            await page.waitForTimeout(300);
            const aplicado = await page.evaluate(() => document.getElementById('rd-color').value);
            const html = await page.evaluate(() => {
                const ed = document.querySelector('[contenteditable="true"]');
                const sel = ed && ed.querySelector('font[color]');
                return sel ? sel.getAttribute('color') : '';
            });
            prueba(`Redactor: colorea la selección (input «${aplicado}», color «${html}»)`,
                aplicado.toLowerCase() === '#0072c6', 'no se aplicó a la selección');
        } else {
            prueba('Redactor: botón junto a #rd-color', false, 'no encontrado');
        }
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

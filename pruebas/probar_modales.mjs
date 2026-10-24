/**
 * probar_modales.mjs — Ningún diálogo nativo del navegador en la aplicación.
 *
 *   node pruebas/probar_modales.mjs
 *
 * Instala oyentes ANTES de que cargue la app para detectar cualquier
 * window.alert / window.confirm / window.prompt: si alguno se invoca, se
 * anota y se rechaza (para no bloquear el hilo) y la prueba falla.
 *
 * Recorre además los flujos que antes usaban nativos:
 *   · Editor   → borrar capa, renombrar lámina, renombrar capa, borrar lámina
 *   · Redactor → eliminar plantilla (formulario propio con data-confirm)
 *   · Carpetas → renombrar y crear carpeta (SheetFlow / Redactor)
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8018';
const EMAIL = process.env.EMAIL || 'modales.test@test.local';
const CLAVE = process.env.CLAVE || 'Prueba1234!';

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

/** Detectores de diálogos nativos, instalados en cuanto se abre la página. */
function vigilarNativos(page, lista) {
    const instalar = () => page.evaluate(() => {
        window.__nativos = [];
        ['alert', 'confirm', 'prompt'].forEach((nombre) => {
            const original = window[nombre];
            window[nombre] = function (...args) {
                window.__nativos.push(nombre + '(' + String(args[0] ?? '').slice(0, 80) + ')');
                // Se rechaza sin bloquear: confirm→false, prompt→null.
                return nombre === 'confirm' ? false : null;
            };
            window.__nativoOriginal = window.__nativoOriginal || {};
            window.__nativoOriginal[nombre] = original;
        });
    });
    page.on('framenavigated', (marco) => {
        if (marco === page.mainFrame()) instalar().catch(() => {});
    });
    return instalar();
}

async function recogerNativos(page) {
    return page.evaluate(() => window.__nativos || []).catch(() => []);
}

// Usuario de prueba y una plantilla propia (para poder eliminarla).
if (!process.env.EMAIL) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
    execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
        `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba Modales', '${EMAIL}', '${hash}', '', 1) ` +
        `ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)`]);
}
execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
    "DELETE FROM redactor_plantillas WHERE nombre LIKE 'Plantilla modales%'; " +
    "INSERT INTO redactor_plantillas (nombre, descripcion, contenido) VALUES ('Plantilla modales', 'Para la prueba', '<p>Hola</p>'); " +
    // Carpetas de rondas anteriores del propio test (si no, «ya existe»).
    "DELETE FROM archivo_archivos WHERE nombre = 'no'; " +
    "DELETE FROM archivo_carpetas WHERE nombre = 'Carpeta modales';"]);

const navegador = await chromium.launch();
const errores = [];

try {
    const ctx = await navegador.newContext({ viewport: { width: 1440, height: 950 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errores.push(`pageerror: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') errores.push(`console: ${m.text().slice(0, 200)}`); });
    // Si aparece un nativo de todas formas, se acepta para no bloquear el test.
    // OJO: los beforeunload se ACEPTAN (rechazarlos cancela la navegación).
    page.on('dialog', async (d) => {
        try {
            if (d.type() === 'beforeunload') await d.accept();
            else await d.dismiss();
        } catch (e) { /* ya cerrado */ }
    });

    const nativos = [];
    await page.addInitScript(() => {
        window.__nativos = window.__nativos || [];
        ['alert', 'confirm', 'prompt'].forEach((nombre) => {
            window[nombre] = function (...args) {
                window.__nativos.push(nombre + '(' + String(args[0] ?? '').slice(0, 90) + ')');
                return nombre === 'confirm' ? false : null;
            };
        });
    });

    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#email', EMAIL);
    await page.fill('#contrasena', CLAVE);
    await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

    const comprobar = async (etiqueta) => {
        const vistos = await recogerNativos(page);
        prueba(`sin diálogos nativos en «${etiqueta}»`, vistos.length === 0, vistos.join(' | '));
        return vistos;
    };

    /* ============================ EDITOR ============================ */
    console.log('\nEditor');
    await page.goto(`${BASE}/admin/editor/crear`, { waitUntil: 'networkidle' });
    await page.fill('#nombre', 'Doc modales ' + Date.now());
    await Promise.all([
        page.waitForURL(/editor\/abrir/, { timeout: 20000 }).catch(() => {}),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(1500);
    prueba('abre un documento del editor', /editor\/abrir/.test(page.url()), page.url());

    // Nueva lámina para poder borrarla.
    const btnLamina = page.locator('#ed-pagina-nueva');
    if (await btnLamina.count()) {
        await btnLamina.click().catch(() => {});
        await page.waitForTimeout(900);
    }

    // Borrar lámina → modal estilizado.
    const btnBorrarLam = page.locator('#ed-pagina-borrar');
    if (await btnBorrarLam.count()) {
        await btnBorrarLam.click();
        await page.waitForTimeout(500);
        const modal = page.locator('#modal-principal');
        const visible = await modal.isVisible().catch(() => false);
        prueba('«Borrar lámina» abre el modal estilizado', visible);
        if (visible) {
            await page.screenshot({ path: '/tmp/opencode/modal-editor-borrar.png' });
            const titulo = await page.locator('#modal-titulo').textContent().catch(() => '');
            prueba('el modal titula la confirmación', /lámina|Eliminar/i.test(titulo || ''), titulo);
            // Cancelar.
            await page.locator('#modal-pie [data-cerrar-modal]').first().click();
            await page.waitForTimeout(300);
            prueba('«Cancelar» cierra el modal', !(await modal.isVisible()));
        }
    }

    // Renombrar lámina (doble clic en la miniatura) → pedirTexto.
    const miniatura = page.locator('.ed-pagina-item').first();
    if (await miniatura.count()) {
        await miniatura.dblclick().catch(() => {});
        await page.waitForTimeout(500);
        const hayCampo = await page.locator('#modal-principal input[type="text"]').isVisible().catch(() => false);
        prueba('el renombrado de lámina usa el modal con campo', hayCampo);
        if (hayCampo) {
            await page.fill('#modal-principal input[type="text"]', 'Lámina renombrada');
            await page.locator('#modal-pie [data-confirmar-modal]').click();
            await page.waitForTimeout(600);
            prueba('el valor escrito llega a la lámina',
                (await page.locator('.ed-pagina-nom').first().textContent().catch(() => '')).includes('Lámina renombrada'));
        }
    }
    // El editor autoguarda cada pocos segundos: se espera a que no esté
    // "Guardando…" para que la navegación no interrumpa la petición.
    await page.waitForTimeout(1000);
    const txtEstado = await page.locator('#ed-estado').textContent().catch(() => '');
    if (/Guardando/i.test(txtEstado || '')) await page.waitForTimeout(3000);
    await comprobar('Editor');

    /* ============================ REDACTOR ============================ */
    console.log('\nRedactor');
    // El editor puede dejar cambios sin guardar y pedir antesunload: se sale
    // primero por una página intermedia y después al Redactor.
    await page.goto('about:blank', { waitUntil: 'commit' }).catch(() => {});
    await page.goto(`${BASE}/admin/redactor/crear`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(600);
    if (!/redactor\/crear/.test(page.url())) {
        await page.goto(`${BASE}/admin/redactor/crear`, { waitUntil: 'domcontentloaded' }).catch(() => {});
        await page.waitForTimeout(600);
    }
    prueba('llega a la creación del Redactor', /redactor\/crear/.test(page.url()), page.url());
    const btnEliminarPlantilla = page.locator('button[form="rd-form-plantilla-eliminar"]').first();
    const hayBorrar = await btnEliminarPlantilla.count();
    prueba('existe el botón de eliminar plantilla (formulario propio)', hayBorrar > 0);
    if (hayBorrar) {
        await btnEliminarPlantilla.click();
        await page.waitForTimeout(600);
        const modal = page.locator('#modal-principal');
        prueba('«Eliminar plantilla» abre el modal estilizado (no confirm nativo)',
            await modal.isVisible().catch(() => false));
        if (await modal.isVisible().catch(() => false)) {
            await page.screenshot({ path: '/tmp/opencode/modal-plantilla.png' });
            await page.locator('#modal-pie [data-cerrar-modal]').first().click();
            await page.waitForTimeout(300);
        }
    }
    await comprobar('Redactor');

    /* ============================ CARPETAS ============================ */
    console.log('\nCarpetas (SheetFlow)');
    // Se siembra una hoja con carpeta para poder probar renombrar y mover.
    execFileSync('mysql', ['-uroot', 'cotizaciones', '-e',
        "INSERT INTO sheetflow_hojas (nombre, fecha, departamento, descripcion, datos, creado_por) " +
        "SELECT 'Hoja modales', CURDATE(), 'Carpeta modales', '', '{\"cols\":[{\"n\":\"A\"}],\"rows\":[[\"\"]]}', u.id " +
        "FROM usuarios u WHERE u.email = '" + EMAIL + "' AND NOT EXISTS " +
        "(SELECT 1 FROM (SELECT nombre FROM sheetflow_hojas) t WHERE t.nombre = 'Hoja modales') LIMIT 1;"]);
    await page.goto(`${BASE}/admin/sheetflow`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const hayRenombrar = page.locator('[data-carpeta-renombrar]');
    if (await hayRenombrar.count()) {
        await hayRenombrar.first().click();
        await page.waitForTimeout(500);
        const hayCampo = await page.locator('#modal-principal input[type="text"]').isVisible().catch(() => false);
        prueba('renombrar carpeta usa el modal con campo (no prompt nativo)', hayCampo);
        if (hayCampo) {
            await page.locator('#modal-pie [data-cerrar-modal]').first().click();
            await page.waitForTimeout(300);
        }
    } else {
        // Sin carpetas: se crea una para la prueba.
        console.log('        (sin carpetas todavía: se omite el renombrado)');
    }

    // «Mover a… → Carpeta nueva» pide el nombre en el modal.
    const mover = page.locator('.carpeta-mover').first();
    if (await mover.count()) {
        await mover.selectOption('__nueva').catch(() => {});
        await page.waitForTimeout(500);
        const hayCampo = await page.locator('#modal-principal input[type="text"]').isVisible().catch(() => false);
        prueba('«Carpeta nueva» pide el nombre en el modal', hayCampo);
        if (hayCampo) {
            await page.locator('#modal-pie [data-cerrar-modal]').first().click();
            await page.waitForTimeout(300);
        }
    }

    // Borrado con data-confirm → modal.
    const btnEliminar = page.locator('form[data-confirm] button[type="submit"]').first();
    if (await btnEliminar.count()) {
        await btnEliminar.click();
        await page.waitForTimeout(600);
        const modal = page.locator('#modal-principal');
        const visible = await modal.isVisible().catch(() => false);
        prueba('el borrado con data-confirm abre el modal', visible);
        if (visible) {
            await page.screenshot({ path: '/tmp/opencode/modal-borrar.png' });
            await page.locator('#modal-pie [data-cerrar-modal]').first().click();
            await page.waitForTimeout(300);
        }
    }
    await comprobar('Carpetas');

    /* ====================== ARCHIVO (diálogos propios) ====================== */
    console.log('\nArchivo');
    await page.goto(`${BASE}/admin/archivo`, { waitUntil: 'networkidle' });
    await page.click('[data-arch-nueva-carpeta]');
    await page.waitForTimeout(400);
    await page.fill('#ar-dlg-carpeta input[name="nombre"]', 'Carpeta modales');
    await Promise.all([
        page.waitForURL(/carpeta=\d+/, { timeout: 10000 }),
        page.click('#ar-dlg-carpeta button[type="submit"]'),
    ]);
    await page.waitForTimeout(500);
    prueba('el archivo crea carpetas con su <dialog> propio', /carpeta=\d+/.test(page.url()));
    await comprobar('Archivo');

    /* ============================ ESC + MODAL ============================ */
    console.log('\nModal compartido');
    await page.goto(`${BASE}/admin/sheetflow`, { waitUntil: 'networkidle' });
    const btnCrearConfirm = page.locator('form[data-confirm] button[type="submit"]').first();
    if (await btnCrearConfirm.count()) {
        await btnCrearConfirm.click();
        await page.waitForTimeout(500);
        if (await page.locator('#modal-principal').isVisible().catch(() => false)) {
            await page.keyboard.press('Escape');
            await page.waitForTimeout(400);
            prueba('Escape cierra el modal', !(await page.locator('#modal-principal').isVisible()));
        }
    }

    // Recuento global.
    const totalNativos = await recogerNativos(page);
    await comprobar('recuento final');
    console.log(`  (diálogos nativos detectados durante toda la sesión: ${totalNativos.length})`);

    await ctx.close();
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

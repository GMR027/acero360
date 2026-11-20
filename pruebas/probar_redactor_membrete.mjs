/**
 * probar_redactor_membrete.mjs — Membrete por bloques del Redactor.
 *
 *   DB_NAME=cotizaciones_test BASE_URL=http://127.0.0.1:8018 node pruebas/probar_redactor_membrete.mjs
 *
 * Comprueba que el panel nuevo carga el diseño guardado, que al guardar se
 * persiste el JSON y que PDF, Word y LibreOffice repiten encabezado, pie,
 * numeración y marca de agua en todas las páginas.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8018';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const EMAIL = 'membrete.test@test.local';
const CLAVE = 'Prueba1234!';
const SALIDA = '/tmp/opencode/membrete-test';
const sql = (c) => execFileSync('mysql', ['-uroot', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();

let ok = 0, mal = 0;
const prueba = (n, v, d = '') => {
    if (v) { ok++; console.log(`  ok   ${n}`); }
    else { mal++; console.log(`  FALLA ${n}${d ? ' → ' + d : ''}`); }
};

if (!existsSync(SALIDA)) execFileSync('mkdir', ['-p', SALIDA]);
const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba Membrete','${EMAIL}','${hash}',1)`);
sql("DELETE FROM redactor_documentos WHERE nombre='Membrete QA'");

// Una imagen real del almacén del Redactor para el logo.
const vplan = `${process.cwd()}/public/uploads/vplan`;
const imagenes = existsSync(vplan) ? readdirSync(vplan).filter((n) => /^[a-f0-9]{32}\.(png|jpg)$/.test(n)) : [];
if (!imagenes.length) { console.error('No hay imágenes en public/uploads/vplan para probar el logo.'); process.exit(2); }
const logo = imagenes[0];

const membrete = JSON.stringify({
    encabezado: {
        activo: 1, disposicion: 'logo_izq', alineacion: 'izq',
        logo_imagen: logo, logo_opacidad: 80, logo_ancho: 2.4,
        titulo_texto: 'Informe de obra', titulo_tamano: 16, titulo_color: '#1d4ed8',
        titulo_negrita: 1, titulo_cursiva: 0,
        subtitulo_texto: '{departamento}', subtitulo_tamano: 9.5, subtitulo_color: '#64748b',
        subtitulo_negrita: 0, subtitulo_cursiva: 0,
    },
    pie: {
        activo: 1, disposicion: 'logo_der', alineacion: 'izq',
        logo_imagen: logo, logo_opacidad: 60, logo_ancho: 1.4,
        titulo_texto: 'Soluciones en Acero', titulo_tamano: 9.5, titulo_color: '#475569',
        titulo_negrita: 0, titulo_cursiva: 0,
        subtitulo_texto: '{fecha}', subtitulo_tamano: 8, subtitulo_color: '#94a3b8',
        subtitulo_negrita: 0, subtitulo_cursiva: 0,
    },
    marca: { activo: 1, texto: 'BORRADOR', color: '#94a3b8', opacidad: 20, tamano: 90, angulo: -30 },
});
const contenido = '<p>Primera página del documento.</p><div class="salto-pagina"></div><p>Segunda página del documento.</p>';
const esc = (v) => String(v).replace(/'/g, "''");
const id = sql(`INSERT INTO redactor_documentos (nombre,fecha,departamento,papel,orientacion,margen_sup,margen_inf,margen_izq,margen_der,numerar,num_posicion,membrete,contenido,creado_por) VALUES ('Membrete QA','2026-11-19','Ingeniería','carta','vertical',2.54,2.54,2.54,2.54,1,'pie_der','${esc(membrete)}','${esc(contenido)}',1); SELECT LAST_INSERT_ID();`);

const navegador = await chromium.launch();
try {
    const ctx = await navegador.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', (e) => errores.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

    await page.goto(`${BASE}/login`);
    await page.fill('#email', EMAIL);
    await page.fill('#contrasena', CLAVE);
    await Promise.all([page.waitForURL('**/admin**'), page.click('button[type="submit"]')]);

    console.log('\nPanel y vista previa');
    await page.goto(`${BASE}/admin/redactor/abrir?id=${id}`, { waitUntil: 'networkidle' });
    await page.click('[data-pest="diseno"]');
    await page.click('#rd-x-encpie');
    await page.waitForSelector('#rd-p-encpie:not([hidden])');
    prueba('el panel muestra el título guardado', await page.inputValue('#rd-mb-enc-titulo') === 'Informe de obra');
    prueba('el panel muestra el logo guardado', await page.inputValue('#rd-x-enc-img') === logo);
    await page.waitForSelector('.rd-mb-zona--enc', { timeout: 5000 }).catch(() => {});
    await page.waitForSelector('.rd-mb-zona--pie', { timeout: 5000 }).catch(() => {});
    await page.waitForSelector('.rd-marca-txt', { timeout: 5000 }).catch(() => {});
    prueba('la hoja dibuja el encabezado en cada página', await page.locator('.rd-mb-zona--enc').count() === 2);
    prueba('la hoja dibuja el pie en cada página', await page.locator('.rd-mb-zona--pie').count() === 2);
    prueba('la hoja dibuja la marca de agua', await page.locator('.rd-marca-txt').count() > 0);
    prueba('el logo aparece en la vista previa', await page.locator('.rd-mb-zona--enc img').count() === 2);

    console.log('\nGuardado');
    await page.fill('#rd-mb-enc-titulo', 'Informe de obra · QA');
    await page.fill('#rd-mb-enc-titulo-color', '#15803d');
    await page.fill('#rd-mb-enc-titulo-tam', '18');
    await page.fill('#rd-mb-pie-sub', 'Documento controlado · {fecha}');
    await page.click('#rd-guardar');
    await page.waitForTimeout(2500);
    const guardado = JSON.parse(sql(`SELECT membrete FROM redactor_documentos WHERE id=${id}`));
    prueba('guarda el título nuevo', guardado.encabezado.titulo_texto === 'Informe de obra · QA', guardado.encabezado.titulo_texto);
    prueba('guarda color y tamaño del título', guardado.encabezado.titulo_color === '#15803d' && Number(guardado.encabezado.titulo_tamano) === 18);
    prueba('guarda el subencabezado del pie', guardado.pie.subtitulo_texto.includes('Documento controlado'));

    console.log('\nExportaciones');
    const pedir = async (formato) => {
        const r = await ctx.request.get(`${BASE}/admin/redactor/descargar?id=${id}&formato=${formato}`);
        const buf = await r.body();
        writeFileSync(`${SALIDA}/membrete.${formato}`, buf);
        return { status: r.status(), buf };
    };

    const pdf = await pedir('pdf');
    prueba('PDF descarga', pdf.status === 200 && pdf.buf.length > 300, `${pdf.status}, ${pdf.buf.length} bytes`);
    const textoPdf = execFileSync('pdftotext', ['-layout', `${SALIDA}/membrete.pdf`, '-'], { encoding: 'utf8' });
    prueba('el PDF repite el encabezado en las 2 páginas', (textoPdf.match(/Informe de obra · QA/g) || []).length === 2);
    prueba('el PDF repite el pie y la numeración', (textoPdf.match(/Soluciones en Acero/g) || []).length === 2 && /Página 1 de 2/.test(textoPdf) && /Página 2 de 2/.test(textoPdf));
    const paginas = execFileSync('pdfinfo', [`${SALIDA}/membrete.pdf`], { encoding: 'utf8' });
    prueba('el PDF tiene 2 páginas', /Pages:\s+2/.test(paginas), paginas.match(/Pages:\s+\d+/)?.[0] || '');

    const docx = await pedir('docx');
    prueba('Word descarga', docx.status === 200 && docx.buf.length > 300, `${docx.status}, ${docx.buf.length} bytes`);
    const listaDocx = execFileSync('unzip', ['-l', `${SALIDA}/membrete.docx`], { encoding: 'utf8' });
    prueba('Word lleva encabezado y pie propios', /word\/header1\.xml/.test(listaDocx) && /word\/footer1\.xml/.test(listaDocx));
    prueba('Word lleva el logo y la marca de agua', /word\/media\/header1_image\d+\.png/.test(listaDocx) && /word\/media\/footer1_image\d+\.png/.test(listaDocx));
    const headerXml = execFileSync('unzip', ['-p', `${SALIDA}/membrete.docx`, 'word/header1.xml'], { encoding: 'utf8' });
    prueba('Word escribe el título con su color', /Informe de obra · QA/.test(headerXml) && /15803d/.test(headerXml));

    const odt = await pedir('odt');
    prueba('LibreOffice descarga', odt.status === 200 && odt.buf.length > 300, `${odt.status}, ${odt.buf.length} bytes`);
    const estilosOdt = execFileSync('unzip', ['-p', `${SALIDA}/membrete.odt`, 'styles.xml'], { encoding: 'utf8' });
    prueba('LibreOffice lleva estilos de encabezado y pie', /RdMbHeaderTitulo/.test(estilosOdt) && /RdMbFooterTitulo/.test(estilosOdt));
    prueba('LibreOffice lleva la marca de agua', /Pictures\/rdmarca\.png/.test(estilosOdt));
    prueba('LibreOffice lleva el logo', /Pictures\/rdmembrete_header\.png/.test(estilosOdt));

    prueba('sin errores de consola', errores.length === 0, errores.join(' | '));
    await ctx.close();
} catch (e) {
    mal++;
    console.log('\n  EXCEPCIÓN: ' + e.message.split('\n')[0]);
} finally {
    sql(`DELETE FROM redactor_documentos WHERE id=${id}`);
    sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
    await navegador.close();
    console.log(`\nResultado: ${ok} ok, ${mal} fallo(s)`);
    process.exit(mal ? 1 : 0);
}

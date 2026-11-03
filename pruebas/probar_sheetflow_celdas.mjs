/**
 * probar_sheetflow_celdas.mjs — Fase 1: formato, enlaces, barras, notas, filtro
 * y contrato del API con el Editor.
 *
 *   DB_NAME=cotizaciones_test BASE_URL=http://127.0.0.1:8018 node pruebas/probar_sheetflow_celdas.mjs
 */
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ok   ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

const hash = execFileSync('php', ['-r', "echo password_hash('Prueba1234!', PASSWORD_BCRYPT);"], { encoding: 'utf8' }).trim();
const correo = 'prueba.sfceldas@cotizaciones.local';
sql(`DELETE FROM usuarios WHERE email='${correo}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba SheetFlow','${correo}','${hash}',1)`);

const galletas = (r) => (r.headers.getSetCookie?.() || []).map((c) => c.split(';')[0]).join('; ');
const previo = await fetch(BASE + '/login');
let cookie = galletas(previo);
const csrf = ((await previo.text()).match(/name="_csrf"\s+value="([^"]+)"/) || ['', ''])[1];
const login = await fetch(BASE + '/login', { method: 'POST', redirect: 'manual', headers: { cookie }, body: new URLSearchParams({ _csrf: csrf, email: correo, contrasena: 'Prueba1234!' }) });
cookie = galletas(login) || cookie;
const pedir = (m, r, datos) => fetch(BASE + r, { method: m, redirect: 'manual', headers: { cookie, 'x-csrf-token': csrf }, body: datos ? new URLSearchParams(datos) : undefined });

let hoja = 0;
let hojaPivote = 0;
let editorId = 0;
try {
  console.log('API y formato');
  const crear = await pedir('POST', '/admin/sheetflow/crear', { nombre: 'T celdas', fecha: '2026-11-02' });
  hoja = Number(sql(`SELECT id FROM sheetflow_hojas WHERE nombre='T celdas' ORDER BY id DESC LIMIT 1`));
  verifica('crea la hoja', crear.status === 302 && hoja > 0, `status ${crear.status}`);

  const datos = {
    cols: [
      { n: 'Producto', t: 'texto', a: 'izquierda' },
      { n: 'Avance', t: 'numero', a: 'derecha' },
      { n: 'Nota', t: 'texto' },
    ],
    rows: [
      ['Acero', '45', ''],
      ['Tornillo', '80', ''],
      ['Pintura', '10', ''],
    ],
    estilo: { enc: '#1f3a5f', encTxt: '#ffffff', zebra: true, bordes: true },
    fmt: {
      '0,0': { b: 1, bg: '#fff7c2' },
      '0,1': { bar: 1, al: 'derecha' },
      '1,0': { lk: 'https://example.com', u: 1 },
      '1,2': { nota: 'Revisar existencia', bg: '#e0f2fe' },
    },
  };
  const version = sql(`SELECT actualizado FROM sheetflow_hojas WHERE id=${hoja}`);
  const guardar = await pedir('POST', '/admin/sheetflow/api/guardar', {
    id: String(hoja), version, nombre: 'T celdas', fecha: '2026-11-02', departamento: '', descripcion: '',
    datos: JSON.stringify(datos),
  });
  const gjson = await guardar.json().catch(() => ({}));
  verifica('guarda formato y herramientas de celda', guardar.status === 200 && gjson.success === true, JSON.stringify(gjson).slice(0, 160));

  const api = await pedir('POST', '/admin/editor/api/tabla', { origen: 'sheetflow', id: String(hoja) });
  const d = await api.json().catch(() => ({}));
  verifica('el API del Editor responde', api.status === 200 && d.success === true, JSON.stringify(d).slice(0, 160));
  verifica('el API envía estilo del origen', d.estilo && d.estilo.enc === '#1f3a5f' && d.estilo.encTxt === '#ffffff', JSON.stringify(d.estilo));
  verifica('el API envía formato en coordenadas del Editor', d.fmt && d.fmt['1,0'] && d.fmt['1,0'].b === 1 && d.fmt['2,0'] && d.fmt['2,0'].lk === 'https://example.com', JSON.stringify(d.fmt));
  verifica('el API envía alineación y tipos', Array.isArray(d.alinear) && d.alinear[1] === 'derecha' && d.tipos[1] === 'numero', JSON.stringify(d.alinear));
  verifica('la barra viaja en la fila de datos correcta', d.fmt['1,1'] && d.fmt['1,1'].bar === 1, JSON.stringify(d.fmt));

  console.log('Navegador');
  const navegador = await chromium.launch();
  try {
    const page = await (await navegador.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('#email', correo);
    await page.fill('#contrasena', 'Prueba1234!');
    await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);
    await page.goto(`${BASE}/admin/sheetflow/abrir?id=${hoja}`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#sf-grid td');

    const celda0 = await page.evaluate(() => {
      const td = document.querySelector('#sf-grid td[data-r="0"][data-c="0"]');
      return { peso: getComputedStyle(td).fontWeight, fondo: getComputedStyle(td).backgroundColor };
    });
    verifica('la celda conserva negrita y relleno', /bold|700/.test(celda0.peso) && celda0.fondo === 'rgb(255, 247, 194)', JSON.stringify(celda0));

    verifica('la celda con enlace se dibuja como enlace', await page.locator('#sf-grid td[data-r="1"][data-c="0"] a.sf-enlace').count() === 1);
    verifica('la barra de progreso se dibuja', await page.locator('#sf-grid td[data-r="0"][data-c="1"] .sf-barra-pista > span').count() === 1);
    verifica('la nota marca la celda', await page.locator('#sf-grid td[data-r="1"][data-c="2"].sf-con-nota').count() === 1);
    verifica('los renglones alternos están activos', await page.locator('#sf-grid tr.sf-alt').count() > 0);

    // Formato condicional: colorear la columna Avance cuando supera 50.
    await page.click('#sf-grid td[data-r="0"][data-c="1"]');
    await page.click('#sf-grid td[data-r="1"][data-c="1"]', { modifiers: ['Shift'] });
    await page.click('#sf-regla');
    await page.waitForSelector('#sf-regla-tipo', { timeout: 5000 });
    await page.selectOption('#sf-regla-tipo', 'mayor');
    await page.fill('#sf-regla-v1', '50');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(300);
    const cond = await page.evaluate(() => ({
      alto: getComputedStyle(document.querySelector('#sf-grid td[data-r="1"][data-c="1"]')).backgroundColor,
      bajo: getComputedStyle(document.querySelector('#sf-grid td[data-r="0"][data-c="1"]')).backgroundColor,
    }));
    verifica('el formato condicional colorea solo lo que cumple', cond.alto === 'rgb(254, 226, 226)' && cond.bajo !== cond.alto, JSON.stringify(cond));

    // Minigráfica en una celda.
    await page.click('#sf-grid td[data-r="0"][data-c="2"]');
    await page.click('#sf-spark-poner');
    await page.waitForSelector('#sf-spark-rango', { timeout: 5000 });
    await page.fill('#sf-spark-rango', 'B2:B4');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(300);
    verifica('dibuja una minigráfica en la celda', await page.locator('#sf-grid td[data-r="0"][data-c="2"] svg.sf-spark').count() === 1);

    // Gráfica de la tabla.
    await page.click('#sf-grafica-poner');
    await page.waitForSelector('#sf-grafica-rango', { timeout: 5000 });
    await page.fill('#sf-grafica-titulo', 'Avance por producto');
    await page.fill('#sf-grafica-rango', 'B2:B4');
    await page.selectOption('#sf-grafica-tipo', 'barras');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(300);
    verifica('crea una gráfica desde un rango', await page.locator('#sf-graficas svg.sf-grafica-svg').count() === 1);

    // Subtotales por producto.
    await page.click('#sf-grid td[data-r="0"][data-c="0"]');
    await page.click('#sf-grid td[data-r="2"][data-c="1"]', { modifiers: ['Shift'] });
    await page.click('#sf-pestanas [data-pest="datos"]');
    await page.click('#sf-subtotales');
    await page.waitForSelector('#sf-sub-g', { timeout: 5000 });
    await page.selectOption('#sf-sub-g', '0');
    await page.selectOption('#sf-sub-s', '1');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(300);
    verifica('agrega subtotales y total al final', (await page.locator('#sf-grid td').allInnerTexts()).includes('Total'));

    // Combinar y separar celdas.
    await page.click('#sf-pestanas [data-pest="insertar"]');
    await page.click('#sf-grid td[data-r="0"][data-c="0"]');
    await page.click('#sf-grid td[data-r="0"][data-c="1"]', { modifiers: ['Shift'] });
    await page.click('#sf-combinar');
    await page.waitForTimeout(200);
    verifica('combina el rango en una sola celda', await page.locator('#sf-grid td[data-r="0"][data-c="0"][colspan="2"]').count() === 1 && await page.locator('#sf-grid td[data-r="0"][data-c="1"]').count() === 0);
    await page.click('#sf-guardar');
    await page.waitForTimeout(700);
    const apiM = await (await pedir('POST', '/admin/editor/api/tabla', { origen: 'sheetflow', id: String(hoja) })).json().catch(() => ({}));
    verifica('el API envía las celdas combinadas al Editor', !!(apiM.merges && apiM.merges.length === 1 && apiM.merges[0].r0 === 1 && apiM.merges[0].c1 === 1), JSON.stringify(apiM.merges));
    await page.click('#sf-grid td[data-r="0"][data-c="0"]');
    await page.click('#sf-separar');
    await page.waitForTimeout(200);
    verifica('separa las celdas combinadas', await page.locator('#sf-grid td[data-r="0"][data-c="0"][colspan]').count() === 0);

    // Filtro: solo debe quedar el renglón con «Acero».
    await page.click('#sf-pestanas [data-pest="datos"]');
    await page.fill('#sf-filtro', 'Acero');
    await page.waitForTimeout(250);
    const visibles = await page.evaluate(() => {
      const filas = [...document.querySelectorAll('#sf-grid td[data-c="0"]')];
      const busca = (t) => filas.find((td) => td.innerText.trim() === t);
      const fila = (t) => { const td = busca(t); return td ? td.closest('tr') : null; };
      const acero = fila('Acero'), pintura = fila('Pintura');
      return {
        acero: acero ? getComputedStyle(acero).display !== 'none' : false,
        pintura: pintura ? getComputedStyle(pintura).display === 'none' : false,
      };
    });
    verifica('el filtro oculta los renglones que no coinciden', visibles.acero && visibles.pintura, JSON.stringify(visibles));
    await page.click('#sf-filtro-limpiar');
    await page.waitForTimeout(150);
    verifica('quitar el filtro vuelve a mostrar todo', await page.locator('#sf-grid tbody tr.sf-oculta').count() === 0);

    // Datos externos: insertar una venta del periodo.
    await page.click('#sf-grid td[data-r="2"][data-c="2"]');
    await page.click('#sf-ext-insertar');
    await page.waitForSelector('#sf-ext-fuente', { timeout: 5000 });
    await page.selectOption('#sf-ext-fuente', 'ventas');
    await page.selectOption('#sf-ext-campo', 'importe');
    await page.waitForTimeout(400);
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(700);
    verifica('inserta un dato de ventas vinculado', await page.locator('#sf-grid td[data-r="2"][data-c="2"].sf-ext').count() === 1);
    verifica('el dato de ventas muestra un importe', /[\d.,]+/.test(await page.locator('#sf-grid td[data-r="2"][data-c="2"]').innerText()));

    // Lista completa: clientes.
    await page.click('#sf-grid td[data-r="2"][data-c="0"]');
    await page.click('#sf-lista-insertar');
    await page.waitForSelector('#sf-lista-fuente', { timeout: 5000 });
    await page.selectOption('#sf-lista-fuente', 'clientes');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(900);
    verifica('inserta una lista de clientes con encabezado', (await page.locator('#sf-grid td[data-r="2"][data-c="0"]').innerText()) === 'Cliente');
    verifica('la lista amplía los renglones', (await page.locator('#sf-grid td[data-r="5"]').count()) > 0);

    // QR en una celda.
    await page.click('#sf-pestanas [data-pest="insertar"]');
    await page.click('#sf-grid td[data-r="0"][data-c="2"]');
    await page.click('#sf-qr-poner');
    await page.waitForSelector('#modal-cuerpo input.control', { timeout: 5000 });
    await page.fill('#modal-cuerpo input.control', 'ACERO-123');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(1800);
    verifica('genera un QR y lo deja como imagen de la celda', await page.locator('#sf-grid td[data-r="0"][data-c="2"] img.sf-img').count() === 1);

    await page.click('#sf-guardar');
    await page.waitForTimeout(800);
    const guardado = JSON.parse(sql(`SELECT datos FROM sheetflow_hojas WHERE id=${hoja}`));
    verifica('guarda el texto y la imagen del QR', !!(guardado.fmt['0,2'] && guardado.fmt['0,2'].qr === 'ACERO-123' && guardado.fmt['0,2'].im), JSON.stringify(guardado.fmt['0,2'] || {}));
    verifica('guarda las gráficas de la tabla', Array.isArray(guardado.graficas) && guardado.graficas.length === 1, JSON.stringify(guardado.graficas || []));

    await page.click('#sf-guardar');
    await page.waitForTimeout(800);
    const api2 = await (await pedir('POST', '/admin/editor/api/tabla', { origen: 'sheetflow', id: String(hoja) })).json().catch(() => ({}));
    verifica('el formato condicional llega al Editor', !!(api2.fmt && api2.fmt['2,1'] && api2.fmt['2,1'].bg === '#fee2e2'), JSON.stringify((api2 && api2.fmt) || {}).slice(0, 200));
    const pdf = await pedir('GET', `/admin/sheetflow/descargar?id=${hoja}&formato=pdf`);
    const pdfBytes = Buffer.from(await pdf.arrayBuffer());
    verifica('exporta PDF con formato y gráficas', pdf.status === 200 && pdfBytes.length > 800, `status ${pdf.status}, ${pdfBytes.length} bytes`);

    // Versiones: crear, cambiar, restaurar (API).
    const datosAntes = JSON.parse(sql(`SELECT datos FROM sheetflow_hojas WHERE id=${hoja}`));
    const a1Original = datosAntes.rows[0][0];
    await pedir('POST', '/admin/sheetflow/api/versiones', { id: String(hoja), accion: 'crear' });
    const listaV = await (await pedir('POST', '/admin/sheetflow/api/versiones', { id: String(hoja), accion: 'listar' })).json();
    const idVersion = listaV.versiones && listaV.versiones[0] ? listaV.versiones[0].id : 0;
    const verActual = sql(`SELECT actualizado FROM sheetflow_hojas WHERE id=${hoja}`);
    await pedir('POST', '/admin/sheetflow/api/guardar', {
      id: String(hoja), version: verActual, nombre: 'T celdas', fecha: '2026-11-02', departamento: '', descripcion: '',
      datos: JSON.stringify({ cols: [{ n: 'X', t: 'texto' }], rows: [['CAMBIADO']] }),
    });
    const rest = await (await pedir('POST', '/admin/sheetflow/api/versiones', { id: String(hoja), accion: 'restaurar', version: String(idVersion) })).json();
    const restaurado = JSON.parse(sql(`SELECT datos FROM sheetflow_hojas WHERE id=${hoja}`));
    verifica('restaura una versión anterior', rest.success === true && restaurado.rows[0][0] === a1Original, JSON.stringify(rest));

    // Versiones y enlace público.
    await page.click('#sf-versiones');
    await page.waitForTimeout(700);
    verifica('lista las versiones guardadas', await page.locator('.sf-version').count() > 0);
    await page.click('[data-cerrar-modal]');
    await page.waitForTimeout(200);
    await page.click('#sf-compartir');
    await page.waitForTimeout(500);
    await page.getByRole('button', { name: 'Crear enlace público' }).click();
    await page.waitForSelector('#sf-enlace-publico', { timeout: 6000 });
    const urlPublica = await page.inputValue('#sf-enlace-publico');
    const publica = await fetch(urlPublica);
    const htmlPublico = await publica.text();
    verifica('el enlace público abre la tabla sin sesión', publica.status === 200 && htmlPublico.includes('T celdas'), `status ${publica.status}`);
    const tokenPublico = new URL(urlPublica).searchParams.get('t');
    const datosTok = sql(`SELECT datos FROM sheetflow_hojas WHERE id=${hoja}`);
    const verTok = sql(`SELECT actualizado FROM sheetflow_hojas WHERE id=${hoja}`);
    await pedir('POST', '/admin/sheetflow/api/guardar', {
      id: String(hoja), version: verTok, nombre: 'T celdas', fecha: '2026-11-02', departamento: '', descripcion: '', datos: datosTok,
    });
    const estadoTok = await (await pedir('POST', '/admin/sheetflow/api/compartir', { id: String(hoja), accion: 'estado' })).json();
    verifica('el token sobrevive a un guardado', !!estadoTok.token && estadoTok.token === tokenPublico, JSON.stringify(estadoTok));
    await page.getByRole('button', { name: 'Desactivar enlace' }).click();
    await page.waitForTimeout(300);
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(600);
    const publica2 = await fetch(urlPublica);
    verifica('desactivar el enlace deja de mostrarlo', publica2.status === 404, `status ${publica2.status}`);
    await page.click('[data-cerrar-modal]');

    // Editor: insertar la tabla y comprobar los atributos del nodo Konva.
    const editorIdCreado = Number((await (await pedir('POST', '/admin/editor/crear', { nombre: 'T editor fmt', fecha: '2026-11-02' })).headers.get('location') || '').match(/id=(\d+)/)?.[1] || 0);
    editorId = editorIdCreado;
    verifica('crea un documento del Editor', editorId > 0);
    if (editorId) {
      await page.goto(`${BASE}/admin/editor/abrir?id=${editorId}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(600);
      await page.click('[data-herr="tabla"]');
      await page.waitForSelector('#ed-t-origen', { timeout: 5000 });
      await page.selectOption('#ed-t-origen', 'sf:' + hoja);
      await page.click('[data-confirmar-modal]');
      await page.waitForTimeout(900);
      const nodo = await page.evaluate(() => {
        const stage = window.Konva && window.Konva.stages && window.Konva.stages[0];
        if (!stage) return { error: 'sin stage' };
        const t = stage.find('Group').filter((n) => n.getAttr('esTabla'))[0];
        if (!t) return { error: 'sin tabla' };
        const textos = t.find('Text');
        const rects = t.find('Rect');
        return {
          fuente: t.getAttr('fuenteTipo'),
          fmt: t.getAttr('celdasFmt'),
          hfill: t.getAttr('hfill'),
          htexto: t.getAttr('htexto'),
          zebra: t.getAttr('zebra'),
          negritas: textos.filter((n) => /bold/.test(n.fontStyle() || '')).length,
          fondos: rects.map((n) => n.fill()).filter((c) => c === '#fff7c2' || c === '#e0f2fe').length,
          hijos: t.getChildren().length,
        };
      });
      verifica('el Editor inserta la tabla vinculada', nodo.fuente === 'sheetflow', JSON.stringify(nodo).slice(0, 200));
      verifica('el Editor guarda el formato por celda', !!(nodo.fmt && nodo.fmt['1,0'] && nodo.fmt['1,0'].b === 1), JSON.stringify(nodo.fmt));
      verifica('el Editor usa el encabezado del origen', nodo.hfill === '#1f3a5f' && nodo.htexto === '#ffffff', `${nodo.hfill} / ${nodo.htexto}`);
      verifica('el Editor dibuja negritas y fondos de celda', nodo.negritas > 0 && nodo.fondos >= 2, `negritas ${nodo.negritas}, fondos ${nodo.fondos}`);
    }

    // Tabla dinámica: crea una hoja nueva y navega a ella.
    await page.goto(`${BASE}/admin/sheetflow/abrir?id=${hoja}`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#sf-grid td');
    await page.click('#sf-pestanas [data-pest="datos"]');
    await page.click('#sf-pivote');
    await page.waitForSelector('#sf-piv-f', { timeout: 5000 });
    await page.selectOption('#sf-piv-f', '0');
    const opcionesPiv = await page.$$eval('#sf-piv-v option', (os) => os.map((o) => o.value));
    await page.selectOption('#sf-piv-v', opcionesPiv.find((v) => v !== '-1') || '-1');
    await page.click('[data-confirmar-modal]');
    await page.waitForTimeout(1500);
    const mp = page.url().match(/[?&]id=(\d+)/);
    hojaPivote = mp ? Number(mp[1]) : 0;
    verifica('crea una tabla dinámica en una hoja nueva', hojaPivote > 0 && hojaPivote !== hoja && /Total/.test(await page.locator('#sf-grid').innerText()), page.url());
  } finally {
    await navegador.close();
  }
} finally {
  if (hoja) sql(`DELETE FROM sheetflow_hojas WHERE id=${hoja}`);
  if (hoja) sql(`DELETE FROM sheetflow_versiones WHERE hoja_id=${hoja}`);
  if (hojaPivote) sql(`DELETE FROM sheetflow_hojas WHERE id=${hojaPivote}`);
  if (editorId) sql(`DELETE FROM editor_documentos WHERE id=${editorId}`);
  sql(`DELETE FROM usuarios WHERE email='${correo}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

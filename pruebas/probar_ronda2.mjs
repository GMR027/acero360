/**
 * probar_ronda2.mjs — Verificación de la ronda de correcciones:
 *   1. Catálogo: sin suma de costos, leyenda IVA por CADA empresa y tabla
 *      más compacta en vertical (se mide la separación real entre renglones).
 *   2. Logo: tope adaptativo con medidas automáticas (detalle + PDF). El valor
 *      del tope lo fija el usuario: aquí se lee del .scss y de la plantilla
 *      del PDF, se comprueba que lo compilado le siga la pista y que AMBOS
 *      toquen el mismo número.
 *   3. Balance: estado "Pendiente"/"Pagado" siempre visible y conmutable.
 *   4. Filtro por categoría sobre el Balance general.
 *   5. proveedor_entradas.categoria como entero apuntando a
 *      categorias_proveedor (catálogo con CRUD).
 *
 * Crea su propio proveedor con 2 entradas y lo borra al final (el borrado
 * es en cascada). No toca los datos del usuario.
 *
 * Uso: node pruebas/probar_ronda2.mjs
 */
import { chromium } from 'playwright';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { execFileSync } from 'node:child_process';
import { writeFileSync, unlinkSync, existsSync, readFileSync } from 'node:fs';

const BASE = 'http://127.0.0.1:8017';
/** Ruta del proyecto (para leer la fuente de verdad de los estilos). */
const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));  // esta carpeta está en <proyecto>/pruebas
// Los usuarios reales son del cliente: las pruebas entran con una cuenta
// propia que se crea al inicio y se borra al final (borrarla es lo último,
// porque `usuario_id` es RESTRICT y no dejaría huérfanos).
const EMAIL_PRUEBA = 'prueba.ronda@cotizaciones.local';
const CLAVE_PRUEBA = 'Prueba1234!';
const PROVEEDOR_PRUEBA = 'ZZ Proveedor de prueba balance';

let correctas = 0;
let fallos = 0;

function comprobar(nombre, condicion, detalle = '') {
  if (condicion) {
    correctas++;
    console.log(`  ✓ ${nombre}`);
  } else {
    fallos++;
    console.log(`  ✗ ${nombre}${detalle ? ` — ${detalle}` : ''}`);
  }
}

/** Consulta de una sola celda a la base local. */
const sql = (consulta) =>
  execFileSync('mysql', ['-u', 'root', 'cotizaciones', '-N', '-B', '-e', consulta], {
    encoding: 'utf8',
  }).trim();

/**
 * Tope de altura del logo, leído de la FUENTE (.scss) y de lo compilado.
 *
 * El valor lo fija el usuario (no el test): si cambia el tope, cambiar estas
 * dos comprobaciones tiene que seguir detectando un build viejo, que es el
 * fallo real. Devuelve los píxeles esperados (1rem = 16px) o null si el
 * tope no se encuentra.
 */
function topeDeLogo() {
  const scss = readFileSync(`${RAIZ}/src/scss/layout/_cotizador.scss`, 'utf8');
  const bloque = scss.match(/&__logo\s*\{([\s\S]*?)\n {2}\}/);
  const rem = bloque ? (bloque[1].match(/max-height:\s*([\d.]+)rem/) || [])[1] : null;
  if (!rem) return { fuente: null, compilado: null, actualizado: false };

  const css = existsSync(`${RAIZ}/build/css/app.css`)
    ? readFileSync(`${RAIZ}/build/css/app.css`, 'utf8')
    : '';
  const regla = css.match(/\.cotizador-detalle__logo\s*\{([\s\S]*?)\n\}/);
  const remCss = regla ? (regla[1].match(/max-height:\s*([\d.]+)rem/) || [])[1] : null;

  return {
    fuente: Number(rem) * 16,
    compilado: remCss === null ? null : Number(remCss) * 16,
    actualizado: remCss === rem,
  };
}

/**
 * Tope de altura del logo DENTRO del PDF, leído de la plantilla.
 *
 * Está en px (dompdf no resuelve el rem igual que el navegador) y el usuario
 * quiere que coincida con el de pantalla: aquí sólo se lee el valor, la
 * igualdad la comprueba el test.
 */
function topeDeLogoPdf() {
  const php = readFileSync(`${RAIZ}/templates/pdfCotizaciones.php`, 'utf8');
  const regla = php.match(/\.logo\s*\{[^}]*max-height:\s*([\d.]+)px/);
  return regla ? Number(regla[1]) : null;
}

async function entrar(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL_PRUEBA);
  await page.fill('#contrasena', CLAVE_PRUEBA);
  await Promise.all([
    page.waitForURL('**/admin**', { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
}

/** Alta de la cuenta de prueba (borrada al final) con hash bcrypt fresco. */
function prepararUsuarioPrueba() {
  // Un intento anterior interrumpido puede dejar la cuenta con cotizaciones
  // propias: como `cotizaciones.usuario_id` es RESTRICT, primero se borran
  // ÉSAS (sólo las suyas) y después el usuario.
  sql(
    `DELETE FROM cotizaciones WHERE usuario_id = ` +
      `(SELECT id FROM usuarios WHERE email = '${EMAIL_PRUEBA}')`
  );
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL_PRUEBA}'`);
  const hash = execFileSync(
    'php',
    ['-r', `echo password_hash(${JSON.stringify(CLAVE_PRUEBA)}, PASSWORD_BCRYPT);`],
    { encoding: 'utf8' }
  ).trim();
  sql(
    "INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) " +
      `VALUES ('Prueba de ronda', '${EMAIL_PRUEBA}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL_PRUEBA}'`));
}

/**
 * Crea SIEMPRE la cotización que se va a medir y devuelve `propia: true`.
 *
 * Antes reutilizaba la primera del histórico: si el usuario tenía varias
 * renglones, el PDF se medía contra el contenido de otra persona y "1
 * página" dejaba de significar algo. Con una cotización propia de un solo
 * renglón la prueba mide el encabezado y el logo, que es lo que se quiere
 * comprobar; se borra en el `finally`.
 */
async function prepararCotizacion(page) {
  await page.goto(`${BASE}/admin/cotizaciones/crear`, { waitUntil: 'domcontentloaded' });
  const datos = await page.evaluate(() => {
    const empresa = document.querySelector('select[name="empresa_id"]');
    const cliente = document.querySelector('select[name="cliente_id"]');
    const fecha = document.querySelector('input[name="fecha"]');
    const vigencia = document.querySelector('select[name="vigencia_dias"]');
    const producto = document.querySelector('[data-cotizador-producto]');
    const primerCliente = cliente
      ? [...cliente.options].find((op) => op.value !== '' && op.value !== '0')
      : null;
    if (!empresa || !primerCliente || !fecha || !vigencia || !producto) return null;
    return {
      empresa: empresa.value,
      cliente: primerCliente.value,
      fecha: fecha.value,
      vigencia: vigencia.value,
      producto: producto.getAttribute('data-id'),
      precio: producto.getAttribute('data-precio'),
    };
  });
  if (!datos) return { id: 0, propia: false };

  const clave = `lineas[${datos.producto}]`;
  const respuesta = await page.request.post(`${BASE}/admin/cotizaciones/crear`, {
    maxRedirects: 0,
    form: {
      empresa_id: datos.empresa,
      cliente_id: datos.cliente,
      fecha: datos.fecha,
      vigencia_dias: datos.vigencia,
      aplica_iva: '1',
      descuento_pct: '0',
      [`${clave}[cantidad]`]: '1',
      [`${clave}[precio]`]: datos.precio || '1',
      [`${clave}[descuento_pct]`]: '0',
    },
  });
  const destino = respuesta.headers()['location'] || '';
  const coincidencia = destino.match(/id=(\d+)/);
  return { id: coincidencia ? Number(coincidencia[1]) : 0, propia: Boolean(coincidencia) };
}

/** Descarga un PDF a disco temporal y devuelve su texto + nº de páginas. */
async function descargarPdf(page, ruta) {
  const respuesta = await page.request.get(`${BASE}${ruta}`);
  const tipo = respuesta.headers()['content-type'] || '';
  if (!respuesta.ok() || !tipo.includes('application/pdf')) {
    return { ok: false, status: respuesta.status(), tipo };
  }
  const temporal = `${tmpdir()}/pdf-${Date.now()}-${Math.random().toString(36).slice(2)}.pdf`;
  writeFileSync(temporal, Buffer.from(await respuesta.body()));
  let texto = '';
  let paginas = 0;
  let imagenes = '';
  try {
    texto = execFileSync('pdftotext', ['-layout', temporal, '-'], { encoding: 'utf8' });
    paginas = Number(
      execFileSync('pdfinfo', [temporal], { encoding: 'utf8' }).match(/^Pages:\s+(\d+)/m)?.[1] || 0
    );
    imagenes = execFileSync('pdfimages', ['-list', temporal], { encoding: 'utf8' });
  } catch (error) {
    texto = '';
  } finally {
    if (existsSync(temporal)) unlinkSync(temporal);
  }
  return { ok: true, texto, paginas, imagenes };
}

/**
 * Separación vertical media (en puntos) entre los renglones de la tabla del
 * catálogo: se localiza cada clave de producto por su texto exacto y se
 * promedian las distancias entre claves consecutivas de la misma página.
 */
function separacionRenglones(xml, skus) {
  const palabras = [];
  const expresion = /<word[^>]*yMin="([\d.]+)"[^>]*>([^<]*)<\/word>/g;
  let coincidencia;
  while ((coincidencia = expresion.exec(xml)) !== null) {
    const texto = coincidencia[2].trim();
    if (skus.includes(texto)) palabras.push({ y: Number(coincidencia[1]), t: texto });
  }
  if (palabras.length < 3) return { media: NaN, n: palabras.length };

  const ordenadas = [...palabras].sort((a, b) => a.y - b.y).map((p) => p.y);
  const saltos = [];
  for (let i = 1; i < ordenadas.length; i++) {
    const delta = ordenadas[i] - ordenadas[i - 1];
    if (delta > 2) saltos.push(delta); // claves repetidas en distintas páginas
  }
  const media = saltos.reduce((a, b) => a + b, 0) / (saltos.length || 1);
  return { media, n: palabras.length };
}

const navegador = await chromium.launch();
const page = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
let idPrueba = 0;
let usuarioPrueba = 0;
let cotizacionPropia = 0;

try {
  usuarioPrueba = prepararUsuarioPrueba();
  await entrar(page);

  /* ------------------------------------------------------------ 1. Catálogo */
  console.log('\n1. Catálogo: sin suma, leyenda por empresa y tabla compacta');
  const empresasActivas = Number(sql('SELECT COUNT(*) FROM empresas WHERE activa = 1'));
  const skus = sql(
    "SELECT IFNULL(NULLIF(sku, ''), '§') FROM productos ORDER BY id"
  ).split('\n').filter((s) => s && s !== '§');

  const catalogo = await descargarPdf(page, '/admin/productos/pdf');
  comprobar('responde como PDF', catalogo.ok, `status=${catalogo.status} tipo=${catalogo.tipo}`);

  if (catalogo.ok) {
    const plano = catalogo.texto.replace(/\s+/g, ' ');
    const planoMinus = plano.toLowerCase();

    comprobar('NO suma los costos', !planoMinus.includes('suma de costos'), 'aparece la suma');
    comprobar(
      'NO muestra el pie de totales',
      !/\d+\s+productos\s+\$/.test(plano),
      plano.match(/\d+\s+productos\s+[\$0-9,.]+/)?.[0] || ''
    );
    comprobar('conserva las 4 columnas', ['imagen', 'clave', 'nombre', 'costo']
      .every((c) => planoMinus.includes(c)));

    const leyes = (planoMinus.match(/los precios no incluyen iva/g) || []).length;
    comprobar(
      `una leyenda por empresa (${empresasActivas} activas)`,
      leyes === empresasActivas,
      `${leyes} leyendas`
    );

    // Segunda bajada: hace falta el XML con coordenadas para medir el salto
    // vertical entre renglones (la primera descarga ya se borró).
    const respuesta = await page.request.get(`${BASE}/admin/productos/pdf`);
    const temporal = `${tmpdir()}/catalogo-bbox.pdf`;
    writeFileSync(temporal, Buffer.from(await respuesta.body()));
    let bbox = '';
    try {
      bbox = execFileSync('pdftotext', ['-bbox', temporal, '-'], {
        encoding: 'utf8',
        maxBuffer: 1 << 28,
      });
    } catch (error) {
      bbox = '';
    } finally {
      if (existsSync(temporal)) unlinkSync(temporal);
    }

    const { media, n } = separacionRenglones(bbox, skus);
    console.log(`    · separación media entre renglones: ${
      Number.isFinite(media) ? media.toFixed(1) : 'n/a'
    } pt (${n} claves ubicadas, ${catalogo.paginas} páginas)`);
    comprobar('se localizaron claves en el PDF', n >= 3, `${n} claves`);
    // Antes de compactar la fila medía ~38 pt (40 px de miniatura + 0.3 rem);
    // ahora la miniatura baja a 30 px y el relleno a 0.12 rem (~21 pt).
    comprobar('renglones compactos (< 30 pt)', Number.isFinite(media) && media < 30,
      Number.isFinite(media) ? `media=${media.toFixed(1)}pt` : 'sin medición');
    comprobar('sigue cabiendo en el PDF', catalogo.paginas >= 1 && catalogo.paginas <= 3,
      `${catalogo.paginas} páginas`);
  }

  /* ---------------------------------------------------- 2. Logo adaptativo */
  const tope = topeDeLogo();
  console.log(`\n2. Logo con max-height ${tope.fuente ? `${tope.fuente}px` : '(no leído)'}`);
  const cotizacion = await prepararCotizacion(page);
  cotizacionPropia = cotizacion.propia ? cotizacion.id : 0;
  /** Tamaño natural del archivo: lo necesita la sección del PDF. */
  let naturalLogo = null;

  comprobar('el build recoge el max-height de la fuente .scss', tope.actualizado,
    `scss=${tope.fuente}px build=${tope.compilado}px`);

  if (cotizacion.id > 0) {
    await page.goto(`${BASE}/admin/cotizaciones/detalle?id=${cotizacion.id}`, {
      waitUntil: 'domcontentloaded',
    });
    // naturalHeight vale 0 hasta que el archivo termina de cargar: sin
    // esperar, la comparación contra el tamaño original se haría con un 0.
    await page
      .waitForFunction(
        () => {
          const img = document.querySelector('.cotizador-detalle__logo');
          return !!img && img.complete && img.naturalHeight > 0;
        },
        { timeout: 15000 }
      )
      .catch(() => {});
    const logo = await page.evaluate(() => {
      const img = document.querySelector('.cotizador-detalle__logo');
      if (!img) return null;
      const rect = img.getBoundingClientRect();
      const css = getComputedStyle(img);
      return {
        ancho: Math.round(rect.width * 10) / 10,
        alto: Math.round(rect.height * 10) / 10,
        naturalAlto: img.naturalHeight,
        maxHeight: css.maxHeight,
        maxWidth: css.maxWidth,
        widthCss: css.width,
        heightCss: css.height,
      };
    });
    comprobar('el logo se muestra en el detalle', logo !== null);
    if (logo) {
      comprobar('el tope calculado es el de la fuente', logo.maxHeight === `${tope.fuente}px`,
        `${logo.maxHeight} vs ${tope.fuente}px`);
      comprobar('respeta el tope sin pasarse', logo.alto <= tope.fuente + 1, `${logo.alto}px`);
      comprobar('se dibuja a tamaño visible (no colapsa)', logo.alto >= 50, `${logo.alto}px`);
      comprobar('la caja conserva la proporción del archivo',
        Math.abs(logo.ancho - logo.alto) <= 1, `${logo.ancho}x${logo.alto}`);
      // "width/height: auto" no se puede leer de getComputedStyle (devuelve
      // el valor usado, en px), así que se comprueba el EFECTO: el dibujo es
      // el archivo a su tamaño natural salvo por el tope, nunca una medida
      // fija ajena a la imagen.
      comprobar('medidas automáticas: escala sólo hasta el tope',
        Math.abs(logo.alto - Math.min(logo.naturalAlto, tope.fuente)) < 1,
        `${logo.alto}px (natural=${logo.naturalAlto}px, tope=${tope.fuente}px)`);
      comprobar('tope adaptativo en ancho (max-width: 100%)', logo.maxWidth === '100%', logo.maxWidth);
      naturalLogo = logo.naturalAlto || null;
    }
  } else {
    comprobar('hay una cotización para medir', false, 'no se pudo crear');
  }

  /* --------------------------------------------- 3. Logo del PDF de cotización */
  console.log('\n3. Logo del PDF de cotización');
  const pdfCot = await descargarPdf(page, `/admin/cotizaciones/pdf?id=${cotizacion.id}`);
  comprobar('el PDF se genera', pdfCot.ok, `status=${pdfCot.status}`);
  if (pdfCot.ok) {
    comprobar('sigue en 1 página', pdfCot.paginas === 1, `${pdfCot.paginas} páginas`);
    // pdfimages -list trae una cabecera cuyo número de columnas cambia entre
    // versiones de poppler (añade "object ID"), así que la posición de x-ppi
    // se lee de la cabecera y no se cuenta a mano.
    const lineas = pdfCot.imagenes.split('\n').filter((l) => l.trim() !== '');
    const cabecera = lineas.find((l) => l.trim().startsWith('page')) || '';
    const cols = cabecera.trim().split(/\s+/);
    const col = (nombre) => Math.max(0, cols.indexOf(nombre));
    const filas = lineas
      .filter((l) => /^\s*\d+\s+\d+\s+(image|smask|stencil)/.test(l))
      .map((l) => l.trim().split(/\s+/))
      .map((c) => ({
        pagina: Number(c[col('page')]),
        tipo: c[col('type')],
        ancho: Number(c[col('width')]),
        alto: Number(c[col('height')]),
        ppi: Number(c[col('x-ppi')]) || 1,
      }));
    // x-ppi dice a cuántos puntos se acabó imprimiendo cada imagen: de ahí
    // sale el tamaño impreso (ancho/alto en px ÷ ppi × 72).
    const portada = filas.filter((f) => f.pagina === 1 && f.tipo === 'image');
    const resumen = portada
      .map((f) => `${f.ancho}x${f.alto}@${f.ppi}ppi=${((f.alto / f.ppi) * 72).toFixed(0)}pt`)
      .join(' ');
    const mayor = [...portada].sort((a, b) => b.alto / b.ppi - a.alto / a.ppi)[0];
    const topePdf = topeDeLogoPdf();

    comprobar('el tope del PDF está en la plantilla', topePdf !== null, `${topePdf}`);
    comprobar('el PDF usa el MISMO tope que la pantalla', topePdf === tope.fuente,
      `${topePdf}px vs ${tope.fuente}px`);

    if (mayor && topePdf && naturalLogo) {
      const puntos = (mayor.alto / mayor.ppi) * 72;
      // px → pt en dompdf: 96 dpi sobre 72 dpi. El dibujo es el archivo a su
      // tamaño natural, salvo que el tope lo recorte — igual que en pantalla.
      const esperado = Math.min(naturalLogo, topePdf) * 0.75;
      comprobar('el logo del PDF respeta el tope', Math.abs(puntos - esperado) <= 4,
        `${puntos.toFixed(1)}pt · esperado ${esperado}pt · ${resumen}`);
      comprobar('la marca sigue siendo visible en el documento', puntos >= 90,
        `${puntos.toFixed(1)}pt`);
    } else {
      comprobar('se encontró el logo en el PDF', false, resumen || 'sin imágenes');
    }
  }

  /* ------------------------------------------------- 4. Proveedor de prueba */
  console.log('\n4. Estado de pago y filtro por categoría');

  // Un intento anterior interrumpido puede dejar un proveedor con este nombre:
  // como el nombre no tiene restricción única, sin esta limpieza cada corrida
  // sumaría otro y `SELECT ... WHERE nombre = ...` devolvería el viejo.
  sql(`DELETE FROM proveedores WHERE nombre = '${PROVEEDOR_PRUEBA}'`);

  await page.request.post(`${BASE}/admin/proveedores/crear`, {
    maxRedirects: 0,
    form: {
      nombre: PROVEEDOR_PRUEBA,
      tipo_proveedor: 'Material',
      rfc: '',
      telefono: '',
      ubicacion: '',
    },
  });
  idPrueba = Number(sql(`SELECT id FROM proveedores WHERE nombre = '${PROVEEDOR_PRUEBA}'`)) || 0;
  comprobar('se creó el proveedor de prueba', idPrueba > 0, `id=${idPrueba}`);

  // El catálogo de categorías lo administra el usuario: crea, renombra y borra
  // lo que quiera. Aquí se leen de la base (no se fijan los ids 1 y 2) para que
  // una alta legítima del usuario no rompa la prueba.
  const listadoCategorias = sql(
    "SELECT IFNULL(GROUP_CONCAT(CONCAT(id, '=', nombre) ORDER BY id SEPARATOR '¦'), '') FROM categorias_proveedor"
  );
  const categoriasCatalogo = listadoCategorias
    ? listadoCategorias.split('¦').map((par) => {
        const corte = par.indexOf('=');
        return { id: par.slice(0, corte), nombre: par.slice(corte + 1) };
      })
    : [];
  comprobar('hay al menos dos categorías en el catálogo', categoriasCatalogo.length >= 2,
    JSON.stringify(categoriasCatalogo));
  const catA = categoriasCatalogo[0] || { id: '9999', nombre: 'Sin categoría' };
  const catB = categoriasCatalogo[1] || { id: '9998', nombre: 'Sin categoría' };

  const hoy = '2026-10-05';
  for (const [pedido, categoria, monto] of [
    ['PED-ACERO', catA.id, '100.00'],
    ['PED-CANA', catB.id, '200.00'],
  ]) {
    const r = await page.request.post(`${BASE}/admin/proveedores/entradas/crear`, {
      maxRedirects: 0,
      form: {
        proveedor_id: String(idPrueba),
        fecha: hoy,
        numero_pedido: pedido,
        categoria,
        monto,
        descripcion: 'Entrada de prueba para la ronda',
        notas: '',
      },
    });
    comprobar(`alta de entrada ${pedido} aceptada`, r.status() === 302, `status=${r.status()}`);
  }

  // Los ids se buscan por número de pedido, no por orden de inserción.
  const idDe = (pedido) =>
    Number(sql(
      `SELECT IFNULL(MAX(id), 0) FROM proveedor_entradas WHERE proveedor_id = ${idPrueba} AND numero_pedido = '${pedido}'`
    ));
  const idAcero = idDe('PED-ACERO');
  const idCana = idDe('PED-CANA');
  comprobar('hay dos entradas de prueba', idAcero > 0 && idCana > 0,
    `PED-ACERO=${idAcero} PED-CANA=${idCana}`);

  const leerEstados = async (query = '') => {
    await page.goto(`${BASE}/admin/proveedores/detalle?id=${idPrueba}${query}`, {
      waitUntil: 'domcontentloaded',
    });
    return page.evaluate(() => {
      const seccion = document.querySelector('section[aria-labelledby="titulo-balance"]');
      if (!seccion) return null;
      const filas = [...seccion.querySelectorAll('tbody tr')].map((f) => {
        const boton = f.querySelector('.tabla__pago button');
        // El texto del icono (ligadura de Material Symbols) también cae en
        // textContent: se quita para comparar sólo la etiqueta.
        const etiqueta = boton
          ? (() => {
              const copia = boton.cloneNode(true);
              copia.querySelectorAll('.material-symbols-outlined').forEach((n) => n.remove());
              return copia.textContent.replace(/\s+/g, ' ').trim();
            })()
          : '';
        return {
          pedido: f.querySelector('td:nth-child(1)')?.textContent.replace(/\s+/g, ' ').trim() || '',
          categoria: f.querySelector('td:nth-child(2)')?.textContent.replace(/\s+/g, ' ').trim() || '',
          estado: f.querySelector('.tabla__pago .badge')?.textContent.replace(/\s+/g, ' ').trim() || '',
          boton: etiqueta,
          clase: boton?.className || '',
        };
      });
      const stats = [...seccion.querySelectorAll('.rejilla-stats .stat')].map((s) =>
        s.textContent.replace(/\s+/g, ' ').trim()
      );
      const select = seccion.querySelector('#categoria-balance');
      return {
        filas,
        stats,
        encabezado: seccion.querySelector('header')?.textContent.replace(/\s+/g, ' ').trim() || '',
        vacio: seccion.querySelector('.texto-secundario')?.textContent.replace(/\s+/g, ' ').trim() || '',
        select: select ? { valor: select.value, opciones: select.options.length } : null,
        filtros: [...seccion.querySelectorAll('form[method="get"] button, form[method="get"] a')]
          .map((b) => b.textContent.replace(/\s+/g, ' ').trim()),
      };
    });
  };

  // --- a) Todo pendiente ------------------------------------------------
  let vista = await leerEstados();
  comprobar('la tabla lista las 2 entradas', vista?.filas.length === 2, `${vista?.filas.length}`);
  comprobar('ambas aparecen como "Pendiente"',
    vista?.filas.every((f) => f.estado === 'Pendiente'),
    vista?.filas.map((f) => `${f.pedido}:${f.estado}`).join(', '));
  comprobar('el botón verde dice "Pagado"',
    vista?.filas.every((f) => f.boton === 'Pagado' && f.clase.includes('btn--exito')));
  // El orden de la tabla no está garantizado (más reciente primero): se
  // compara como conjunto para que la prueba no dependa del orden.
  const categorias = (vista?.filas || []).map((f) => f.categoria).sort().join('|');
  comprobar('la categoría muestra el nombre de la lista',
    categorias === [catA.nombre, catB.nombre].sort().join('|'),
    `${categorias} (esperado: ${[catA.nombre, catB.nombre].sort().join('|')})`);

  // --- b) Marcar uno como pagado ---------------------------------------
  const rutaRetorno = `/admin/proveedores/detalle?id=${idPrueba}`;
  const marca = await page.request.post(`${BASE}/admin/proveedores/entradas/pago`, {
    maxRedirects: 0,
    form: { id: String(idAcero), pagado: '1', retorno: rutaRetorno },
  });
  comprobar('el cambio de estado responde 302', marca.status() === 302, `status=${marca.status()}`);
  comprobar('se guarda en la base', sql(`SELECT pagado FROM proveedor_entradas WHERE id=${idAcero}`) === '1');

  vista = await leerEstados();
  comprobar('la pagada dice "Pagado"',
    vista?.filas.find((f) => f.pedido === 'PED-ACERO')?.estado === 'Pagado',
    vista?.filas.map((f) => `${f.pedido}:${f.estado}`).join(', '));
  comprobar('la otra sigue en "Pendiente"',
    vista?.filas.find((f) => f.pedido === 'PED-CANA')?.estado === 'Pendiente');
  comprobar('el resumen marca 1 pedido pendiente', /1 pedido pendiente/.test(vista?.encabezado || ''),
    vista?.encabezado);
  comprobar('los indicadores cuadran (Pagado 100 / Pendiente 200)',
    /100\.00/.test(vista?.stats.join(' ')) && /200\.00/.test(vista?.stats.join(' ')),
    vista?.stats.join(' | '));

  // --- c) Filtro por categoría -----------------------------------------
  vista = await leerEstados(`&categoria=${catA.id}`);
  comprobar(`el filtro deja sólo la categoría ${catA.nombre}`, vista?.filas.length === 1,
    `${vista?.filas.length}`);
  comprobar('sólo aparece PED-ACERO', vista?.filas[0]?.pedido === 'PED-ACERO');
  comprobar('el select queda en la categoría elegida', vista?.select?.valor === catA.id,
    `valor=${vista?.select?.valor}`);
  comprobar('los indicadores se filtran junto con la tabla',
    /100\.00/.test(vista?.stats.join(' ')) && !/200\.00/.test(vista?.stats.join(' ')),
    vista?.stats.join(' | '));
  comprobar('la barra trae Buscar y Limpiar',
    (vista?.filtros || []).some((t) => t.includes('Buscar'))
      && (vista?.filtros || []).some((t) => t.includes('Limpiar')),
    (vista?.filtros || []).join(' / '));

  // Una categoría que este proveedor NO usa: se elige del catálogo, no un id
  // fijo, porque el usuario puede reordenar los suyos.
  const catAjena = categoriasCatalogo.find((c) => c.id !== catA.id && c.id !== catB.id)
    || { id: '9999' };
  vista = await leerEstados(`&categoria=${catAjena.id}`);
  comprobar('categoría vacía muestra aviso', /no tiene pedidos en la categoría/.test(vista?.vacio || ''),
    vista?.vacio.slice(0, 80));

  // --- d) Revertir el pago ---------------------------------------------
  const revierte = await page.request.post(`${BASE}/admin/proveedores/entradas/pago`, {
    maxRedirects: 0,
    form: { id: String(idAcero), retorno: rutaRetorno },
  });
  comprobar('se puede devolver a pendiente', sql(`SELECT pagado FROM proveedor_entradas WHERE id=${idAcero}`) === '0');

  /* ---------------------------------------- 5. Lista de entradas y filtros */
  console.log('\n5. Lista de entradas: búsqueda por nombre de categoría');
  await page.goto(`${BASE}/admin/proveedores/entradas?q=${encodeURIComponent(catA.nombre)}`,
    { waitUntil: 'domcontentloaded' });
  const buscadas = await page.evaluate(() => {
    const badge = document.querySelector('.tabla-toolbar .badge');
    return badge ? badge.textContent.replace(/\s+/g, ' ').trim() : '';
  });
  const enBase = Number(
    sql(`SELECT COUNT(*) FROM proveedor_entradas e JOIN proveedores p ON p.id = e.proveedor_id WHERE e.categoria = ${catA.id}`)
  );
  comprobar(`buscar "${catA.nombre}" encuentra las entradas de esa categoría`,
    buscadas.includes(String(enBase)), `${buscadas} / base=${enBase}`);

  // Un nombre que no está en el catálogo: se comprueba que la búsqueda no
  // devuelve nada, no que una categoría concreta esté vacía.
  await page.goto(`${BASE}/admin/proveedores/entradas?q=${encodeURIComponent('ZZZ-Ninguna-Parte')}`,
    { waitUntil: 'domcontentloaded' });
  const fantasma = await page.evaluate(() => ({
    filas: document.querySelectorAll('.tabla tbody tr').length,
  }));
  comprobar('una categoría que ya no existe no devuelve filas', fantasma.filas === 0,
    `${fantasma.filas} filas`);

  /* ------------------------------------------- 6. Campo de categoría (alta) */
  console.log('\n6. Categoría como entero con lista fija');
  const tipoColumna = sql("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'cotizaciones' AND TABLE_NAME = 'proveedor_entradas' AND COLUMN_NAME = 'categoria'");
  comprobar('la columna es TINYINT (no texto)', /^tinyint/.test(tipoColumna), tipoColumna);

  await page.goto(`${BASE}/admin/proveedores/entradas/crear`, { waitUntil: 'domcontentloaded' });
  const selector = await page.evaluate(() => {
    const sel = document.querySelector('select#categoria[name="categoria"]');
    if (!sel) return null;
    return {
      opciones: [...sel.options].map((o) => ({ valor: o.value, texto: o.textContent.trim() })),
    };
  });
  comprobar('el campo es un select', selector !== null);
  comprobar(`trae las ${categoriasCatalogo.length} categorías + "Sin categoría"`,
    selector?.opciones.length === categoriasCatalogo.length + 1,
    JSON.stringify(selector?.opciones));
  comprobar('los valores son enteros',
    selector?.opciones.every((o) => /^\d+$/.test(o.valor)), JSON.stringify(selector?.opciones));

  const rechazo = await page.request.post(`${BASE}/admin/proveedores/entradas/crear`, {
    maxRedirects: 0,
    form: {
      proveedor_id: String(idPrueba),
      fecha: hoy,
      numero_pedido: 'PED-INVALIDO',
      categoria: '99',
      monto: '10.00',
      descripcion: 'No debe guardarse',
      notas: '',
    },
  });
  const quedoInvalida = Number(
    sql(`SELECT COUNT(*) FROM proveedor_entradas WHERE numero_pedido = 'PED-INVALIDO'`)
  );
  comprobar('una categoría fuera de la lista se rechaza',
    rechazo.status() === 200 && quedoInvalida === 0,
    `status=${rechazo.status()} filas=${quedoInvalida}`);
  if (rechazo.status() === 200) {
    const cuerpo = await rechazo.text();
    comprobar('explica el error de categoría',
      cuerpo.includes('La categoría seleccionada no es válida.'));
  }

  const ficha = await page.request.get(`${BASE}/admin/proveedores/entradas/detalle?id=${idAcero}`);
  const cuerpoFicha = await ficha.text();
  comprobar('la ficha muestra el nombre de la categoría', cuerpoFicha.includes(catA.nombre),
    catA.nombre);

  /* -------------------------------------------------------- 7. Regresiones */
  console.log('\n7. Los demás PDF siguen respondiendo');
  for (const ruta of ['/admin/proveedores/pdf', '/admin/inventario/pdf', '/admin/productos/pdf']) {
    const r = await page.request.get(`${BASE}${ruta}`);
    comprobar(`${ruta} sigue en PDF`, r.ok() && (r.headers()['content-type'] || '').includes('application/pdf'),
      `status=${r.status()} ${r.headers()['content-type']}`);
  }

  /* --------------------------------------------------- 8. Sin desbordes */
  console.log('\n8. Sin desbordamiento horizontal');
  for (const ruta of [
    `/admin/proveedores/detalle?id=${idPrueba}&categoria=${catA.id}`,
    '/admin/proveedores/entradas',
    '/admin/productos',
    // La cotización se borra en el `finally`, así que aquí sigue viva.
    `/admin/cotizaciones/detalle?id=${cotizacion.id}`,
  ]) {
    for (const ancho of [1440, 390]) {
      await page.setViewportSize({ width: ancho, height: 900 });
      await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(200);
      const m = await page.evaluate(() => ({
        scroll: document.documentElement.scrollWidth,
        inner: window.innerWidth,
      }));
      comprobar(`${ruta} @${ancho}px sin desborde`, m.scroll <= m.inner,
        `scrollWidth=${m.scroll} innerWidth=${m.inner}`);
    }
  }
  await page.setViewportSize({ width: 1440, height: 900 });

} catch (error) {
  fallos++;
  console.log(`  ✗ error inesperado — ${error.message}`);
} finally {
  // Limpieza: el borrado del proveedor arrastra sus entradas (FK en cascada).
  try {
    if (idPrueba > 0) {
      sql(`DELETE FROM proveedores WHERE id = ${idPrueba}`);
      const resto = Number(
        sql(`SELECT COUNT(*) FROM proveedor_entradas WHERE proveedor_id = ${idPrueba}`)
      );
      comprobar('las entradas de prueba desaparecieron en cascada', resto === 0, `quedan ${resto}`);

      const proveedorResta = Number(
        sql(`SELECT COUNT(*) FROM proveedores WHERE id = ${idPrueba}`)
      );
      comprobar('el proveedor de prueba se eliminó', proveedorResta === 0, `${proveedorResta}`);
    }

    if (cotizacionPropia > 0) {
      sql(`DELETE FROM cotizaciones WHERE id = ${cotizacionPropia}`);
    }

    // Último: la cuenta de prueba sólo puede borrarse cuando ya no tiene
    // entradas ni cotizaciones (las FK son RESTRICT).
    if (usuarioPrueba > 0) {
      sql(`DELETE FROM usuarios WHERE id = ${usuarioPrueba}`);
      const usuarioResta = Number(
        sql(`SELECT COUNT(*) FROM usuarios WHERE id = ${usuarioPrueba}`)
      );
      comprobar('la cuenta de prueba se eliminó', usuarioResta === 0, `${usuarioResta}`);
    }
  } catch (error) {
    fallos++;
    console.log(`  ✗ no se pudo limpiar — ${error.message}`);
  }

  await navegador.close();
  console.log(`\nResultado: ${correctas} correctas · ${fallos} fallos`);
  process.exit(fallos === 0 ? 0 : 1);
}

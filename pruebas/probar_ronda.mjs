/**
 * probar_ronda.mjs — Verificación de la ronda:
 *   1. Botón "Exportar catálogo de productos" junto a "Nuevo producto".
 *   2. Logo del detalle y del PDF respetando el tope de medida. El tope lo
 *      fija el usuario: aquí se lee del .scss y de la plantilla del PDF,
 *      se comprueba que lo compilado le siga la pista y que la imagen
 *      dibujada no lo rebasen (nunca se compara contra un número fijo).
 *   3. Botón "Exportar datos" a la izquierda de "Entradas".
 *   4. Expediente del proveedor (compras y saldos) y, si existe, su historial de entregas anteriores con el botón de pago.
 *   5. Contador de "Pendiente por pagar" en Entradas.
 *   6. Campo "Monto" en el formulario de entrada.
 *   7. Ninguna pantalla con desbordamiento horizontal (1440 y 390 px).
 *   10. Panel: tarjetas con los productos más vendidos del mes (código, nombre, imagen e
 *       importe de venta) y con las existencias bajas (negativas, agotadas o bajo el mínimo).
 *   11. Panel: el orden del «Resumen general» (negocios → impuestos → pendientes →
 *       productos → últimos documentos → indicadores), las ventas de cada negocio
 *       como única cifra grande (sin ganancias), el preliminar con los impuestos por
 *       pagar en la misma tarjeta, los cuatro listados de pendientes con su acción
 *       directa y el PDF de las últimas cinco cotizaciones y ventas.
 *
 * Uso: node pruebas/probar_ronda.mjs
 */
import { chromium } from 'playwright';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { execFileSync } from 'node:child_process';
import { writeFileSync, unlinkSync, existsSync, readFileSync } from 'node:fs';

/** Las pruebas que mandan POST «a mano» deben llevar la llave anti-falsificación (CSRF) de la sesión, como lo hace el navegador. */
async function postCsrf(pagina, url, opciones = {}) {
  const token = await pagina.evaluate(() => (document.querySelector('meta[name="csrf"]') || {}).content || '');
  return pagina.request.post(url, { ...opciones, headers: { ...(opciones.headers || {}), 'X-CSRF-Token': token } });
}


const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
/** Ruta del proyecto, para leer la fuente de verdad de las medidas. */
const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));  // esta carpeta está en <proyecto>/pruebas
// Los usuarios reales son del cliente: esta prueba entra con una cuenta
// propia que se crea al inicio y se borra al FINAL, porque las FK de
// `cotizaciones.usuario_id` son RESTRICT y no dejarían huérfanos.
const EMAIL = 'prueba.ronda14@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const sql = (consulta) =>
  execFileSync('mysql', ['-u', 'root', (process.env.DB_NAME || 'cotizaciones'), '-N', '-B', '-e', consulta], {
    encoding: 'utf8',
  }).trim();

/** Alta de la cuenta de prueba con un hash bcrypt fresco. */
function prepararUsuarioPrueba() {
  // Un intento anterior interrumpido puede dejar la cuenta con cotizaciones
  // propias: como `cotizaciones.usuario_id` es RESTRICT, se borran éSAS
  // (sólo las suyas) antes que el usuario.
  sql(
    `DELETE FROM cotizaciones WHERE usuario_id = ` +
      `(SELECT id FROM usuarios WHERE email = '${EMAIL}')`
  );
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
  const hash = execFileSync(
    'php',
    ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`],
    { encoding: 'utf8' }
  ).trim();
  sql(
    "INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) " +
      `VALUES ('Prueba de ronda', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

/**
 * Gasto con el pago PENDIENTE para ver la tarjeta del panel con datos reales
 * (nombre de quién cobra + total con IVA). Se borra apenas se comprueba.
 */
function crearGastoPendiente(usuarioId) {
  const contribuyente = Number(sql('SELECT MIN(id) FROM contribuyentes WHERE activo = 1'));
  sql(
    `INSERT INTO gastos (contribuyente_id, fecha, beneficiario, concepto, subtotal, iva, total, usuario_id)
     VALUES (${contribuyente}, CURDATE(), 'Proveedor Ronda Panel', 'Material de la ronda', 100.00, 16.00, 116.00, ${usuarioId})`
  );
  return Number(sql(`SELECT id FROM gastos WHERE beneficiario = 'Proveedor Ronda Panel' ORDER BY id DESC LIMIT 1`));
}

/** Incidencia de entrega ABIERTA sobre una venta registrada, para ver ese listado con datos. */
function crearIncidenciaAbierta(usuarioId) {
  const venta = Number(sql(`SELECT id FROM ventas WHERE estado = 'registrada' ORDER BY id LIMIT 1`));
  if (!venta) return 0;
  sql(
    `INSERT INTO entrega_incidencias (venta_id, motivo, estado, notas, usuario_id)
     VALUES (${venta}, 'producto_danado', 'abierta', 'Incidencia de la ronda', ${usuarioId})`
  );
  return Number(sql(`SELECT id FROM entrega_incidencias WHERE notas = 'Incidencia de la ronda' ORDER BY id DESC LIMIT 1`));
}

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

/**
 * Tope de altura del logo en PANTALLA, leído de la fuente (.scss) y de lo
 * compilado. Devuelve píxeles (1rem = 16px) o null si no aparece.
 *
 * El valor lo fija el usuario: si cambia el tope, estas comprobaciones
 * tienen que seguir detectando un build viejo, que es el fallo real.
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
 * Tope de altura del logo DENTRO del PDF, leído de la plantilla (en px,
 * porque dompdf no resuelve el rem igual que el navegador).
 */
function topeDeLogoPdf() {
  // El tamaño lo calcula Imagenes::logoPdf dentro de una caja por empresa; el tope absoluto es esta constante.
  const php = readFileSync(`${RAIZ}/clases/Imagenes.php`, 'utf8');
  const regla = php.match(/LOGO_PDF_ALTO_MAX\s*=\s*(\d+)/);
  return regla ? Number(regla[1]) : null;
}

async function entrar(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#contrasena', CLAVE);
  await Promise.all([
    page.waitForURL('**/admin**', { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
}

/**
 * Devuelve el id de una cotización para medir su logo.
 *
 * Si el histórico está vacío (que lo suele estar: el usuario borra lo suyo),
 * la prueba **crea la suya** y devuelve `propia: true` para que el bloque
 * final la borre. Nunca se toca una cotización existente.
 */
async function prepararCotizacion(page) {
  await page.goto(`${BASE}/admin/cotizaciones`, { waitUntil: 'domcontentloaded' });
  const existente = await page.evaluate(() => {
    const enlace = document.querySelector('a[href*="/admin/cotizaciones/detalle?id="]');
    return enlace ? Number(new URL(enlace.href).searchParams.get('id')) : 0;
  });
  if (existente > 0) {
    return { id: existente, propia: false };
  }

  // Histórico vacío → alta mínima (1 renglón de 1 unidad).
  await page.goto(`${BASE}/admin/cotizaciones/crear`, { waitUntil: 'domcontentloaded' });
  const datos = await page.evaluate(() => {
    const empresa = document.querySelector('select[name="empresa_id"]');
    const cliente = document.querySelector('select[name="cliente_id"]');
    const fecha   = document.querySelector('input[name="fecha"]');
    const vigencia= document.querySelector('select[name="vigencia_dias"]');
    const producto = document.querySelector('[data-cotizador-producto]');
    // La primera opción del selector de clientes es el marcador "Seleccione…": si
    // se manda vacío el servidor responde 200 con el formulario, no un 302.
    const primerCliente = cliente
      ? [...cliente.options].find((op) => op.value !== '' && op.value !== '0')
      : null;
    if (!empresa || !primerCliente || !fecha || !vigencia || !producto) return null;
    return {
      empresa:  empresa.value,
      cliente:  primerCliente.value,
      fecha:    fecha.value,
      vigencia: vigencia.value,
      producto: producto.getAttribute('data-id'),
      precio:   producto.getAttribute('data-precio'),
    };
  });
  if (!datos) {
    return { id: 0, propia: false };
  }

  const clave = `lineas[${datos.producto}]`;
  const respuesta = await postCsrf(page, `${BASE}/admin/cotizaciones/crear`, {
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

/** Comprueba que la página no desborda horizontalmente. */
async function sinDesborde(page, ruta, ancho) {
  await page.setViewportSize({ width: ancho, height: 900 });
  await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(250);
  const medidas = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    inner: window.innerWidth,
  }));
  comprobar(
    `${ruta} @${ancho}px sin desborde`,
    medidas.scroll <= medidas.inner,
    `scrollWidth=${medidas.scroll} innerWidth=${medidas.inner}`
  );
}

const navegador = await chromium.launch();
const page = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
let cotizacionPropia = 0;
let usuarioPrueba = 0;
let gastoPrueba = 0;
let incidenciaPrueba = 0;

try {
  usuarioPrueba = prepararUsuarioPrueba();
  await entrar(page);

  /* ---------------------------------------------------------------- 1. Catálogo */
  console.log('\n1. Exportar catálogo de productos');
  await page.goto(`${BASE}/admin/productos`, { waitUntil: 'domcontentloaded' });

  const textoAcciones = await page.evaluate(() => {
    const caja = document.querySelector('.encabezado-pagina__acciones');
    return caja ? caja.textContent : '';
  });
  comprobar('el botón existe', textoAcciones.includes('Exportar catálogo de productos'));
  comprobar('está al lado de "Nuevo producto"', textoAcciones.includes('Nuevo producto'));
  comprobar(
    'queda a la izquierda de "Nuevo producto"',
    textoAcciones.indexOf('Exportar catálogo') < textoAcciones.indexOf('Nuevo producto'),
    textoAcciones.replace(/\s+/g, ' ').trim()
  );

  /* ------------------------------------------------------- 2. Logo ×2 (detalle) */
  console.log('\n2. Logo de la cotización a doble tamaño');
  // Se reutiliza una cotización existente o se crea la propia (y se borra al final).
  const cotizacion = await prepararCotizacion(page);
  cotizacionPropia = cotizacion.propia ? cotizacion.id : 0;
  const idCotizacion = cotizacion.id;

  if (idCotizacion > 0) {
    await page.goto(`${BASE}/admin/cotizaciones/detalle?id=${idCotizacion}`, { waitUntil: 'domcontentloaded' });
    const caja = await page.evaluate(() => {
      const img = document.querySelector('.cotizador-detalle__logo');
      if (!img) return null;
      const rect = img.getBoundingClientRect();
      const cajaPadre = img.parentElement ? img.parentElement.getBoundingClientRect() : rect;
      const r2 = (n) => Math.round(n * 10) / 10;
      return {
        ancho: r2(rect.width),
        alto: r2(rect.height),
        contenedor: r2(cajaPadre.width),
      };
    });
    comprobar('el logo se muestra en el detalle', caja !== null);

    if (caja) {
      // La medida esperada no es un número fijo: sale del .scss (el tope
      // que fija el usuario) y de lo compilado, que es donde vive el fallo
      // real (un build viejo con el tope anterior).
      const tope = topeDeLogo();
      comprobar('el tope está declarado en el .scss', tope.fuente !== null, `${tope.fuente}px`);
      comprobar(
        'el CSS compilado sigue la fuente',
        tope.actualizado,
        `scss=${tope.fuente}px compilado=${tope.compilado}px`
      );
      comprobar(
        `el alto dibujado (${caja.alto}px) no rebasa el tope (${tope.fuente}px)`,
        tope.fuente !== null && caja.alto <= tope.fuente + 1
      );
      comprobar(
        `el ancho (${caja.ancho}px) cabe en su contenedor (${caja.contenedor}px)`,
        caja.ancho <= caja.contenedor + 1
      );
      comprobar('el logo tiene alto real (no colapsado)', caja.alto > 0, `${caja.alto}px`);
    }
  } else {
    comprobar('hay una cotización para medir', false, 'el histórico está vacío');
  }

  /* --------------------------------------------------------- 3. Botón proveedores */
  console.log('\n3. Exportar datos / Entradas');
  await page.goto(`${BASE}/admin/proveedores`, { waitUntil: 'domcontentloaded' });
  const accionesProv = await page.evaluate(() => {
    const caja = document.querySelector('.encabezado-pagina__acciones');
    return caja ? caja.textContent : '';
  });
  comprobar('existe "Exportar datos"', accionesProv.includes('Exportar datos'));
  comprobar(
    'queda a la izquierda de "Entradas"',
    accionesProv.indexOf('Exportar datos') < accionesProv.indexOf('Entradas'),
    accionesProv.replace(/\s+/g, ' ').trim()
  );

  /* ------------------------------------------------------- 4. Expediente */
  console.log('\n4. Expediente del proveedor y entregas anteriores');
  const idProveedor = await page.evaluate(async () => {
    const html = await (await fetch('/admin/proveedores')).text();
    const coincidencia = html.match(/\/admin\/proveedores\/detalle\?id=(\d+)/);
    return coincidencia ? Number(coincidencia[1]) : 0;
  });

  comprobar('hay al menos un proveedor', idProveedor > 0);
  if (idProveedor > 0) {
    await page.goto(`${BASE}/admin/proveedores/detalle?id=${idProveedor}`, { waitUntil: 'domcontentloaded' });
    const expediente = await page.textContent('body');
    comprobar('el expediente trae las compras y los saldos', expediente.includes('Saldo pendiente') && expediente.includes('Compras'));
    comprobar('y las recepciones, facturas y pagos del proveedor',
      expediente.includes('Pendiente de recibir') && expediente.includes('Facturas pendientes') && expediente.includes('Pagos'));
  }

  // Historial del registro fotográfico anterior: solo se muestra si el proveedor tiene entregas anteriores.
  const idConHistorial = Number(sql('SELECT IFNULL(MIN(proveedor_id), 0) FROM proveedor_entradas'));
  if (idConHistorial > 0) {
    await page.goto(`${BASE}/admin/proveedores/detalle?id=${idConHistorial}`, { waitUntil: 'domcontentloaded' });
    const texto = await page.textContent('body');
    comprobar('la sección de entregas anteriores existe', texto.includes('Entregas anteriores (historial)'));
    comprobar('trae el resumen de pagos', texto.includes('Pendiente por pagar'));

    const encabezados = await page.$$eval('section[aria-labelledby="titulo-balance"] thead th', (nodos) =>
      nodos.map((n) => n.textContent.trim())
    );
    comprobar(
      'columnas: pedido, categoría, monto, imagen, pago, acciones',
      encabezados.join('|').includes('Número de pedido')
        && encabezados.join('|').includes('Monto')
        && encabezados.join('|').includes('Imagen de entrega')
        && encabezados.join('|').includes('Pago')
        && encabezados.join('|').includes('Acciones'),
      encabezados.join(', ')
    );

    const botonesPago = await page.$$('section[aria-labelledby="titulo-balance"] form.tabla__pago');
    comprobar('cada entrega trae su botón de pago', botonesPago.length > 0, `${botonesPago.length} botones`);

    const selectores = await page.$$('section[aria-labelledby="titulo-balance"] form.tabla__pago select[name="pagado"]');
    comprobar('cada entrega tiene su selector de estado de pago', selectores.length === botonesPago.length, `${selectores.length} selectores`);

    const accionesFila = await page.$$eval(
      'section[aria-labelledby="titulo-balance"] tbody .tabla__acciones',
      (nodos) => nodos.length
    );
    comprobar('hay acciones por fila', accionesFila > 0, `${accionesFila} filas`);
  } else {
    console.log('  (sin entregas anteriores en esta base: no hay historial que verificar)');
  }

  /* ---------------------------------------------------------- 5. Contador */
  console.log('\n5. Contador de pendientes en Entradas');
  await page.goto(`${BASE}/admin/proveedores/entradas`, { waitUntil: 'domcontentloaded' });
  const contador = await page.evaluate(() => {
    const nodo = document.querySelector('.tabla-toolbar__resumen .badge--pendiente');
    return nodo ? nodo.textContent.replace(/\s+/g, ' ').trim() : '';
  });
  comprobar('muestra "Pendiente por pagar"', contador.includes('Pendiente por pagar'), contador);
  comprobar('muestra un importe', /\$\s?[\d,.]+/.test(contador), contador);

  /* ------------------------------------------------------ 6. Campo monto */
  console.log('\n6. Campo Monto en la entrada');
  await page.goto(`${BASE}/admin/proveedores/entradas/crear`, { waitUntil: 'domcontentloaded' });
  const campoMonto = await page.$('input#monto[name="monto"]');
  comprobar('existe el campo Monto', campoMonto !== null);
  const etiquetaMonto = campoMonto ? await campoMonto.evaluate((n) => {
    const etiqueta = document.querySelector('label[for="monto"]');
    return etiqueta ? etiqueta.textContent.trim() : '';
  }) : '';
  comprobar('se llama "Monto"', etiquetaMonto === 'Monto', etiquetaMonto);

  /* ------------------------------------------------ 7. PDF de los dos reportes */
  console.log('\n7. PDF de catálogo y de proveedores');
  for (const [ruta, titulo, busqueda] of [
    ['/admin/productos/pdf', 'Catálogo de productos', 'Los precios no incluyen iva (16%), las ventas son con pago de contado.'],
    ['/admin/proveedores/pdf', 'Entradas de proveedores', 'Pendiente por pagar'],
  ]) {
    const respuesta = await page.request.get(`${BASE}${ruta}`);
    const tipo = respuesta.headers()['content-type'] || '';
    comprobar(`${ruta} responde PDF`, respuesta.ok() && tipo.includes('application/pdf'), `status=${respuesta.status()} tipo=${tipo}`);

    if (respuesta.ok()) {
      const rutaTmp = `${tmpdir()}/salida-${Math.abs(ruta.length)}-${Date.now()}.pdf`;
      writeFileSync(rutaTmp, Buffer.from(await respuesta.body()));
      let texto = '';
      try {
        texto = execFileSync('pdftotext', ['-layout', rutaTmp, '-'], { encoding: 'utf8' });
      } catch (error) {
        texto = '';
      } finally {
        if (existsSync(rutaTmp)) unlinkSync(rutaTmp);
      }
      const plano = texto.replace(/\s+/g, ' ');
      comprobar(`contiene "${titulo}"`, plano.includes(titulo));
      // Comparación sin distinguir mayúsculas: los encabezados de tabla
      // se pintan con text-transform: uppercase y pdftotext extrae tal cual.
      const planoNormal = plano.toLowerCase();
      const buscarNormal = busqueda.replace(/\s+/g, ' ').toLowerCase();
      // Sin entradas en el año, el PDF muestra el aviso vacío en lugar del resumen.
      comprobar('trae la leyenda / columna pedida', planoNormal.includes(buscarNormal) || (ruta === '/admin/proveedores/pdf' && planoNormal.includes('no hay entradas registradas')), buscarNormal);
    }
  }

  /* ------------------------------------------- 8. Medida del logo en el PDF */
  console.log('\n8. Logo del PDF');
  const idParaPdf = idCotizacion;

  if (idParaPdf > 0) {
    const respuesta = await page.request.get(`${BASE}/admin/cotizaciones/pdf?id=${idParaPdf}`);
    const rutaPdf = `${tmpdir()}/logo-ronda.pdf`;
    writeFileSync(rutaPdf, Buffer.from(await respuesta.body()));

    let filas = '';
    try {
      filas = execFileSync('pdfimages', ['-list', rutaPdf], { encoding: 'utf8' });
    } catch (error) {
      filas = '';
    } finally {
      if (existsSync(rutaPdf)) unlinkSync(rutaPdf);
    }

    // El logo se incrusta como miniatura 300×300: x-ppi revela su tamaño
    // dibujado (puntos) = ancho_px * 72 / x-ppi.
    const coincidencia = filas.split('\n').find((l) => /^\s*\d+\s+\d+\s+image\s+300\s+\d+\s/.test(l));
    comprobar('el logo aparece en el PDF', Boolean(coincidencia), filas.trim().split('\n').slice(0, 4).join(' | '));

    if (coincidencia) {
      // pdfimages -list: page num type width height color comp bpc enc interp
      //                   object ID x-ppi y-ppi size ratio
      const partes = coincidencia.trim().split(/\s+/);
      const anchoPx = Number(partes[3]);
      const altoPx = Number(partes[4]);
      const xPpi = Number(partes[partes.length - 4]);
      const yPpi = Number(partes[partes.length - 3]);
      // Un punto = 1/72 pulgada; x-ppi es la densidad con la que dompdf
      // incrustó la imagen, de ahí sale el tamaño dibujado.
      const anchoPt = (anchoPx * 72) / xPpi;
      const altoPt = (altoPx * 72) / yPpi;

      const topePdf = topeDeLogoPdf();
      const topePantalla = topeDeLogo();

      comprobar(
        'el tope máximo del logo del PDF está declarado',
        topePdf !== null,
        `${topePdf}px`
      );
      comprobar(
        'el tope del PDF no supera al de la pantalla (8rem = 128px)',
        topePdf !== null && topePantalla.fuente !== null && topePdf <= topePantalla.fuente,
        `pantalla=${topePantalla.fuente}px pdf=${topePdf}px`
      );
      comprobar(
        `la altura dibujada (${altoPt.toFixed(1)}pt) no rebasa el tope`,
        topePdf !== null && altoPt <= topePdf * 0.75 + 1,
        `${altoPt.toFixed(1)}pt vs ${(topePdf * 0.75).toFixed(1)}pt`
      );
      comprobar(
        'el logo se dibuja con tamaño real',
        anchoPt > 0 && altoPt > 0,
        `${anchoPt.toFixed(1)}×${altoPt.toFixed(1)}pt`
      );
    }
  } else {
    comprobar('hay cotización para exportar', false);
  }

  /* ------------------------------------------------------- 9. Desbordamiento */
  console.log('\n9. Desbordamiento horizontal');
  const pantallas = [
    '/admin/productos',
    '/admin/proveedores',
    '/admin/proveedores/entradas',
    '/admin/proveedores/entradas/crear',
  ];
  if (idProveedor > 0) pantallas.push(`/admin/proveedores/detalle?id=${idProveedor}`);
  if (idCotizacion > 0) pantallas.push(`/admin/cotizaciones/detalle?id=${idCotizacion}`);

  for (const ruta of pantallas) {
    await sinDesborde(page, ruta, 1440);
    await sinDesborde(page, ruta, 390);
  }

  /* ------------------------------------------------------------- 10. Panel */
  console.log('\n10. Panel: productos más vendidos y existencias bajas');
  await page.goto(BASE + '/admin');
  const seccionTop = page.locator('section[aria-labelledby="titulo-mas-vendidos"]');
  comprobar(
    'el panel anuncia los productos más vendidos del mes',
    /Productos más vendidos/.test(await seccionTop.locator('h2').innerText())
  );
  const tarjetasTop = seccionTop.locator('.stat--producto');
  const nTop = await tarjetasTop.count();
  if (nTop > 0) {
    const textoTop = (await tarjetasTop.first().innerText()).replace(/\s+/g, ' ');
    comprobar('salen hasta 4 tarjetas de productos más vendidos', nTop >= 1 && nTop <= 4, `${nTop}`);
    comprobar('la tarjeta trae el importe de venta y las piezas', textoTop.includes('$') && /pieza/.test(textoTop), textoTop);
    comprobar('la tarjeta abre el producto', (await tarjetasTop.first().getAttribute('href') || '').includes('/admin/productos/editar?id='));
  } else {
    comprobar('sin ventas del mes se muestra el estado vacío', (await seccionTop.innerText()).includes('No hay ventas de productos'));
  }

  const seccionStock = page.locator('section[aria-labelledby="titulo-stock-bajo"]');
  const tarjetasStock = seccionStock.locator('.stat--producto');
  const nStock = await tarjetasStock.count();
  const cifrasEnlace = (await seccionStock.locator('a.btn--texto').innerText()).replace(/\D+/g, '');
  const totalStock = cifrasEnlace === '' ? 0 : Number(cifrasEnlace);
  comprobar(
    'las tarjetas de existencias bajas muestran todas las que caben',
    nStock === Math.min(8, totalStock),
    `${nStock} de ${totalStock}`
  );
  if (nStock > 0) {
    const textoStock = (await tarjetasStock.first().innerText()).replace(/\s+/g, ' ');
    comprobar('la tarjeta de stock dice por qué está en alerta', /Agotado|negativa|mínimo/.test(textoStock), textoStock);
    comprobar('la tarjeta de stock lleva al inventario a buscarlo', (await tarjetasStock.first().getAttribute('href') || '').includes('/admin/inventario?q='));
    // El número va en rojo cuando ya no hay existencias y en naranja cuando quedan pocas.
    const cifras = await tarjetasStock.evaluateAll((tarjetas) => {
      const rgb = (valor) => {
        const t = valor.trim();
        if (!t.startsWith('#')) { return t; }
        const h = t.length === 3 ? t.split('').map((c) => c + c).join('') : t.slice(1);
        const n = parseInt(h, 16);
        return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
      };
      const tema = getComputedStyle(document.documentElement);
      return tarjetas.map((t) => ({
        rojo: rgb(tema.getPropertyValue('--color-error')),
        naranja: rgb(tema.getPropertyValue('--color-advertencia')),
        cifra: getComputedStyle(t.querySelector('.stat__valor')).color,
        pie: t.querySelector('.stat__pie').textContent,
      }));
    });
    comprobar(
      'la cifra sale en rojo sin existencias y en naranja con pocas',
      cifras.every((c) => (/Agotado|negativa/.test(c.pie) ? c.cifra === c.rojo : c.cifra === c.naranja)),
      JSON.stringify(cifras)
    );
  } else {
    comprobar('sin productos en alerta se avisa', (await seccionStock.innerText()).includes('Ningún producto'));
  }

  /* --------------------------------------- 11. Resumen general del panel */
  console.log('\n11. Panel: resumen general, pendientes y últimos documentos');
  // Un gasto con el pago pendiente y una incidencia abierta para ver esos
  // listados con datos reales; se borran apenas se comprueban para no
  // influir en las demás pruebas.
  gastoPrueba = crearGastoPendiente(usuarioPrueba);
  incidenciaPrueba = crearIncidenciaAbierta(usuarioPrueba);

  await page.goto(BASE + '/admin', { waitUntil: 'domcontentloaded' });

  const panel = await page.evaluate(() => {
    const seccion = (id) => document.querySelector(`section[aria-labelledby="${id}"]`);
    const texto = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : '');
    const href = (el) => (el ? el.getAttribute('href') || '' : '');
    /** Tarjetas de una sección: título, enlaces de su cabecera, filas y acciones de fila. */
    const tarjetas = (id) =>
      [...(seccion(id) ? seccion(id).querySelectorAll('article') : [])].map((c) => ({
        titulo: texto(c.querySelector('.tarjeta__titulo')),
        cabecera: [...c.querySelectorAll('.tarjeta__cabecera a')].map((a) => href(a)),
        filas: [...c.querySelectorAll('.actividad__fila')].map((f) => texto(f)),
        acciones: [...c.querySelectorAll('li a.btn')].map((b) => href(b)),
      }));
    const cuerpo = texto(document.body);

    return {
      orden: [...document.querySelectorAll('main section[aria-labelledby]')].map((s) => s.getAttribute('aria-labelledby')),
      negociosTitulo: texto(seccion('titulo-negocios') ? seccion('titulo-negocios').querySelector('h2') : null),
      negocios: [...(seccion('titulo-negocios') ? seccion('titulo-negocios').querySelectorAll('article.ganancia') : [])].map((n) => ({
        etiqueta: texto(n.querySelector('.ganancia__etiqueta')),
        cifra: texto(n.querySelector('.ganancia__valor')),
        sinGanancias: !texto(n).includes('Ganancias'),
        enlace: href(n.querySelector('a[href*="/admin/ventas?empresa="]')),
      })),
      impuestos: (() => {
        const tarjeta = seccion('titulo-impuestos') ? seccion('titulo-impuestos').querySelector('article') : null;
        return {
          titulo: texto(tarjeta ? tarjeta.querySelector('.tarjeta__titulo') : null),
          cifra: texto(tarjeta ? tarjeta.querySelector('.ganancia__valor') : null),
          preliminar: texto(tarjeta).includes('Preliminar de impuestos'),
          pagar: /Impuestos por pagar|Saldo a tu favor/.test(texto(tarjeta)),
          enlaces: [...(seccion('titulo-impuestos') ? seccion('titulo-impuestos').querySelectorAll('a') : [])].map((a) => href(a)),
        };
      })(),
      pendientes: tarjetas('titulo-pendientes'),
      documentos: tarjetas('titulo-actividad'),
      indicadores: seccion('titulo-indicadores')
        ? seccion('titulo-indicadores').querySelectorAll('.stat').length
        : 0,
      retirados: {
        accesos: cuerpo.includes('¿Qué quieres hacer hoy?'),
        devoluciones: cuerpo.includes('Devoluciones recientes'),
        sinFactura: cuerpo.includes('Cotizaciones vendidas sin factura'),
      },
    };
  });

  const ordenEsperado = [
    'titulo-negocios', 'titulo-impuestos', 'titulo-pendientes', 'titulo-mas-vendidos',
    'titulo-stock-bajo', 'titulo-actividad', 'titulo-indicadores',
  ];
  comprobar(
    'el resumen general se ordena como se atiende',
    JSON.stringify(panel.orden) === JSON.stringify(ordenEsperado),
    panel.orden.join(' → ')
  );

  comprobar(
    'cada negocio enseña sus ventas del periodo con IVA y ya no las ganancias',
    panel.negocios.length > 0 && panel.negocios.every((n) =>
      n.etiqueta.includes('Ventas del periodo (con IVA)') && n.cifra.includes('$') && n.sinGanancias),
    JSON.stringify(panel.negocios)
  );
  comprobar('la sección se llama «Ventas por negocio»', panel.negociosTitulo === 'Ventas por negocio',
    panel.negociosTitulo);
  comprobar(
    'y enlaza al detalle de las ventas de ese negocio',
    panel.negocios.length > 0 && panel.negocios.every((n) => n.enlace.includes('/admin/ventas?empresa=')),
    JSON.stringify(panel.negocios.map((n) => n.enlace))
  );

  comprobar(
    'el preliminar de impuestos y los impuestos por pagar van en la misma tarjeta',
    panel.impuestos.preliminar && panel.impuestos.pagar && panel.impuestos.cifra.includes('$'),
    `${panel.impuestos.titulo} · ${panel.impuestos.cifra}`
  );
  comprobar(
    'la tarjeta de impuestos enlaza al preliminar y al detalle',
    panel.impuestos.enlaces.some((h) => h.includes('/admin/fiscal/preliminar?anio=')) &&
      panel.impuestos.enlaces.some((h) => h.includes('/admin/fiscal?anio=')),
    JSON.stringify(panel.impuestos.enlaces)
  );

  const tarjetaDe = (textoBuscado) => panel.pendientes.find((c) => c.titulo.includes(textoBuscado))
    || { cabecera: [], filas: [], acciones: [] };
  comprobar('los cuatro pendientes comparten su tarjeta', panel.pendientes.length === 4,
    panel.pendientes.map((c) => c.titulo).join(' | '));
  for (const tituloEsperado of ['Ventas pendientes de pago o por facturar', 'Gastos pendientes por pagar',
    'Ventas pendientes por entregar', 'Incidencias de logística o entrega']) {
    comprobar(`el listado «${tituloEsperado}»`, panel.pendientes.some((c) => c.titulo.includes(tituloEsperado)));
  }

  const pendVentas = tarjetaDe('Ventas pendientes de pago');
  comprobar(
    'cada venta pendiente muestra el cliente o alias y el importe con IVA',
    pendVentas.filas.every((f) => f.includes('$') && f.length > 12),
    JSON.stringify(pendVentas.filas)
  );
  comprobar(
    'la tarjeta de ventas abre sus dos listados (por cobrar y por facturar)',
    pendVentas.cabecera.some((h) => h.includes('cobro=pendiente')) &&
      pendVentas.cabecera.some((h) => h.includes('sin_clasificar')),
    JSON.stringify(pendVentas.cabecera)
  );

  const pendGastos = tarjetaDe('Gastos pendientes por pagar');
  comprobar('el gasto pendiente se lista con su nombre, su concepto y su importe',
    pendGastos.filas.some((f) => f.includes('Proveedor Ronda Panel') && f.includes('Material de la ronda') && f.includes('$')),
    JSON.stringify(pendGastos.filas));
  comprobar('la tarjeta de gastos abre su listado de pagos pendientes',
    pendGastos.cabecera.some((h) => h.includes('gastos?pago=pendiente')),
    JSON.stringify(pendGastos.cabecera));

  const pendEntregas = tarjetaDe('Ventas pendientes por entregar');
  comprobar('cada entrega pendiente lleva su botón directo de seguimiento',
    pendEntregas.acciones.length === pendEntregas.filas.length &&
      pendEntregas.acciones.every((h) => h.includes('/admin/entregas-seguimiento/venta?id=')),
    JSON.stringify(pendEntregas.acciones));

  const pendIncidencias = tarjetaDe('Incidencias de logística');
  comprobar('cada incidencia se lista con su motivo y su botón directo para atenderla',
    pendIncidencias.acciones.length === pendIncidencias.filas.length &&
      pendIncidencias.acciones.every((h) => h.includes('#incidencias')) &&
      (incidenciaPrueba === 0 || pendIncidencias.filas.some((f) => f.includes('Producto dañado o defectuoso'))),
    JSON.stringify(pendIncidencias.filas));

  const cotizacionesRecientes = panel.documentos.find((d) => d.titulo.includes('Últimas cotizaciones'))
    || { filas: [], acciones: [] };
  const ventasRecientes = panel.documentos.find((d) => d.titulo.includes('Últimas ventas'))
    || { filas: [], acciones: [] };
  comprobar('las últimas cotizaciones se quedan en cinco y cada una trae su PDF',
    cotizacionesRecientes.filas.length <= 5 &&
      cotizacionesRecientes.acciones.length === cotizacionesRecientes.filas.length &&
      cotizacionesRecientes.acciones.every((h) => h.includes('/admin/cotizaciones/pdf?id=')),
    `${cotizacionesRecientes.filas.length} filas`);
  comprobar('las últimas ventas se quedan en cinco y cada una trae su PDF',
    ventasRecientes.filas.length <= 5 &&
      ventasRecientes.acciones.length === ventasRecientes.filas.length &&
      ventasRecientes.acciones.every((h) => h.includes('/admin/ventas/pdf?id=')),
    `${ventasRecientes.filas.length} filas`);

  comprobar('los indicadores del periodo cierran el resumen', panel.indicadores === 8, `${panel.indicadores}`);
  comprobar('ya no están los accesos rápidos, las devoluciones ni las cotizaciones sin factura',
    !panel.retirados.accesos && !panel.retirados.devoluciones && !panel.retirados.sinFactura);

  // Los datos de prueba ya cumplieron: se retiran de inmediato.
  if (gastoPrueba > 0) {
    sql(`DELETE FROM gastos WHERE id = ${gastoPrueba}`);
    gastoPrueba = 0;
  }
  if (incidenciaPrueba > 0) {
    sql(`DELETE FROM entrega_incidencias WHERE id = ${incidenciaPrueba}`);
    incidenciaPrueba = 0;
  }

  await sinDesborde(page, '/admin', 1440);
  await sinDesborde(page, '/admin', 390);
} catch (error) {
  fallos++;
  console.error('\nERROR DE EJECUCIÓN:', error.message);
} finally {
  // Limpieza: sólo lo que creó la prueba. La base queda como estaba.
  if (incidenciaPrueba > 0) {
    try {
      sql(`DELETE FROM entrega_incidencias WHERE id = ${incidenciaPrueba}`);
      console.log(`\nIncidencia de prueba id=${incidenciaPrueba} eliminada.`);
    } catch (e) {
      comprobar('la incidencia de prueba se eliminó', false, e.message.slice(0, 80));
    }
  }

  if (gastoPrueba > 0) {
    try {
      sql(`DELETE FROM gastos WHERE id = ${gastoPrueba}`);
      console.log(`\nGasto de prueba id=${gastoPrueba} eliminado.`);
    } catch (e) {
      comprobar('el gasto de prueba se eliminó', false, e.message.slice(0, 80));
    }
  }

  if (cotizacionPropia > 0) {
    await postCsrf(page, `${BASE}/admin/cotizaciones/eliminar`, {
      maxRedirects: 0,
      form: { id: String(cotizacionPropia) },
    }).catch(() => null);
    console.log(`\nCotización de prueba id=${cotizacionPropia} eliminada.`);
  }

  // Último: la cuenta de prueba sólo puede borrarse cuando ya no le quedan
  // cotizaciones (la FK es RESTRICT). Si algo quedó colgado, se avisa.
  if (usuarioPrueba > 0) {
    try {
      sql(`DELETE FROM usuarios WHERE id = ${usuarioPrueba}`);
      const restan = Number(sql(`SELECT COUNT(*) FROM usuarios WHERE id = ${usuarioPrueba}`));
      comprobar('la cuenta de prueba se eliminó', restan === 0, `${restan}`);
    } catch (e) {
      comprobar('la cuenta de prueba se eliminó', false, e.message.slice(0, 80));
    }
  }

  await navegador.close();
}

console.log(`\nPruebas: ${correctas + fallos}  Correctas: ${correctas}  Fallos: ${fallos}`);
process.exit(fallos > 0 ? 1 : 0);

/**
 * probar_categorias.mjs — Verificación del CRUD de categorías de proveedores.
 *
 * Lo que se comprueba:
 *   1. El botón "Crear Categorías" es el primero (lado izquierdo) de la
 *      barra de /admin/proveedores.
 *   2. /admin/proveedores/categorias lista el catálogo con su contador de
 *      entradas, deja buscar y marca la sección "Proveedores" como activa.
 *   3. Alta: aparece en la lista, en el badge de conteo y — lo importante —
 *      en el campo «Categoría» de las entradas de TODOS los proveedores.
 *   4. Nombres repetidos y escapado de HTML en el nombre.
 *   5. Edición: renombrar sólo cambia el texto que se muestra.
 *   6. Borrado bloqueado si la categoría está en uso; permitido si no.
 *   7. El endpoint de borrado sólo acepta POST.
 *   8. Sin desbordamiento horizontal en escritorio ni en móvil.
 *
 * Crea su propio usuario, proveedor y entrada, y los borra al final (el
 * usuario se borra el ÚLTIMO, porque usuario_id es RESTRICT). No toca los
 * datos del cliente: la única categoría creada es la de prueba.
 *
 * Uso: node pruebas/probar_categorias.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const EMAIL_PRUEBA = 'prueba.categorias@cotizaciones.local';
const CLAVE_PRUEBA = 'Prueba1234!';
const PROVEEDOR_PRUEBA = 'ZZ Proveedor CRUD categorías';
const CATEGORIA_NUEVA = 'ZZ Prueba CRUD';
const CATEGORIA_RENOMBRADA = 'ZZ Prueba CRUD 2026';
const CATEGORIA_RARA = '<b>ZZ escapado</b>';

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
  execFileSync('mysql', ['-u', 'root', (process.env.DB_NAME || 'cotizaciones'), '-N', '-B', '-e', consulta], {
    encoding: 'utf8',
  }).trim();

async function entrar(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL_PRUEBA);
  await page.fill('#contrasena', CLAVE_PRUEBA);
  await Promise.all([
    page.waitForURL('**/admin**', { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
}

/**
 * Texto de los botones de la barra de acciones SIN las ligaduras del icono:
 * el texto de `.material-symbols-outlined` ("category", "add"…) cae en
 * textContent y ensuciaría la comparación de etiquetas.
 */
const botonesDeAccion = (page) =>
  page.$$eval('.encabezado-pagina__acciones > *', (els) =>
    els.map((e) => {
      const clon = e.cloneNode(true);
      clon.querySelectorAll('.material-symbols-outlined').forEach((n) => n.remove());
      return {
        texto: (clon.textContent || '').replace(/\s+/g, ' ').trim(),
        href: e.getAttribute('href'),
      };
    })
  );

/** Opciones del select «Categoría» del formulario de entradas. */
async function categoriasEnFormulario(page, url) {
  await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
  return page.$$eval('#categoria option', (ops) =>
    ops.map((o) => ({ valor: o.value, texto: o.textContent.trim() }))
  );
}

/** Clic en "Eliminar" de la fila que coincida con un nombre exacto. */
async function eliminarFila(page, nombre) {
  const encontrada = await page.evaluate((n) => {
    const filas = [...document.querySelectorAll('.tabla tbody tr')];
    const fila = filas.find(
      (f) => (f.querySelector('td strong')?.textContent || '').trim() === n
    );
    if (!fila) return false;
    fila.querySelector('form button[type="submit"]').click();
    return true;
  }, nombre);

  if (!encontrada) return false;

  await page.waitForSelector('#modal-principal', { state: 'visible', timeout: 5000 });
  await page.click('#modal-pie [data-confirmar-modal]');
  // El POST redirige SIEMPRE al listado, que ya es la URL actual: esperar por
  // URL no serviría de nada, así que se da tiempo a que llegue la respuesta.
  await page.waitForTimeout(900);
  return true;
}

/** Primera alerta visible tras un envío. */
const alertaVisible = (page) =>
  page
    .waitForSelector('#alertas-contenedor .alerta', { timeout: 5000 })
    .then((h) => h.evaluate((n) => n.textContent.replace(/\s+/g, ' ').trim()))
    .catch(() => '');

/**
 * Aviso de error del formulario (la tira `.nota--error` que pintan las
 * vistas cuando la validación del servidor devuelve mensajes).
 */
const avisoDeFormulario = async (page) => {
  await page.waitForTimeout(600);
  return page.evaluate(
    () =>
      document
        .querySelector('.nota--error')
        ?.textContent.replace(/\s+/g, ' ')
        .trim() || ''
  );
};

/** Desbordamiento horizontal del documento. */
const desborde = (page) =>
  page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    inner: window.innerWidth,
  }));

let usuarioId = 0;
let proveedorId = 0;
let entradaId = 0;
let navegador = null;
// Se declaran fuera del `try` porque el bloque `finally` (la limpieza) también
// los necesita, y `const`/`let` declarados dentro no salen de ahí.
let catalogoBase = 0;
let entradasBase = 0;

try {
  /* ------------------------------------------------ 0. Preparación */
  console.log('\n0. Preparación');

  const viejo = sql(`SELECT id FROM usuarios WHERE email = '${EMAIL_PRUEBA}'`);
  if (viejo) {
    sql(`DELETE FROM proveedor_entradas WHERE usuario_id = ${Number(viejo)}`);
    sql(`DELETE FROM usuarios WHERE id = ${Number(viejo)}`);
  }
  sql(`DELETE FROM proveedores WHERE nombre = '${PROVEEDOR_PRUEBA}'`);
  sql(
    `DELETE FROM categorias_proveedor WHERE nombre IN ` +
      `('${CATEGORIA_NUEVA}', '${CATEGORIA_RENOMBRADA}', '${CATEGORIA_RARA}')`
  );

  const hash = execFileSync(
    'php',
    ['-r', `echo password_hash(${JSON.stringify(CLAVE_PRUEBA)}, PASSWORD_BCRYPT);`],
    { encoding: 'utf8' }
  ).trim();
  sql(
    'INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ' +
      `VALUES ('Prueba CRUD categorías', '${EMAIL_PRUEBA}', '${hash}', '', 1)`
  );
  usuarioId = Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL_PRUEBA}'`));

  sql(`INSERT INTO proveedores (nombre, tipo_proveedor) VALUES ('${PROVEEDOR_PRUEBA}', 'Material')`);
  proveedorId = Number(sql(`SELECT id FROM proveedores WHERE nombre = '${PROVEEDOR_PRUEBA}'`));

  // El catálogo de categorías lo administra el usuario (crea, renombra y borra
  // lo que quiera): aquí se leen sus filas en lugar de fijar "6 categorías" o
  // el id 1. Como "protegida" se toma la que va PRIMERO alfabéticamente, para
  // que siempre esté en la primera página del listado.
  entradasBase = Number(sql('SELECT COUNT(*) FROM proveedor_entradas'));
  catalogoBase = Number(sql('SELECT COUNT(*) FROM categorias_proveedor'));
  const idMaxAntes = Number(sql('SELECT IFNULL(MAX(id), 0) FROM categorias_proveedor'));
  const filaProtegida = sql('SELECT id, nombre FROM categorias_proveedor ORDER BY nombre ASC LIMIT 1').split('\t');
  const catProtegida = { id: filaProtegida[0] || '', nombre: filaProtegida[1] || '' };

  // Entrada de prueba con la categoría protegida: es la que hará que esa
  // categoría no se pueda borrar. No se modifica ninguna fila del cliente.
  sql(
    `INSERT INTO proveedor_entradas (proveedor_id, fecha, categoria, descripcion, monto, usuario_id) ` +
      `VALUES (${proveedorId}, CURDATE(), ${Number(catProtegida.id) || 0}, 'Entrada de prueba del CRUD de categorías', 100.00, ${usuarioId})`
  );
  entradaId = Number(sql(`SELECT MAX(id) FROM proveedor_entradas WHERE usuario_id = ${usuarioId}`));

  const enUsoBase = Number(
    sql(`SELECT COUNT(*) FROM proveedor_entradas WHERE categoria = ${Number(catProtegida.id) || 0}`)
  );

  comprobar('la cuenta de prueba existe', usuarioId > 0, `id=${usuarioId}`);
  comprobar('el proveedor de prueba existe', proveedorId > 0, `id=${proveedorId}`);
  comprobar('la entrada de prueba existe', entradaId > 0, `id=${entradaId}`);
  comprobar('hay categorías en el catálogo', catalogoBase >= 1, `${catalogoBase}`);
  comprobar(`"${catProtegida.nombre}" está en uso`, enUsoBase >= 1, `${enUsoBase}`);
  comprobar('el catálogo cabe en una página (15 filas)', catalogoBase + 2 <= 15,
    `${catalogoBase} + 2`);

  navegador = await chromium.launch();
  const context = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await entrar(page);

  /* ------------------------------------- 1. Botón "Crear Categorías" */
  console.log('\n1. Botón "Crear Categorías" en /admin/proveedores');
  await page.goto(`${BASE}/admin/proveedores`, { waitUntil: 'domcontentloaded' });
  const botones = await botonesDeAccion(page);
  comprobar('hay acciones en la cabecera', botones.length >= 3, `${botones.length}`);
  comprobar(
    'el PRIMER botón es "Crear Categorías"',
    botones[0]?.texto === 'Crear Categorías',
    `primero="${botones[0]?.texto}"`
  );
  comprobar(
    'apunta al CRUD de categorías',
    botones[0]?.href === '/admin/proveedores/categorias',
    `href=${botones[0]?.href}`
  );
  comprobar(
    'siguen "Exportar datos", "Entradas" y "Nuevo proveedor"',
    botones.slice(1, 4).map((b) => b.texto).join('|') ===
      'Exportar datos|Entradas|Nuevo proveedor',
    botones.slice(1, 4).map((b) => b.texto).join('|')
  );

  /* ------------------------------------------- 2. Listado de categorías */
  console.log('\n2. Listado /admin/proveedores/categorias');
  await Promise.all([
    page.waitForURL('**/admin/proveedores/categorias', { timeout: 10000 }),
    page.click('a[href="/admin/proveedores/categorias"]'),
  ]);

  const filas = await page.$$eval('.tabla tbody tr', (fs) =>
    fs.map((f) => ({
      nombre: (f.querySelector('td strong')?.textContent || '').trim(),
      entradas: (f.querySelector('td:nth-child(2)')?.textContent || '').trim(),
    }))
  );
  // El orden esperado sale de la propia base (ORDER BY nombre ASC), no de una
  // lista escrita a mano: el usuario puede dar de alta y borrar lo que quiera.
  const nombresBase = sql(
    'SELECT GROUP_CONCAT(nombre ORDER BY nombre SEPARATOR "¦") FROM categorias_proveedor'
  ).split('¦').filter(Boolean);
  const nombresVisibles = nombresBase.slice(0, 15);
  comprobar('lista todas las categorías del catálogo', filas.length === nombresVisibles.length,
    `${filas.length} vs ${nombresVisibles.length}`);
  comprobar(
    'la lista está en orden alfabético',
    filas.map((f) => f.nombre).join(',') === nombresVisibles.join(','),
    filas.map((f) => f.nombre).join(',')
  );
  const filaProtegidaEnListado = filas.find((f) => f.nombre === catProtegida.nombre);
  comprobar(
    'la columna "Entradas" muestra cuántas la usan',
    filaProtegidaEnListado?.entradas === String(enUsoBase),
    `${catProtegida.nombre}=${filaProtegidaEnListado?.entradas} esperado=${enUsoBase}`
  );
  const badgeTotal = await page.textContent('.tabla-toolbar .badge');
  comprobar(
    `el badge del toolbar dice ${nombresBase.length} categorías`,
    new RegExp(`${nombresBase.length}\\s+categor`, 'i').test(badgeTotal || ''),
    `"${badgeTotal}"`
  );
  const activo = await page.getAttribute('a[href="/admin/proveedores"][aria-current]', 'aria-current');
  comprobar('la sección "Proveedores" queda activa', activo === 'page', `${activo}`);

  /* ------------------------------------------------------- 3. Alta */
  console.log('\n3. Crear categoría');
  const badgeAntes = Number((badgeTotal || '').replace(/\D+/g, ''));
  await page.goto(`${BASE}/admin/proveedores/categorias/crear`, { waitUntil: 'domcontentloaded' });
  await page.fill('#nombre', CATEGORIA_NUEVA);
  await Promise.all([
    page.waitForURL('**/admin/proveedores/categorias', { timeout: 10000 }),
    page.click('button[type="submit"]'),
  ]);
  const trasAlta = await page.textContent('.tabla-toolbar .badge');
  comprobar(
    `el contador sube a ${badgeAntes + 1}`,
    Number((trasAlta || '').replace(/\D+/g, '')) === badgeAntes + 1,
    `"${trasAlta}"`
  );
  comprobar(
    'la nueva categoría está en la lista',
    (await page.$$eval('.tabla tbody td strong', (e) => e.map((n) => n.textContent.trim()))).includes(
      CATEGORIA_NUEVA
    )
  );
  comprobar(
    'guardada en la base con id nuevo',
    Number(sql(`SELECT id FROM categorias_proveedor WHERE nombre = '${CATEGORIA_NUEVA}'`)) > idMaxAntes,
    `máx antes=${idMaxAntes}`
  );

  /* --------------------------------------- 4. Categoría compartida */
  console.log('\n4. Se ofrece en las entradas de TODOS los proveedores');
  let opciones = await categoriasEnFormulario(page, '/admin/proveedores/entradas/crear');
  comprobar(
    'el formulario de entradas ofrece la categoría nueva',
    opciones.some((o) => o.texto === CATEGORIA_NUEVA),
    opciones.map((o) => o.texto).join(', ')
  );
  comprobar(
    'conserva la opción "Sin categoría" con valor 0',
    opciones[0]?.valor === '0' && opciones[0]?.texto === 'Sin categoría',
    JSON.stringify(opciones[0])
  );
  comprobar(
    'hay más de una opción, o sea que no es una lista por proveedor',
    // "Sin categoría" + el catálogo completo + la categoría recién creada.
    opciones.length === catalogoBase + 2,
    `${opciones.length} vs ${catalogoBase + 2}`
  );

  /* ----------------------------------------- 5. Validaciones y XSS */
  console.log('\n5. Validaciones');
  await page.goto(`${BASE}/admin/proveedores/categorias/crear`, { waitUntil: 'domcontentloaded' });
  await page.fill('#nombre', catProtegida.nombre); // ya existe
  await page.click('button[type="submit"]');
  const avisoDuplicado = await avisoDeFormulario(page);
  comprobar(
    'rechaza un nombre repetido',
    /ya existe una categoría/i.test(avisoDuplicado),
    avisoDuplicado
  );
  comprobar(
    'el nombre repetido NO se guardó',
    Number(
      sql(`SELECT COUNT(*) FROM categorias_proveedor WHERE nombre = '${catProtegida.nombre}'`)
    ) === 1
  );

  await page.goto(`${BASE}/admin/proveedores/categorias/crear`, { waitUntil: 'domcontentloaded' });
  const limite = await page.getAttribute('#nombre', 'maxlength');
  comprobar('el campo limita el nombre a 80 caracteres', limite === '80', `${limite}`);
  await page.fill('#nombre', CATEGORIA_RARA);
  await Promise.all([
    page.waitForURL('**/admin/proveedores/categorias', { timeout: 10000 }),
    page.click('button[type="submit"]'),
  ]);
  const textoEscapado = await page.$$eval('.tabla tbody td strong', (e) =>
    e.map((n) => n.textContent.trim())
  );
  comprobar(
    'el nombre con HTML se guarda y se muestra como texto',
    textoEscapado.includes(CATEGORIA_RARA),
    textoEscapado.join(', ')
  );
  const htmlCrudo = await page.content();
  comprobar(
    'el HTML del nombre NO se interpreta en la lista',
    !htmlCrudo.includes('<strong><b>ZZ escapado</b></strong>')
  );
  await eliminarFila(page, CATEGORIA_RARA);
  comprobar(
    'se pudo borrar la categoría sin uso',
    Number(sql(`SELECT COUNT(*) FROM categorias_proveedor WHERE nombre = '${CATEGORIA_RARA}'`)) === 0
  );

  /* ---------------------------------------------------- 6. Edición */
  console.log('\n6. Renombrar');
  const idNueva = Number(sql(`SELECT id FROM categorias_proveedor WHERE nombre = '${CATEGORIA_NUEVA}'`));
  await page.goto(`${BASE}/admin/proveedores/categorias/editar?id=${idNueva}`, {
    waitUntil: 'domcontentloaded',
  });
  await page.fill('#nombre', CATEGORIA_RENOMBRADA);
  await Promise.all([
    page.waitForURL('**/admin/proveedores/categorias', { timeout: 10000 }),
    page.click('button[type="submit"]'),
  ]);
  comprobar(
    'el id NO cambia al renombrar',
    Number(sql(`SELECT id FROM categorias_proveedor WHERE nombre = '${CATEGORIA_RENOMBRADA}'`)) === idNueva
  );
  opciones = await categoriasEnFormulario(page, '/admin/proveedores/entradas/crear');
  comprobar(
    'las entradas muestran el nombre nuevo',
    opciones.some((o) => o.texto === CATEGORIA_RENOMBRADA)
  );
  comprobar(
    'las entradas ya no muestran el nombre viejo',
    !opciones.some((o) => o.texto === CATEGORIA_NUEVA)
  );

  /* --------------------------------------- 7. Borrado con y sin uso */
  console.log('\n7. Borrado protegido');
  await page.goto(`${BASE}/admin/proveedores/categorias`, { waitUntil: 'domcontentloaded' });
  const eliminada = await eliminarFila(page, catProtegida.nombre);
  comprobar(`se intenta borrar "${catProtegida.nombre}" (en uso)`, eliminada);
  const avisoUso = await alertaVisible(page);
  comprobar(
    'el servidor se niega y explica por qué',
    /no se puede eliminar/i.test(avisoUso) && /entrada/i.test(avisoUso),
    avisoUso
  );
  comprobar(
    `"${catProtegida.nombre}" sigue en la base`,
    Number(sql(`SELECT COUNT(*) FROM categorias_proveedor WHERE id = ${Number(catProtegida.id) || 0}`)) === 1
  );
  const trasRechazo = await page.textContent('.tabla-toolbar .badge');
  comprobar(
    'el catálogo no perdió ninguna categoría',
    Number((trasRechazo || '').replace(/\D+/g, '')) === catalogoBase + 1,
    `"${trasRechazo}" esperado=${catalogoBase + 1}`
  );

  await page.goto(`${BASE}/admin/proveedores/categorias`, { waitUntil: 'domcontentloaded' });
  await eliminarFila(page, CATEGORIA_RENOMBRADA);
  comprobar(
    'la categoría de prueba (sin uso) sí se borra',
    Number(sql(`SELECT COUNT(*) FROM categorias_proveedor WHERE id = ${idNueva}`)) === 0
  );
  comprobar(
    'la entrada de prueba no se vio afectada',
    Number(sql(`SELECT COUNT(*) FROM proveedor_entradas WHERE id = ${entradaId}`)) === 1
  );
  opciones = await categoriasEnFormulario(page, '/admin/proveedores/entradas/crear');
  comprobar(
    'desaparece del formulario de entradas',
    !opciones.some((o) => o.texto === CATEGORIA_RENOMBRADA)
  );

  /* --------------------------------------------------- 8. Búsqueda */
  console.log('\n8. Búsqueda en el listado');
  await page.goto(`${BASE}/admin/proveedores/categorias?q=${encodeURIComponent(catProtegida.nombre)}`,
    { waitUntil: 'domcontentloaded' });
  const buscadas = await page.$$eval('.tabla tbody td strong', (e) => e.map((n) => n.textContent.trim()));
  const esperadas = Number(
    sql(`SELECT COUNT(*) FROM categorias_proveedor WHERE nombre LIKE '%${catProtegida.nombre}%'`)
  );
  comprobar('filtra por el término de búsqueda',
    buscadas.length === esperadas && buscadas.includes(catProtegida.nombre),
    `${buscadas.join(',')} / base=${esperadas}`);
  const badgeBusqueda = await page.textContent('.tabla-toolbar .badge');
  comprobar(
    `el badge refleja el filtro (${esperadas} categoría)`,
    new RegExp(`${esperadas}\\s+categor`, 'i').test(badgeBusqueda || ''),
    `"${badgeBusqueda}"`
  );
  const hayLimpiar = await page.$('a[href="/admin/proveedores/categorias"]:has-text("Limpiar")');
  comprobar('muestra el botón "Limpiar" con filtro activo', !!hayLimpiar);

  await page.goto(`${BASE}/admin/proveedores/categorias?q=zzzznoexiste`, {
    waitUntil: 'domcontentloaded',
  });
  const vacio = await page.textContent('.vacio__titulo').catch(() => '');
  comprobar('estado vacío cuando no hay coincidencias', /sin coincidencias/i.test(vacio), vacio);

  /* ------------------------------------------------- 9. Sólo POST */
  console.log('\n9. Endpoints');
  const getBorrar = await page.goto(`${BASE}/admin/proveedores/categorias/eliminar`, {
    waitUntil: 'domcontentloaded',
  });
  comprobar('el borrado no acepta GET (404)', getBorrar.status() === 404, `${getBorrar.status()}`);
  const getCrear = await page.goto(`${BASE}/admin/proveedores/categorias/crear`, {
    waitUntil: 'domcontentloaded',
  });
  comprobar('el alta sí acepta GET (formulario)', getCrear.status() === 200, `${getCrear.status()}`);

  /* ------------------------------------------ 10. Sin desbordamiento */
  console.log('\n10. Sin desbordamiento horizontal');
  for (const ruta of [
    '/admin/proveedores',
    '/admin/proveedores/categorias',
    '/admin/proveedores/categorias/crear',
    '/admin/proveedores/entradas/crear',
  ]) {
    for (const ancho of [1440, 390]) {
      await page.setViewportSize({ width: ancho, height: 900 });
      await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(200);
      const m = await desborde(page);
      comprobar(`${ruta} @${ancho}px sin desborde`, m.scroll <= m.inner, `scroll=${m.scroll} inner=${m.inner}`);
    }
  }
  await page.setViewportSize({ width: 1440, height: 900 });
} catch (error) {
  fallos++;
  console.log(`  ✗ error inesperado — ${error.message}`);
} finally {
  /* ------------------------------------------------- Limpieza */
  try {
    // En este orden: la entrada depende del usuario y del proveedor; la
    // categoría, de nada; el usuario va el ÚLTIMO (usuario_id es RESTRICT).
    if (entradaId > 0) sql(`DELETE FROM proveedor_entradas WHERE id = ${entradaId}`);
    if (proveedorId > 0) sql(`DELETE FROM proveedores WHERE id = ${proveedorId}`);
    sql(
      `DELETE FROM categorias_proveedor WHERE nombre IN ` +
        `('${CATEGORIA_NUEVA}', '${CATEGORIA_RENOMBRADA}', '${CATEGORIA_RARA}')`
    );
    if (usuarioId > 0) sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);

    comprobar(
      `sin restos: categorías = ${catalogoBase}`,
      Number(sql('SELECT COUNT(*) FROM categorias_proveedor')) === catalogoBase,
      `${sql('SELECT COUNT(*) FROM categorias_proveedor')} vs ${catalogoBase}`
    );
    comprobar(
      'sin restos: entradas de prueba = 0',
      Number(sql(`SELECT COUNT(*) FROM proveedor_entradas WHERE usuario_id = ${usuarioId}`)) === 0
    );
    comprobar(
      'sin restos: proveedor de prueba = 0',
      Number(sql(`SELECT COUNT(*) FROM proveedores WHERE nombre = '${PROVEEDOR_PRUEBA}'`)) === 0
    );
    comprobar(
      'sin restos: usuario de prueba = 0',
      Number(sql(`SELECT COUNT(*) FROM usuarios WHERE email = '${EMAIL_PRUEBA}'`)) === 0
    );
    comprobar(
      `los datos del cliente siguen enteros (${entradasBase} entradas)`,
      Number(sql('SELECT COUNT(*) FROM proveedor_entradas')) === entradasBase,
      `${sql('SELECT COUNT(*) FROM proveedor_entradas')} vs ${entradasBase}`
    );
  } catch (error) {
    fallos++;
    console.log(`  ✗ fallo en la limpieza — ${error.message}`);
  }

  if (navegador) await navegador.close().catch(() => {});
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

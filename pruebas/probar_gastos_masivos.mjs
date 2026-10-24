/**
 * probar_gastos_masivos.mjs — selección múltiple de gastos para eliminarlos.
 *
 * En /admin/contabilidad/gastos se marcan varios gastos con casillas y se
 * eliminan en bloque desde POST /admin/contabilidad/gastos/masivo, junto con
 * sus documentos adjuntos (filas de `documentos` y archivos del disco).
 *
 * Comprueba:
 *   1. La lista trae casilla por fila, «marcar todos» y la barra con el
 *      contador de gastos y de documentos adjuntos.
 *   2. No se envía sin motivo, ni con un motivo de menos de 5 caracteres.
 *   3. La confirmación trae los números y el motivo; al aceptar se borran los
 *      gastos, las filas de documentos, los archivos y la liga de la lectura.
 *   4. Un gasto de mes cerrado NO se elimina: se borra el resto y se avisa.
 *   5. La lista vuelve con los mismos filtros (búsqueda y «todos»).
 *
 * Crea sus propios gastos, documentos, archivos, lectura y periodo cerrado, y
 * lo borra todo al final. No toca los datos del cliente.
 *
 * Uso: node pruebas/probar_gastos_masivos.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const EMAIL = 'prueba.gastos.masivos@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const ALMACEN = join(RAIZ, 'storage', 'documentos');
const BUSCAR = 'Gasto masivo de prueba';
const MOTIVO = 'Registro capturado dos veces por error';

const sql = (consulta) =>
  execFileSync('mysql', ['-u', 'root', process.env.DB_NAME || 'cotizaciones', '-N', '-B', '-e', consulta], {
    encoding: 'utf8',
  }).trim();

let correctas = 0;
let fallos = 0;

function comprobar(nombre, condicion, detalle = '') {
  if (condicion) {
    correctas += 1;
    console.log(`  ✓ ${nombre}`);
  } else {
    fallos += 1;
    console.log(`  ✗ ${nombre}${detalle ? ' — ' + detalle : ''}`);
  }
}

/** Archivos creados en el almacén (se borran al final aunque falle la prueba). */
const archivos = [];
/** El periodo cerrado sólo se borra si lo creó esta prueba. */
let periodoCreado = false;

/** Mes anterior al actual: ahí se crea el gasto de un periodo cerrado. */
const mesAnterior = (() => {
  const fecha = new Date();
  fecha.setDate(1);
  fecha.setMonth(fecha.getMonth() - 1);
  return { anio: fecha.getFullYear(), mes: fecha.getMonth() + 1 };
})();
const fechaCerrada = `${mesAnterior.anio}-${String(mesAnterior.mes).padStart(2, '0')}-15`;

function contribuyente() {
  return Number(sql('SELECT IFNULL(MIN(id), 0) FROM contribuyentes'));
}

/** Quita los restos de una corrida anterior. */
function limpiarDePrueba() {
  const c = contribuyente();
  sql(
    `DELETE d FROM documentos d INNER JOIN gastos g ON g.id = d.entidad_id AND d.entidad = 'gasto'
      WHERE g.concepto LIKE '${BUSCAR}%'`
  );
  sql(`DELETE FROM lecturas_documentales WHERE nombre_original LIKE '${BUSCAR}%'`);
  sql(`DELETE FROM gastos WHERE concepto LIKE '${BUSCAR}%'`);
  // Si al eliminar se dejó copia en la papelera, tampoco debe quedar.
  sql(`DELETE FROM papelera WHERE tipo = 'gasto' AND etiqueta LIKE '%${BUSCAR}%'`);
  sql(
    `DELETE FROM periodos_contables WHERE contribuyente_id = ${c} AND estado = 'cerrado'
       AND anio = ${mesAnterior.anio} AND mes = ${mesAnterior.mes}`
  );
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
}

function prepararUsuario() {
  limpiarDePrueba();
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba gastos masivos', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

/** Gasto de prueba con un concepto propio (para filtrarlo con «q» en la lista). */
function gastoDePrueba(usuarioId, fecha, sufijo) {
  sql(
    `INSERT INTO gastos (contribuyente_id, fecha, concepto, beneficiario_tipo, beneficiario, naturaleza,
            subtotal, iva, total, estado_pago, estado, usuario_id)
     VALUES (${contribuyente()}, '${fecha}', '${BUSCAR} ${sufijo}', 'otro', '${BUSCAR}', 'gasto',
            100, 16, 116, 'pendiente', 'vigente', ${usuarioId})`
  );
  return Number(sql(`SELECT id FROM gastos WHERE usuario_id = ${usuarioId} AND concepto LIKE '${BUSCAR}%' ORDER BY id DESC LIMIT 1`));
}

/**
 * Documento adjunto de prueba: escribe el archivo en el almacén (misma ruta
 * que usa Archivos::subir) y crea su fila en `documentos`.
 *
 * @returns {string} ruta absoluta del archivo
 */
function documentoDePrueba(gastoId, usuarioId, sufijo) {
  const hoy = new Date();
  const anio = hoy.getFullYear();
  const mes = String(hoy.getMonth() + 1).padStart(2, '0');
  const relativa = `${contribuyente()}/${anio}/${mes}/gastos`;
  const carpeta = join(ALMACEN, relativa);
  if (!existsSync(carpeta)) {
    mkdirSync(carpeta, { recursive: true });
  }

  const contenido = `<?xml version="1.0"?><!-- ${BUSCAR} ${sufijo} --><cfdi:Comprobante Version="4.0"/>`;
  const nombre = `masivo_${gastoId}_${sufijo}.xml`;
  const ruta = join(carpeta, nombre);
  writeFileSync(ruta, contenido);
  archivos.push(ruta);

  sql(
    `INSERT INTO documentos (contribuyente_id, entidad, entidad_id, tipo, nombre_original, archivo, mime, tamano,
            sha256, estado_validacion, subido_por)
     VALUES (${contribuyente()}, 'gasto', ${gastoId}, 'xml', '${BUSCAR} ${sufijo}', '${relativa}/${nombre}',
            'application/xml', ${contenido.length}, '${createHash('sha256').update(contenido).digest('hex')}',
            'no_aplica', ${usuarioId})`
  );

  return ruta;
}

/** Lectura ya confirmada contra el gasto: al eliminarlo debe quedar sin enlace. */
function lecturaDePrueba(gastoId, usuarioId) {
  sql(
    `INSERT INTO lecturas_documentales (contribuyente_id, destino, nombre_original, archivo, mime, sha256, fuente,
            datos_leidos, avisos, estado, entidad, entidad_id, creado_por)
     VALUES (${contribuyente()}, 'gasto', '${BUSCAR} lectura.xml', 'lecturas/masivo_${gastoId}.xml', 'application/xml',
            '${createHash('sha256').update(String(gastoId)).digest('hex')}', 'xml', '{"campos":{},"conceptos":[]}', '[]',
            'confirmada', 'gasto', ${gastoId}, ${usuarioId})`
  );
}

/** Cierra el mes anterior (sólo si nadie lo había cerrado). */
function cerrarMesAnterior() {
  const c = contribuyente();
  const existente = Number(sql(
    `SELECT COUNT(*) FROM periodos_contables WHERE contribuyente_id = ${c} AND anio = ${mesAnterior.anio} AND mes = ${mesAnterior.mes}`
  ));
  if (existente === 0) {
    sql(`INSERT INTO periodos_contables (contribuyente_id, anio, mes, estado, cerrado_en)
         VALUES (${c}, ${mesAnterior.anio}, ${mesAnterior.mes}, 'cerrado', NOW())`);
    periodoCreado = true;
  }

  return fechaCerrada;
}

async function login(pagina) {
  await pagina.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin**', { timeout: 15000 }), pagina.click('button[type="submit"]')]);
}

/** Texto de todas las alertas visibles, en una sola línea. */
async function alertas(pagina) {
  const textos = await pagina.locator('.alerta__texto').allTextContents();
  return textos.map((t) => t.replace(/\s+/g, ' ').trim()).join(' | ');
}

/* --------------------------------------------------------------------- */

const usuarioId = prepararUsuario();
const rutaLista = `${BASE}/admin/contabilidad/gastos?todos=1&q=${encodeURIComponent(BUSCAR)}`;

let navegador = null;

try {
  const gastoConDocs1 = gastoDePrueba(usuarioId, new Date().toISOString().slice(0, 10), 'uno');
  const gastoConDocs2 = gastoDePrueba(usuarioId, new Date().toISOString().slice(0, 10), 'dos');
  const gastoSinDocs = gastoDePrueba(usuarioId, new Date().toISOString().slice(0, 10), 'tres');
  const archivo1 = documentoDePrueba(gastoConDocs1, usuarioId, 'uno');
  const archivo2 = documentoDePrueba(gastoConDocs1, usuarioId, 'uno-b');
  const archivo3 = documentoDePrueba(gastoConDocs2, usuarioId, 'dos');
  lecturaDePrueba(gastoConDocs1, usuarioId);

  const fechaCerradaMes = cerrarMesAnterior();
  const gastoMesCerrado = gastoDePrueba(usuarioId, fechaCerradaMes, 'cerrado');

  const abiertos = [gastoConDocs1, gastoConDocs2, gastoSinDocs];
  const idsTodos = abiertos.concat([gastoMesCerrado]);

  navegador = await chromium.launch();
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
  const pagina = await contexto.newPage();
  const errores = [];
  pagina.on('pageerror', (error) => errores.push(String(error)));
  pagina.on('console', (mensaje) => {
    if (mensaje.type() === 'error') errores.push(mensaje.text());
  });

  console.log('\n1. La lista de gastos: casillas, «marcar todos» y barra');
  await login(pagina);
  await pagina.goto(rutaLista, { waitUntil: 'domcontentloaded' });

  comprobar('cada gasto de la lista trae su casilla', (await pagina.locator('input[name="gastos[]"]').count()) === 4,
    `filas=${await pagina.locator('input[name="gastos[]"]').count()}`);
  comprobar('la barra de selección empieza oculta', await pagina.locator('[data-resumen-seleccion]').isHidden());
  comprobar('el botón de eliminar empieza desactivado', await pagina.locator('[data-eliminar-gastos]').isDisabled());

  await pagina.check('[data-marcar-todas]');
  comprobar('«marcar todos» marca las 4 filas', (await pagina.locator('input[name="gastos[]"]:checked').count()) === 4);

  const contador = (await pagina.locator('[data-seleccion-contador]').textContent()).trim();
  comprobar('el contador dice los gastos marcados', contador.includes('4 gastos marcados'), contador);
  const documentosTexto = (await pagina.locator('[data-seleccion-documentos]').textContent()).trim();
  comprobar('cuenta los documentos adjuntos que se borrarán', documentosTexto.includes('3 documentos adjuntos'), documentosTexto);
  comprobar('la barra se muestra con la selección', await pagina.locator('[data-resumen-seleccion]').isVisible());
  comprobar('el botón de eliminar se activa', await pagina.locator('[data-eliminar-gastos]').isEnabled());

  // Quitar una fila actualiza el contador (4 → 3 y sus documentos).
  await pagina.uncheck(`#gasto-${gastoSinDocs}`);
  const contador2 = (await pagina.locator('[data-seleccion-contador]').textContent()).trim();
  comprobar('al quitar una fila baja el contador', contador2.includes('3 gastos marcados'), contador2);

  // Y se vuelve a marcar todo para el envío.
  await pagina.check('[data-marcar-todas]');
  comprobar('se recuperan las 4 filas marcadas', (await pagina.locator('input[name="gastos[]"]:checked').count()) === 4);

  console.log('\n2. El motivo es obligatorio');
  await pagina.click('[data-eliminar-gastos]');
  await pagina.waitForSelector('.campo__error', { timeout: 5000 });
  comprobar('con el motivo vacío no se envía', pagina.url().includes('todos=1'), pagina.url());
  const errorVacio = (await pagina.locator('.campo__error').first().textContent()).trim();
  comprobar('pide el motivo y sus 5 caracteres', errorVacio.includes('mínimo 5 caracteres'), errorVacio);

  await pagina.fill('#motivo-eliminacion', 'abc');
  await pagina.click('[data-eliminar-gastos]');
  await pagina.waitForTimeout(300);
  comprobar('un motivo muy corto tampoco pasa', (await pagina.locator('.campo__error').count()) === 1,
    await pagina.locator('.campo__error').first().textContent().catch(() => '(sin error)'));

  console.log('\n3. Confirmación con los números y el motivo');
  await pagina.fill('#motivo-eliminacion', MOTIVO);
  await pagina.click('[data-eliminar-gastos]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });

  const titulo = (await pagina.locator('#modal-titulo').textContent()).trim();
  comprobar('se abre la confirmación', titulo === '¿Eliminar los gastos marcados?', titulo);
  const cuerpo = (await pagina.locator('#modal-cuerpo').textContent()).replace(/\s+/g, ' ').trim();
  comprobar('el mensaje trae cuántos gastos se borran', cuerpo.includes('4 gastos'), cuerpo);
  comprobar('…y cuántos documentos adjuntos', cuerpo.includes('3 documentos adjuntos'), cuerpo);
  comprobar('…y el motivo escrito', cuerpo.includes(MOTIVO), cuerpo);
  const pie = (await pagina.locator('#modal-pie').textContent()).replace(/\s+/g, ' ').trim();
  comprobar('la confirmación se llama «Eliminar»', pie.includes('Eliminar'), pie);

  await Promise.all([
    pagina.waitForURL(/\/admin\/contabilidad\/gastos\?/, { timeout: 20000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  comprobar('regresa a la lista con los mismos filtros',
    pagina.url().includes('todos=1') && pagina.url().includes('q=Gasto'),
    pagina.url());

  console.log('\n4. Lo que quedó borrado y lo que no');
  const quedan = sql(`SELECT COUNT(*) FROM gastos WHERE usuario_id = ${usuarioId} AND concepto LIKE '${BUSCAR}%'`);
  comprobar('sólo quedó el gasto del mes cerrado', quedan === '1', `quedan=${quedan}`);
  comprobar('los otros tres gastos ya no existen',
    abiertos.every((id) => sql(`SELECT COUNT(*) FROM gastos WHERE id = ${id}`) === '0'));
  comprobar('sus documentos adjuntos desaparecieron (filas)',
    sql(`SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id IN (${abiertos.join(',')})`) === '0');
  comprobar('y también sus archivos del disco',
    [archivo1, archivo2, archivo3].every((ruta) => !existsSync(ruta)),
    [archivo1, archivo2, archivo3].filter((ruta) => existsSync(ruta)).join(' · '));
  comprobar('la lectura quedó sin enlace y disponible para volver a usarla',
    sql(`SELECT CONCAT(estado, '/', IFNULL(entidad, 'sin'), '/', IFNULL(entidad_id, '0')) FROM lecturas_documentales
          WHERE nombre_original LIKE '${BUSCAR}%'`) === 'descartada/sin/0',
    sql(`SELECT CONCAT(estado, '/', IFNULL(entidad, 'sin'), '/', IFNULL(entidad_id, '0')) FROM lecturas_documentales
          WHERE nombre_original LIKE '${BUSCAR}%'`));

  const mensaje = await alertas(pagina);
  comprobar('avisa de lo que se eliminó', mensaje.includes('3 gastos') && mensaje.includes('3 documentos adjuntos'), mensaje);
  comprobar('y de lo que no se pudo (mes cerrado)', mensaje.includes('Gasto #' + gastoMesCerrado) && /cerrado/i.test(mensaje), mensaje);
  comprobar('el gasto del mes cerrado sigue en la lista',
    (await pagina.locator(`#gasto-${gastoMesCerrado}`).count()) === 1);

  console.log('\n5. El gasto de mes cerrado no se puede eliminar (ni forzando el envío)');
  await pagina.check(`#gasto-${gastoMesCerrado}`);
  await pagina.fill('#motivo-eliminacion', 'Intento de borrar un mes cerrado');
  await pagina.click('[data-eliminar-gastos]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  await Promise.all([
    pagina.waitForURL(/\/admin\/contabilidad\/gastos\?/, { timeout: 20000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  const mensaje2 = await alertas(pagina);
  comprobar('el servidor lo rechaza', /cerrado/i.test(mensaje2) && mensaje2.includes('No se eliminó'), mensaje2);
  comprobar('y el gasto sigue ahí', sql(`SELECT COUNT(*) FROM gastos WHERE id = ${gastoMesCerrado}`) === '1');

  console.log('\n6. Consola del navegador');
  comprobar('sin errores de JavaScript', errores.length === 0, errores.join(' | '));

  // Los demás gastos de la lista no se ven afectados por la prueba.
  idsTodos.forEach((id) => sql(`DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id = ${id}`));
  sql(`DELETE FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);
  sql(`DELETE FROM gastos WHERE usuario_id = ${usuarioId}`);
} catch (error) {
  fallos += 1;
  console.log(`  ✗ la prueba se interrumpió — ${error.message}`);
} finally {
  if (navegador) await navegador.close().catch(() => {});

  try {
    sql(`DELETE FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);
    sql(`DELETE FROM gastos WHERE concepto LIKE '${BUSCAR}%'`);
    sql(`DELETE FROM papelera WHERE tipo = 'gasto' AND etiqueta LIKE '%${BUSCAR}%'`);
    sql(`DELETE FROM documentos WHERE subido_por = ${usuarioId}`);
    if (periodoCreado) {
      const c = contribuyente();
      sql(`DELETE FROM periodos_contables WHERE contribuyente_id = ${c} AND estado = 'cerrado'
             AND anio = ${mesAnterior.anio} AND mes = ${mesAnterior.mes}`);
    }
    sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
    for (const ruta of archivos) {
      try {
        if (existsSync(ruta)) unlinkSync(ruta);
      } catch (e) {
        // El archivo ya no está: no hay nada que limpiar.
      }
    }
  } catch (error) {
    fallos += 1;
    console.log(`  ✗ limpieza — ${error.message}`);
  }
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

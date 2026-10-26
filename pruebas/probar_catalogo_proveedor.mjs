/**
 * probar_catalogo_proveedor.mjs — Del «concepto sin equivalente» al catálogo del
 * proveedor, desde las dos pantallas que lo ofrecen.
 *
 * Recorre el requisito completo:
 *   1. Se lee la factura de un proveedor YA registrado y los conceptos que no
 *      coinciden con el catálogo propio salen en «Conceptos sin producto
 *      equivalente», cada uno con su botón «Agregar al proveedor».
 *   2. El botón da de alta el concepto en `proveedor_productos` SIN recargar la
 *      página (recargar perdería lo capturado del gasto) y la fila queda con el
 *      nombre, el código y el precio de la factura.
 *   3. Al volver a cargar la MISMA lectura, el concepto ya no está en la lista:
 *      la 2ª pasada del motor lo coteja contra el catálogo del proveedor.
 *   4. Volver a enviarlo contesta «existente»: no duplica.
 *   5. Lo mismo desde Compras Inteligentes (revisión de la factura de compra).
 *
 * Crea su propio proveedor, usuario y lecturas, y los borra al final.
 *
 * Uso: DB_NAME=cotizaciones_test node pruebas/probar_catalogo_proveedor.mjs
 */
import { chromium } from 'playwright';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
const EMAIL = 'prueba.catalogo.proveedor@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const NOMBRE_PROVEEDOR = 'Pablo Frutas del Centro';
const RFC_PROVEEDOR = 'PPL1803057K3';
const CONCEPTO_GASTO = 'Manzana golden cosmica 20 kg';
const CONCEPTO_COMPRA = 'Pera williams seleccion cosmica 10 kg';
const PRECIO = '180.00';

const sql = (consulta) =>
  execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', consulta], { encoding: 'utf8' }).trim();

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

/** Proveedor propio de la prueba; se borra todo lo que cuelga de él al final. */
function prepararProveedor() {
  sql(`DELETE FROM proveedores WHERE nombre = '${NOMBRE_PROVEEDOR}'`);
  sql(
    `INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) ` +
      `VALUES ('${NOMBRE_PROVEEDOR}', '${RFC_PROVEEDOR}', 'Material', 1)`
  );
  return Number(sql(`SELECT id FROM proveedores WHERE nombre = '${NOMBRE_PROVEEDOR}'`));
}

function limpiarProveedor(proveedorId) {
  sql(
    `DELETE FROM proveedor_producto_relaciones WHERE proveedor_producto_id IN ` +
      `(SELECT id FROM proveedor_productos WHERE proveedor_id = ${proveedorId})`
  );
  sql(`DELETE FROM proveedor_productos WHERE proveedor_id = ${proveedorId}`);
  sql(`DELETE FROM proveedores WHERE id = ${proveedorId}`);
}

function prepararUsuario() {
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba catálogo proveedor', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

/** CFDI 4.0 cuyo EMISOR es el proveedor de la prueba y cuyo concepto no existe en ningún catálogo. */
function cfdi(uuid, serie, folio, descripcion, identificacion) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
  Version="4.0" Serie="${serie}" Folio="${folio}" Fecha="2026-10-22T11:00:00" SubTotal="${PRECIO}" Total="208.80"
  Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="I">
  <cfdi:Emisor Rfc="${RFC_PROVEEDOR}" Nombre="${NOMBRE_PROVEEDOR}" RegimenFiscal="601"/>
  <cfdi:Receptor Rfc="DEMO000101A01" Nombre="Mi Taller" RegimenFiscal="601" UsoCFDI="G01"/>
  <cfdi:Conceptos>
    <cfdi:Concepto NoIdentificacion="${identificacion}" Cantidad="1" Unidad="pza" Descripcion="${descripcion}" ValorUnitario="${PRECIO}" Importe="${PRECIO}"/>
  </cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="28.80">
    <cfdi:Traslados><cfdi:Traslado Base="${PRECIO}" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000" Importe="28.80"/></cfdi:Traslados>
  </cfdi:Impuestos>
  <tfd:TimbreFiscalDigital UUID="${uuid}" FechaTimbrado="2026-10-22T11:01:00" RfcProvCertif="SAT00000000000000000000" SelloCFD="x" NoCertificado="0000" Version="1.1"/>
</cfdi:Comprobante>`;
}

const carpeta = mkdtempSync(`${tmpdir()}/catalogo-proveedor-`);
const rutaGasto = `${carpeta}/gasto.xml`;
const rutaCompra = `${carpeta}/compra.xml`;
const uuidGasto = '45000000-1111-4222-8333-000000000001';
const uuidCompra = '45000000-1111-4222-8333-000000000002';

const proveedorId = prepararProveedor();
const usuarioId = prepararUsuario();
let navegador = null;

/** Conceptos de prueba guardados en el catálogo del proveedor. */
const productosDelProveedor = () =>
  Number(sql(`SELECT COUNT(*) FROM proveedor_productos WHERE proveedor_id = ${proveedorId}`));

try {
  navegador = await chromium.launch();
  const pagina = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  const errores = [];
  pagina.on('pageerror', (error) => errores.push(String(error)));
  pagina.on('console', (mensaje) => {
    if (mensaje.type() === 'error') errores.push(mensaje.text());
  });
  writeFileSync(rutaGasto, cfdi(uuidGasto, 'T45P', '1', CONCEPTO_GASTO, 'T45P-01'));
  writeFileSync(rutaCompra, cfdi(uuidCompra, 'T45P', '2', CONCEPTO_COMPRA, 'T45P-02'));

  await pagina.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin**', { timeout: 15000 }), pagina.click('button[type="submit"]')]);

  /* El Panel de inicio (al que redirige el login) está roto por el trabajo en
   * curso del árbol: views/admin/index.php llama a Panel::ventasPorCobrar(),
   * que ya no existe en clases/Panel.php. No es de esta ronda, así que lo que
   * se vigila aquí es la consola de las pantallas SÍ relacionadas con el cambio. */
  errores.length = 0;

  console.log('\n1. La factura del proveedor deja el concepto sin equivalente');
  await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
  await pagina.click('[data-inteligente="gasto"]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  await pagina.setInputFiles('#inteligente-archivo', rutaGasto);
  await Promise.all([
    pagina.waitForURL(/\/admin\/contabilidad\/gastos\/crear\?lectura=\d+/, { timeout: 25000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  const urlGasto = pagina.url();

  comprobar('se abre el formulario de gasto con la lectura', /gastos\/crear\?lectura=\d+/.test(urlGasto), urlGasto);
  comprobar('el proveedor emisor quedó elegido en «Pagado a»',
    (await pagina.locator('#proveedor_id').inputValue()) === String(proveedorId));
  comprobar('aparece la lista de conceptos sin equivalente',
    (await pagina.locator('.tarjeta h2').filter({ hasText: 'Conceptos sin producto equivalente' }).count()) >= 1);
  comprobar('el concepto ofrece el botón de alta en el proveedor',
    (await pagina.locator('[data-agregar-proveedor]').count()) === 1);
  comprobar('y sigue estando el botón de buscar en el catálogo propio',
    (await pagina.locator('[data-cargar-busqueda]').count()) === 1);
  comprobar('el botón apunta al proveedor correcto',
    (await pagina.locator('[data-agregar-proveedor]').getAttribute('data-proveedor-nombre')) === NOMBRE_PROVEEDOR);

  console.log('\n2. El botón da de alta el concepto sin recargar');
  await pagina.click('[data-agregar-proveedor]');
  await pagina.waitForSelector('[data-acciones-concepto] .badge--aprobado', { timeout: 15000 });

  comprobar('la fila se sustituye por el aviso de alta hecha',
    (await pagina.locator('[data-acciones-concepto] .badge--aprobado').textContent())
      .includes('Agregado al catálogo de ' + NOMBRE_PROVEEDOR));
  comprobar('el botón desapareció (no se puede dar de alta dos veces)',
    (await pagina.locator('[data-agregar-proveedor]').count()) === 0);
  comprobar('quedó en la BD con nombre, código y precio de la factura',
    sql(
      `SELECT CONCAT(nombre, '|', IFNULL(codigo, ''), '|', costo_unitario, '|', activo) ` +
        `FROM proveedor_productos WHERE proveedor_id = ${proveedorId}`
    ) === `${CONCEPTO_GASTO}|T45P-01|${PRECIO}|1`,
    sql(`SELECT nombre, codigo, costo_unitario FROM proveedor_productos WHERE proveedor_id = ${proveedorId}`));
  comprobar('el formulario del gasto siguió intacto (no se recargó)',
    (await pagina.inputValue('#concepto')) === `Factura de ${NOMBRE_PROVEEDOR}`);

  console.log('\n3. Volver a enviarlo no duplica');
  const repetido = await pagina.evaluate(
    async ([provId, desc, costo]) => {
      const meta = document.querySelector('meta[name="csrf"]');
      const datos = new FormData();
      datos.append('proveedor_id', provId);
      datos.append('descripcion', desc);
      datos.append('costo', costo);
      const respuesta = await fetch('/admin/compras/catalogo/agregar', {
        method: 'POST',
        body: datos,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': meta ? meta.content : '' },
      });
      return await respuesta.json();
    },
    [String(proveedorId), CONCEPTO_GASTO, PRECIO]
  );
  comprobar('el mismo concepto se contesta «existente»', repetido.ok === true && repetido.estado === 'existente',
    JSON.stringify(repetido));
  comprobar('y sigue habiendo un solo registro', productosDelProveedor() === 1, String(productosDelProveedor()));

  console.log('\n4. Al volver a leer la factura ya se reconoce');
  await pagina.goto(urlGasto, { waitUntil: 'networkidle' });

  comprobar('ya no hay conceptos sin equivalente que mostrar',
    (await pagina.locator('[data-cargar-busqueda]').count()) === 0);
  comprobar('tampoco queda el botón de alta',
    (await pagina.locator('[data-agregar-proveedor]').count()) === 0);
  comprobar('el renglón entró como producto reconocido',
    !(await pagina.locator('body').textContent()).includes('Conceptos sin producto equivalente'));
  comprobar('la lectura y el proveedor siguen en su lugar',
    (await pagina.locator('#proveedor_id').inputValue()) === String(proveedorId)
      && (await pagina.locator('#concepto').inputValue()) === `Factura de ${NOMBRE_PROVEEDOR}`);

  console.log('\n5. Lo mismo desde Compras Inteligentes');
  await pagina.goto(`${BASE}/admin/compras/inteligente`, { waitUntil: 'domcontentloaded' });
  await pagina.setInputFiles('#archivo', rutaCompra);
  await Promise.all([
    pagina.waitForURL(/\/admin\/compras\/inteligente\/revision\?id=\d+/, { timeout: 25000 }),
    pagina.click('button[type="submit"]'),
  ]);

  // La pantalla abre un diálogo de decisión al cargar: se cierra para poder operar.
  try {
    await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
    await pagina.locator('[data-cerrar-modal]').first().click();
  } catch (error) {
    // No se abrió: no hay nada que cerrar.
  }

  const botonesRevision = await pagina.locator('[data-agregar-proveedor]').count();
  const buscarRevision = await pagina.locator('[data-cargar-busqueda]').count();
  const textoRevision = (await pagina.locator('body').textContent()).replace(/\s+/g, ' ').slice(0, 200);
  comprobar('la revisión ofrece el botón para el concepto sin equivalente',
    botonesRevision === 1,
    `botones=${botonesRevision} buscar=${buscarRevision} texto=${textoRevision}`);
  await pagina.click('[data-agregar-proveedor]');
  await pagina.waitForSelector('[data-acciones-concepto] .badge--aprobado', { timeout: 15000 });
  comprobar('la fila se sustituye por el aviso de alta hecha',
    (await pagina.locator('[data-acciones-concepto] .badge--aprobado').textContent())
      .includes('Agregado al catálogo de ' + NOMBRE_PROVEEDOR));
  comprobar('el concepto de la factura de compra quedó guardado',
    sql(
      `SELECT COUNT(*) FROM proveedor_productos WHERE proveedor_id = ${proveedorId} AND nombre = '${CONCEPTO_COMPRA}'`
    ) === '1');

  comprobar('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (error) {
  fallos += 1;
  console.log(`  ✗ la prueba se interrumpió — ${error.message}`);
} finally {
  if (navegador) await navegador.close().catch(() => {});

  // Los archivos leídos se borran con su fila (primero la lista, luego la fila).
  const raiz = dirname(dirname(fileURLToPath(import.meta.url)));
  const lecturas = sql(`SELECT IFNULL(GROUP_CONCAT(archivo), '') FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);
  for (const archivo of lecturas.split(',').filter(Boolean)) {
    try { unlinkSync(`${raiz}/storage/documentos/${archivo}`); } catch (error) { /* no existe */ }
  }
  sql(`DELETE FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);

  const proveedorFinal = Number(sql(`SELECT IFNULL(MAX(id), 0) FROM proveedores WHERE nombre = '${NOMBRE_PROVEEDOR}'`));
  if (proveedorFinal > 0) limpiarProveedor(proveedorFinal);
  sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
  try { unlinkSync(rutaGasto); } catch (error) { /* ya no está */ }
  try { unlinkSync(rutaCompra); } catch (error) { /* ya no está */ }
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

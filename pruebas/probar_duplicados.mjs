/**
 * probar_duplicados.mjs — Aviso de factura ya registrada en el registro inteligente.
 *
 * Cuando la factura que se sube ya existe, el servidor no manda derecho al
 * formulario: responde con las coincidencias y el navegador abre un diálogo con
 * cada una (folio, fecha, importe y por qué se considera duplicado), un botón
 * para abrirla y la salida para registrar de todos modos.
 *
 * Comprueba:
 *   1. Coincidencia por folio fiscal (UUID) → «Ver el gasto».
 *   2. Misma fecha E mismo importe (con el RFC del tercero) → «Ver la venta».
 *   3. Mismo día pero OTRO importe → no hay aviso: se va derecho al formulario
 *      (una venta de ese día no es esta factura).
 *   4. Mismo importe y mismo RFC con otra fecha → sí hay aviso (la fecha a veces
 *      cambia; el folio fiscal, el importe y el tercero son los que mandan).
 *   5. Sin coincidencia → se va derecho al formulario, sin diálogo.
 *
 * Crea su propio gasto de prueba y lo borra al final (junto con sus documentos
 * y lecturas). No toca los datos del cliente.
 *
 * Uso: node pruebas/probar_duplicados.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync, rmSync, unlinkSync, readdirSync, existsSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const EMAIL = 'prueba.duplicados@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const UUID_DUP = '5D858013-7568-47B5-AF10-BB5180C1C2B0';
const FOLIO_DUP = 'BEX 21324';

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

/**
 * CFDI de compra mínimo con la fecha, el importe y el receptor que se le pidan.
 * El IVA sale del total para que los importes del documento cuadren.
 */
function cfdiCompra(fecha, uuid, total = 134.56, rfcReceptor = 'DEMO000101A01') {
  const pesos = Number(total);
  const subtotal = (pesos / 1.16).toFixed(2);
  const iva = (pesos - Number(subtotal)).toFixed(2);

  return `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
  Version="4.0" Serie="Z" Folio="777" Fecha="${fecha}T10:00:00" SubTotal="${subtotal}" Total="${pesos.toFixed(2)}"
  Moneda="MXN" FormaPago="01" MetodoPago="PUE" TipoDeComprobante="E">
  <cfdi:Emisor Rfc="XAXX010101000" Nombre="PROVEEDOR DE PRUEBA DUPLICADOS"/>
  <cfdi:Receptor Rfc="${rfcReceptor}" Nombre="Receptor de prueba"/>
  <cfdi:Conceptos><cfdi:Concepto Cantidad="1" Descripcion="Concepto de prueba" ValorUnitario="${subtotal}" Importe="${subtotal}"/></cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="${iva}"><cfdi:Traslados><cfdi:Traslado Impuesto="002" Importe="${iva}"/></cfdi:Traslados></cfdi:Impuestos>
  <cfdi:Complemento><tfd:TimbreFiscalDigital UUID="${uuid}"/></cfdi:Complemento>
</cfdi:Comprobante>`;
}

/** Quita los restos de una corrida anterior (ventas, clientes y usuarios propios). */
function limpiarDePrueba() {
  // Antes que el usuario: las ventas y los clientes apuntan a él por llave foránea.
  sql(`DELETE FROM ventas WHERE folio LIKE 'VEN-2026-DUP%'`);
  sql(`DELETE FROM clientes WHERE nombre = 'Cliente de la prueba de duplicados'`);
  const id = Number(sql(`SELECT IFNULL(MAX(id), 0) FROM usuarios WHERE email = '${EMAIL}'`));
  if (id > 0) {
    sql(`DELETE FROM documentos WHERE subido_por = ${id}`);
    sql(`DELETE FROM gasto_reparto WHERE gasto_id IN (SELECT id FROM gastos WHERE usuario_id = ${id})`);
    sql(`DELETE FROM gastos WHERE usuario_id = ${id}`);
  }
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
}

function prepararUsuario() {
  limpiarDePrueba();
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba duplicados', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

/**
 * Crea la venta de referencia del caso «misma fecha e importe».
 *
 * Se crea en la prueba en lugar de buscar una existente: las otras pruebas de
 * la suite borran y crean ventas, y el resultado no puede depender de eso.
 *
 * @returns {{id: number, fecha: string, total: number, rfc: string}}
 */
function ventaDePrueba(usuarioId) {
  sql(`INSERT INTO clientes (nombre, rfc) VALUES ('Cliente de la prueba de duplicados', 'ZZDUP010101AAA')`);
  const cliente = Number(sql(`SELECT id FROM clientes WHERE nombre = 'Cliente de la prueba de duplicados'`));
  sql(
    `INSERT INTO ventas (folio, empresa_id, cliente_id, usuario_id, fecha, estado, subtotal, aplica_iva, iva, total)
     VALUES ('VEN-2026-DUP01', 1, ${cliente}, ${usuarioId}, '2026-03-17', 'registrada', 37250.47, 1, 6960.08, 43210.55)`
  );

  return {
    id: Number(sql(`SELECT id FROM ventas WHERE folio = 'VEN-2026-DUP01'`)),
    fecha: '2026-03-17',
    total: 43210.55,
    rfc: 'ZZDUP010101AAA',
  };
}

const carpeta = mkdtempSync(join(tmpdir(), 'duplicados-'));
const usuarioId = prepararUsuario();
let navegador = null;

/** Gasto ya existente con el UUID y el folio de la factura ABA. */
function gastoDePrueba() {
  const contribuyente = Number(sql('SELECT IFNULL(MIN(id), 0) FROM contribuyentes'));
  sql(
    `INSERT INTO gastos (contribuyente_id, fecha, concepto, beneficiario_tipo, beneficiario, naturaleza,
            subtotal, iva, total, estado_pago, estado, uuid_fiscal, folio_comercial, usuario_id)
     VALUES (${contribuyente}, '2026-10-21', 'Gasto ya registrado (prueba)', 'otro', 'Prueba duplicados', 'gasto',
            100, 16, 116, 'pendiente', 'vigente', '${UUID_DUP}', '${FOLIO_DUP}', ${usuarioId})`
  );
  return Number(sql('SELECT MAX(id) FROM gastos WHERE uuid_fiscal = ' + "'" + UUID_DUP + "'"));
}

async function login(pagina) {
  await pagina.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin**', { timeout: 15000 }), pagina.click('button[type="submit"]')]);
}

/** Sube un archivo al botón (venta o gasto) y espera el diálogo (o el salto al formulario). */
async function subir(pagina, rutaArchivo, destino = 'gasto') {
  await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
  await pagina.click(`[data-inteligente="${destino}"]`);
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 10000 });
  await pagina.setInputFiles('#inteligente-archivo', rutaArchivo);
  await pagina.click('[data-confirmar-modal]');
}

let gastoId = 0;

/**
 * Borra las lecturas que dejó esta corrida: sus filas y sus archivos originales
 * (el principal y los complementarios, p. ej. el PDF que se subió con el XML).
 */
function borrarLecturas(usuarioId) {
  const raiz = dirname(dirname(fileURLToPath(import.meta.url)));
  const rutas = sql(`SELECT archivo FROM lecturas_documentales WHERE creado_por = ${usuarioId}`)
    .split('\n')
    .filter(Boolean);
  const datos = sql(`SELECT datos_leidos FROM lecturas_documentales WHERE creado_por = ${usuarioId}`)
    .split('\n')
    .filter(Boolean);
  for (const json of datos) {
    try {
      for (const extra of JSON.parse(json).extras || []) {
        if (extra && extra.archivo) rutas.push(extra.archivo);
      }
    } catch (e) {
      // JSON ilegible: sólo queda el archivo principal.
    }
  }
  for (const ruta of rutas) {
    try {
      unlinkSync(join(raiz, 'storage', 'documentos', ruta));
    } catch (e) {
      // El archivo ya no está: no hay nada que limpiar.
    }
  }
  sql(`DELETE FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);
}

try {
  console.log('\n1. Factura ya registrada como gasto (folio fiscal y folio de factura)');
  gastoId = gastoDePrueba();
  comprobar('se creó el gasto de prueba', gastoId > 0, `id=${gastoId}`);

  navegador = await chromium.launch();
  const pagina = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  const errores = [];
  pagina.on('pageerror', (error) => errores.push(String(error)));

  await login(pagina);

  // El PDF se localiza en la carpeta de descargas buscando por folio: en el
  // código no se escribe la ruta con el RFC del proveedor (dato de terceros).
  const dirDescargas = join(homedir(), 'Descargas', 'gastos');
  const pdfDuplicado = existsSync(dirDescargas)
    ? readdirSync(dirDescargas).find((nombre) => nombre.endsWith('.pdf') && nombre.includes('21324'))
    : undefined;
  await subir(pagina, join(dirDescargas, pdfDuplicado ?? 'no-existe.pdf'));
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 20000 });

  const titulo = (await pagina.locator('#modal-titulo').textContent()).trim();
  comprobar('se abre el diálogo de duplicados', titulo === 'Ya hay un registro con esta factura', titulo);

  const cuerpo = (await pagina.locator('#modal-cuerpo').textContent()).replace(/\s+/g, ' ');
  comprobar('menciona que hay que revisarlo antes de guardar', cuerpo.includes('no lo dupliques'));
  comprobar('lista el gasto encontrado', cuerpo.includes(`Gasto #${gastoId}`), cuerpo.slice(0, 160));
  comprobar('muestra el folio del CFDI', cuerpo.includes('BEX 21324'));
  comprobar('explica por qué se considera duplicado', /folio fiscal \(UUID\)|folio de factura/.test(cuerpo));

  const pie = (await pagina.locator('#modal-pie').textContent()).replace(/\s+/g, ' ');
  comprobar('ofrece registrar de todos modos', pie.includes('Registrar de todos modos'), pie);
  comprobar('trae el botón para ver el gasto', (await pagina.locator(`#modal-pie button:text("Ver el gasto ${gastoId}")`).count()) === 1);

  // El botón lleva al detalle del gasto que ya existía.
  await Promise.all([
    pagina.waitForURL(new RegExp('/admin/contabilidad/gastos/detalle\\?id=' + gastoId + '$'), { timeout: 15000 }),
    pagina.click(`#modal-pie button:text("Ver el gasto ${gastoId}")`),
  ]);
  comprobar('«Ver el gasto» abre el detalle del gasto existente', /gastos\/detalle\?id=\d+/.test(pagina.url()), pagina.url());

  console.log('\n2. Misma fecha y mismo importe → avisa de la venta existente');
  // Se crea la venta de referencia con la fecha, el importe y el RFC que trae el
  // CFDI de abajo: así el caso no depende de los datos que otras pruebas de la
  // suite hayan borrado o facturado. Va sin ingreso ni folio fiscal para que el
  // aviso salga por la fecha y el importe.
  const referencia = ventaDePrueba(usuarioId);
  const idVenta = referencia.id;
  const fechaVenta = referencia.fecha;
  const totalVenta = referencia.total;
  const rfcCliente = referencia.rfc;
  comprobar('se creó la venta de referencia para probarlo', idVenta > 0, `id=${idVenta}`);

  const rutaMismoDia = join(carpeta, 'venta_mismo_dia_mismo_importe.xml');
  writeFileSync(rutaMismoDia, cfdiCompra(fechaVenta, 'MISMODIA-1111-4222-8333-BBBBCCCCDDDD', totalVenta, rfcCliente));

  await subir(pagina, rutaMismoDia, 'venta');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 20000 });

  const cuerpo2 = (await pagina.locator('#modal-cuerpo').textContent()).replace(/\s+/g, ' ');
  comprobar('explica que coincide la fecha y el importe', cuerpo2.includes('misma fecha e importe'), cuerpo2.slice(0, 200));
  comprobar(`lista la venta #${idVenta}`, cuerpo2.includes(`Venta #${idVenta}`), cuerpo2.slice(0, 200));

  const botonVenta = pagina.locator('#modal-pie button:text("Ver la venta")').first();
  comprobar('trae el botón «Ver la venta»', (await pagina.locator('#modal-pie button:text("Ver la venta")').count()) > 0);
  comprobar('ofrece también registrar de todos modos',
    (await pagina.locator('#modal-pie button:text("Registrar de todos modos")').count()) === 1);

  const idVentaBoton = (await botonVenta.textContent()).replace(/\D/g, '');
  await Promise.all([
    pagina.waitForURL(new RegExp('/admin/ventas/detalle\\?id=' + idVentaBoton + '$'), { timeout: 15000 }),
    botonVenta.click(),
  ]);
  comprobar('«Ver la venta» abre el detalle de esa venta', pagina.url().includes(`ventas/detalle?id=${idVentaBoton}`), pagina.url());

  console.log('\n3. Mismo día pero OTRO importe → no es esta factura');
  const rutaOtroImporte = join(carpeta, 'venta_mismo_dia_otro_importe.xml');
  writeFileSync(rutaOtroImporte, cfdiCompra(fechaVenta, 'OTROIMP-1111-4222-8333-FFFFEEEEAAAA', totalVenta + 137.5, rfcCliente));

  await subir(pagina, rutaOtroImporte, 'venta');
  await Promise.all([
    pagina.waitForURL(/\/admin\/ventas\/crear\?lectura=\d+/, { timeout: 20000 }),
  ]);
  comprobar('un mismo día con otro importe no avisa de duplicado',
    (await pagina.locator('#modal-principal:not([hidden])').count()) === 0);
  comprobar('se va derecho al formulario de venta', /ventas\/crear\?lectura=\d+/.test(pagina.url()), pagina.url());

  console.log('\n4. Mismo importe y mismo RFC con OTRA fecha → sí hay aviso');
  const rutaOtraFecha = join(carpeta, 'venta_otra_fecha_mismo_importe.xml');
  writeFileSync(rutaOtraFecha, cfdiCompra('2024-03-11', 'OTRAFEC-1111-4222-8333-FFFFEEEEAAAA', totalVenta, rfcCliente));

  await subir(pagina, rutaOtraFecha, 'venta');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 20000 });

  const cuerpo4 = (await pagina.locator('#modal-cuerpo').textContent()).replace(/\s+/g, ' ');
  comprobar('la fecha a veces cambia, pero el importe y el RFC coinciden',
    cuerpo4.includes('mismo importe y RFC'), cuerpo4.slice(0, 200));
  comprobar(`y lista la venta #${idVenta}`, cuerpo4.includes(`Venta #${idVenta}`), cuerpo4.slice(0, 200));

  console.log('\n5. Sin coincidencia: se va derecho al formulario');
  const rutaSinDuplicado = join(carpeta, 'compra_nueva.xml');
  writeFileSync(rutaSinDuplicado, cfdiCompra('2020-01-05', 'NUEVO00-1111-4222-8333-FFFFEEEEAAAA'));

  await subir(pagina, rutaSinDuplicado);
  await Promise.all([
    pagina.waitForURL(/\/admin\/contabilidad\/gastos\/crear\?lectura=\d+/, { timeout: 20000 }),
  ]);
  comprobar('sin duplicado no se abre ningún diálogo', (await pagina.locator('#modal-principal:not([hidden])').count()) === 0);
  comprobar('se llega al formulario de gasto', /gastos\/crear\?lectura=\d+/.test(pagina.url()));

  comprobar('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (error) {
  fallos += 1;
  console.log(`  ✗ la prueba se interrumpió — ${error.message}`);
} finally {
  if (navegador) await navegador.close().catch(() => {});

  try {
    if (gastoId > 0) {
      sql(`DELETE FROM documentos WHERE entidad = 'gasto' AND entidad_id = ${gastoId}`);
      sql(`DELETE FROM gasto_reparto WHERE gasto_id = ${gastoId}`);
      sql(`DELETE FROM gastos WHERE id = ${gastoId}`);
    }
    sql(`DELETE FROM ventas WHERE folio LIKE 'VEN-2026-DUP%'`);
    sql(`DELETE FROM clientes WHERE nombre = 'Cliente de la prueba de duplicados'`);
    borrarLecturas(usuarioId);
    sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
    rmSync(carpeta, { recursive: true, force: true });
  } catch (error) {
    fallos += 1;
    console.log(`  ✗ limpieza — ${error.message}`);
  }
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

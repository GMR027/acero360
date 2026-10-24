/**
 * probar_duplicados.mjs — Aviso de factura ya registrada en el registro inteligente.
 *
 * Cuando la factura que se sube ya existe, el servidor no manda derecho al
 * formulario: responde con las coincidencias y el navegador abre un diálogo con
 * cada una (folio, fecha, importe y por qué se considera duplicado), un botón
 * para abrirla y la salida para registrar de todos modos.
 *
 * Comprueba:
 *   1. Coincidencia por folio fiscal (UUID) y por folio de factura → «Ver el gasto».
 *   2. Coincidencia por misma fecha → «Ver la venta» (y que el botón lleva a su detalle).
 *   3. Sin coincidencia → se va derecho al formulario, sin diálogo.
 *
 * Crea su propio gasto de prueba y lo borra al final (junto con sus documentos
 * y lecturas). No toca los datos del cliente.
 *
 * Uso: node pruebas/probar_duplicados.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

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

/** CFDI de compra mínimo con la fecha que se le pida. */
function cfdiCompra(fecha, uuid) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
  Version="4.0" Serie="Z" Folio="777" Fecha="${fecha}T10:00:00" SubTotal="116.00" Total="134.56"
  Moneda="MXN" FormaPago="01" MetodoPago="PUE" TipoDeComprobante="E">
  <cfdi:Emisor Rfc="XAXX010101000" Nombre="PROVEEDOR DE PRUEBA DUPLICADOS"/>
  <cfdi:Receptor Rfc="DEMO000101A01" Nombre="Receptor de prueba"/>
  <cfdi:Conceptos><cfdi:Concepto Cantidad="1" Descripcion="Concepto de prueba" ValorUnitario="116.00" Importe="116.00"/></cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="18.56"><cfdi:Traslados><cfdi:Traslado Impuesto="002" Importe="18.56"/></cfdi:Traslados></cfdi:Impuestos>
  <cfdi:Complemento><tfd:TimbreFiscalDigital UUID="${uuid}"/></cfdi:Complemento>
</cfdi:Comprobante>`;
}

function prepararUsuario() {
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba duplicados', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
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

/** Sube un archivo al botón y espera el diálogo (o el salto al formulario). */
async function subir(pagina, rutaArchivo) {
  await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
  await pagina.click('[data-inteligente="gasto"]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 10000 });
  await pagina.setInputFiles('#inteligente-archivo', rutaArchivo);
  await pagina.click('[data-confirmar-modal]');
}

let gastoId = 0;

try {
  console.log('\n1. Factura ya registrada como gasto (folio fiscal y folio de factura)');
  gastoId = gastoDePrueba();
  comprobar('se creó el gasto de prueba', gastoId > 0, `id=${gastoId}`);

  navegador = await chromium.launch();
  const pagina = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  const errores = [];
  pagina.on('pageerror', (error) => errores.push(String(error)));

  await login(pagina);

  await subir(pagina, '/home/edgar/Descargas/gastos/factura_prueba.pdf');
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

  console.log('\n2. Factura de un día que ya tiene ventas (mismo día)');
  const fechaConVentas = sql("SELECT fecha FROM ventas WHERE estado = 'registrada' GROUP BY fecha HAVING COUNT(*) > 1 ORDER BY fecha DESC LIMIT 1");
  comprobar('hay un día con varias ventas para probarlo', fechaConVentas !== '', fechaConVentas);
  const ventasEseDia = Number(sql(`SELECT COUNT(*) FROM ventas WHERE fecha = '${fechaConVentas}' AND estado = 'registrada'`));

  const rutaXml = join(carpeta, 'compra_misma_fecha.xml');
  writeFileSync(rutaXml, cfdiCompra(fechaConVentas, 'FECHA00-1111-4222-8333-BBBBCCCCDDDD'));

  await subir(pagina, rutaXml);
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 20000 });

  const cuerpo2 = (await pagina.locator('#modal-cuerpo').textContent()).replace(/\s+/g, ' ');
  comprobar('avisa de las ventas registradas ese día', cuerpo2.includes('misma fecha'), cuerpo2.slice(0, 200));
  comprobar(`lista hasta3 de las ${ventasEseDia} ventas`, (cuerpo2.match(/Venta #/g) || []).length === Math.min(3, ventasEseDia));

  const botonVenta = pagina.locator('#modal-pie button:text("Ver la venta")').first();
  comprobar('trae el botón «Ver la venta»', (await pagina.locator('#modal-pie button:text("Ver la venta")').count()) > 0);
  comprobar('ofrece también registrar de todos modos',
    (await pagina.locator('#modal-pie button:text("Registrar de todos modos")').count()) === 1);

  const idVenta = (await botonVenta.textContent()).replace(/\D/g, '');
  await Promise.all([
    pagina.waitForURL(new RegExp('/admin/ventas/detalle\\?id=' + idVenta + '$'), { timeout: 15000 }),
    botonVenta.click(),
  ]);
  comprobar('«Ver la venta» abre el detalle de esa venta', pagina.url().includes(`ventas/detalle?id=${idVenta}`), pagina.url());

  console.log('\n3. Sin coincidencia: se va derecho al formulario');
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
    sql(`DELETE FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);
    sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
    rmSync(carpeta, { recursive: true, force: true });
  } catch (error) {
    fallos += 1;
    console.log(`  ✗ limpieza — ${error.message}`);
  }
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

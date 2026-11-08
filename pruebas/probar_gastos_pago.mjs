/**
 * pruebas/probar_gastos_pago.mjs — Abonos y complemento de pago de un gasto.
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_gastos_pago.mjs   (servidor en :8017)
 *
 * Escenario PPD real:
 *   1. El gasto muestra «Facturación y pago», método PPD y saldo pendiente.
 *   2. Se registra un abono parcial: el gasto queda «Pago parcial».
 *   3. Se carga el complemento de pago (CFDI tipo P) desde el gasto.
 *   4. La revisión muestra el monto REAL del pago y lo vincula con el abono.
 *   5. El detalle queda con «Complemento recibido» y el abono vinculado.
 *   6. La vista previa del XML explica que el Total 0.00 no es el importe.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') {
  console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).');
  process.exit(2);
}

const EMAIL = 'prueba.gastos.pago@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const UUID_GASTO = 'FA500000-0000-4000-8000-0000000000F1';
const UUID_COMP = 'FA500000-0000-4000-8000-0000000000F2';
const CONCEPTO = 'T50UI Gasto PPD';

const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
const php = (c) => execFileSync('php', ['-r', c], { env: { ...process.env, DB_NAME: DB }, encoding: 'utf8' }).trim();

let ok = 0;
let mal = 0;
const verifica = (n, c, d = '') => {
  if (c) { ok++; console.log('  ✓ ' + n); } else { mal++; console.log('  ✗ ' + n + (!c && d ? ' → ' + d : '')); }
};

/* --- Entorno ------------------------------------------------------------ */
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`DELETE FROM documentos WHERE entidad='gasto' AND entidad_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T50UI %')`);
sql(`DELETE FROM complemento_pagos_gasto WHERE pago_gasto_id IN (SELECT id FROM pagos_gasto WHERE gasto_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T50UI %'))`);
sql(`DELETE FROM complemento_doctos WHERE documento_uuid='${UUID_GASTO}'`);
sql(`DELETE FROM complementos_pago WHERE uuid='${UUID_COMP}'`);
sql(`DELETE FROM lecturas_documentales WHERE nombre_original LIKE 'T50UI%'`);
sql(`DELETE FROM pagos_gasto WHERE gasto_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T50UI %')`);
sql(`DELETE FROM gastos WHERE concepto LIKE 'T50UI %'`);

const hash = php(`echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba gastos pago','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const contrib = sql('SELECT MIN(id) FROM contribuyentes WHERE activo=1');
const rfcPropio = sql(`SELECT rfc FROM contribuyentes WHERE id=${contrib}`);

const creado = php(`require 'includes/app.php';
App\\Gastos::crear([
  'fecha' => date('Y-m-d'), 'beneficiario_tipo' => 'otro', 'beneficiario' => 'T50UI Proveedor',
  'concepto' => '${CONCEPTO}', 'subtotal' => '1000', 'iva_tasa' => '16',
  'estado_pago' => 'pendiente', 'comprobante_fiscal' => 1, 'uuid_fiscal' => '${UUID_GASTO}',
  'metodo_pago_sat' => 'PPD', 'revision_fiscal' => 'validado',
  'rfc_emisor' => 'T50U010101AA1', 'rfc_receptor' => '${rfcPropio}',
], ${usuario}); echo App\\Gastos::$ultimoId;`);
const gasto = Number(creado);
if (!gasto) {
  console.error('No se pudo crear el gasto de prueba: ' + creado);
  process.exit(2);
}

const xml = `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:pago20="http://www.sat.gob.mx/Pagos20"
  xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Fecha="${new Date().toISOString().slice(0, 10)}T10:00:00"
  SubTotal="0" Total="0" Moneda="MXN" TipoDeComprobante="P" LugarExpedicion="06000" Exportacion="01">
  <cfdi:Emisor Rfc="T50U010101AA1" Nombre="T50UI Proveedor" RegimenFiscal="612"/>
  <cfdi:Receptor Rfc="${rfcPropio}" Nombre="Negocio" DomicilioFiscalReceptor="06000" RegimenFiscalReceptor="601" UsoCFDI="G03"/>
  <cfdi:Conceptos><cfdi:Concepto ClaveProdServ="84111506" Cantidad="1" ClaveUnidad="ACT" Descripcion="Pago" ValorUnitario="0" Importe="0"/></cfdi:Conceptos>
  <cfdi:Complemento>
    <pago20:Pagos Version="2.0" MontoTotalPagos="500.00">
      <pago20:Pago FechaPago="${new Date().toISOString().slice(0, 10)}T09:00:00" FormaPagoP="03" Monto="500.00" MonedaP="MXN">
        <pago20:DoctoRelacionado IdDocumento="${UUID_GASTO}" Serie="F" Folio="1" MonedaDR="MXN"
          NumParcialidad="1" ImpSaldoAnt="1160.00" ImpPagado="500.00" ObjetoImpDR="02"/>
      </pago20:Pago>
    </pago20:Pagos>
    <tfd:TimbreFiscalDigital Version="1.1" UUID="${UUID_COMP}" FechaTimbrado="${new Date().toISOString().slice(0, 10)}T10:01:00"/>
  </cfdi:Complemento>
</cfdi:Comprobante>`;
const carpeta = mkdtempSync(join(tmpdir(), 'gastos-pago-'));
const rutaXml = join(carpeta, 'complemento.xml');
writeFileSync(rutaXml, xml);

const limpiar = () => {
  try {
    sql(`DELETE FROM documentos WHERE entidad='gasto' AND entidad_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T50UI %')`);
    sql(`DELETE FROM complemento_pagos_gasto WHERE pago_gasto_id IN (SELECT id FROM pagos_gasto WHERE gasto_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T50UI %'))`);
    sql(`DELETE FROM complemento_doctos WHERE documento_uuid='${UUID_GASTO}'`);
    sql(`DELETE FROM complementos_pago WHERE uuid='${UUID_COMP}'`);
    sql(`DELETE FROM lecturas_documentales WHERE nombre_original LIKE 'T50UI%'`);
    sql(`DELETE FROM pagos_gasto WHERE gasto_id IN (SELECT id FROM gastos WHERE concepto LIKE 'T50UI %')`);
    sql(`DELETE FROM gastos WHERE concepto LIKE 'T50UI %'`);
    sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  } finally {
    try { unlinkSync(rutaXml); } catch { /* ya no está */ }
  }
};

const navegador = await chromium.launch();
const errores = [];
try {
  const pagina = await (await navegador.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
  pagina.on('pageerror', (e) => errores.push(String(e)));
  pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });

  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('1. El gasto muestra el ciclo Factura → Pago → Complemento');
  await pagina.goto(`${BASE}/admin/contabilidad/gastos/detalle?id=${gasto}`, { waitUntil: 'domcontentloaded' });
  const textoInicial = (await pagina.locator('#facturacion-pago').innerText()).toLocaleLowerCase();
  verifica('la tarjeta de facturación y pago está visible', textoInicial.includes('facturación y pago'));
  verifica('informa el método PPD', textoInicial.includes('ppd'));
  verifica('el pago está pendiente', textoInicial.includes('pago pendiente'));
  verifica('falta el complemento de pago', textoInicial.includes('falta complemento'));
  verifica('ofrece registrar un abono', (await pagina.locator('a[data-modal-form="tpl-pagar-gasto"]').count()) === 1);

  console.log('2. Registrar un abono parcial de $500');
  await pagina.click('a[data-modal-form="tpl-pagar-gasto"]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  await pagina.fill('#modal-principal input[name="importe"]', '500');
  await pagina.selectOption('#modal-principal select[name="metodo_pago"]', 'Transferencia');
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    pagina.click('#modal-principal [data-confirmar-modal]'),
  ]);
  const textoParcial = (await pagina.locator('#facturacion-pago').innerText()).toLocaleLowerCase();
  verifica('el gasto queda en pago parcial', textoParcial.includes('pago parcial'));
  verifica('la barra muestra el avance y el saldo', /\d+\s*% pagado/.test(textoParcial) && textoParcial.includes('660.00'), textoParcial.slice(0, 400));
  verifica('el abono aparece en la lista', textoParcial.includes('500.00'));

  console.log('3. Cargar el complemento de pago desde el gasto');
  await Promise.all([
    pagina.waitForURL(/\/admin\/compras\/complemento\?gasto=\d+/, { timeout: 15000 }),
    pagina.click('a[href^="/admin/compras/complemento?gasto="]'),
  ]);
  const textoComplemento = await pagina.locator('body').innerText();
  verifica('la bandeja recuerda el gasto de origen', textoComplemento.includes('Vas a cargar el complemento'));
  await pagina.setInputFiles('#archivo', rutaXml);
  await Promise.all([
    pagina.waitForURL(/\/admin\/compras\/complemento\/revision\?id=\d+/, { timeout: 20000 }),
    pagina.locator('form button[type=submit]').first().click(),
  ]);
  const revision = await pagina.locator('body').innerText();
  verifica('la revisión muestra el monto REAL del pago', revision.includes('Monto del complemento') && revision.includes('500.00'));
  verifica('no presenta el complemento como una factura de $0', !revision.includes('Total del comprobante: $0.00'));
  verifica('localiza el gasto y sus abonos', revision.includes('T50UI Gasto PPD') && revision.includes('500.00'));
  await Promise.all([
    pagina.waitForURL(/\/admin\/compras\/complemento$/, { timeout: 20000 }),
    pagina.locator('form#form-complemento button[type=submit]').click(),
  ]);

  console.log('4. El detalle refleja el complemento vinculado');
  await pagina.goto(`${BASE}/admin/contabilidad/gastos/detalle?id=${gasto}`, { waitUntil: 'domcontentloaded' });
  const textoFinal = (await pagina.locator('#facturacion-pago').innerText()).toLocaleLowerCase();
  verifica('se informa el complemento recibido', textoFinal.includes('complemento recibido'));
  verifica('el complemento vinculado aparece con su monto', textoFinal.includes('complementos de pago vinculados') && textoFinal.includes('500.00'));

  console.log('5. Vista previa del XML: el 0 no se confunde con el importe');
  await pagina.locator('details summary', { hasText: 'Sólo adjuntar el archivo' }).click();
  await pagina.setInputFiles('#complemento-archivo', rutaXml);
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    pagina.locator('#complemento-archivo').locator('xpath=ancestor::form').locator('button[type=submit]').click(),
  ]);
  const botonVer = pagina.locator('#documentos [data-ver-documento]').first();
  await botonVer.click();
  await pagina.waitForSelector('#modal-principal:not([hidden]) .documento-preview', { timeout: 5000 });
  await pagina.waitForSelector('#modal-principal .documento-preview__resumen', { timeout: 5000 });
  const previa = (await pagina.locator('#modal-principal').innerText()).toLocaleLowerCase();
  verifica('la vista previa muestra «Monto del pago»', previa.includes('monto del pago') && previa.includes('500.00'), previa.slice(0, 500));
  verifica('explica que el Total 0.00 es normal en un complemento', previa.includes('no es una factura') && previa.includes('total del comprobante es 0.00'), previa.slice(0, 500));
  await pagina.locator('#modal-principal [data-cerrar-modal]').first().click();

  console.log('6. La lista de gastos refleja el estado');
  await pagina.goto(`${BASE}/admin/contabilidad/gastos?todos=1&q=T50UI`, { waitUntil: 'domcontentloaded' });
  const listado = (await pagina.locator('table').first().innerText()).toLocaleLowerCase();
  verifica('la lista muestra el pago parcial', listado.includes('parcial'));
  verifica('la lista muestra el complemento recibido', listado.includes('complemento'));

  console.log('7. Consola');
  verifica('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (e) {
  mal += 1;
  console.log('  ✗ se interrumpió → ' + e.message);
} finally {
  await navegador.close();
  limpiar();
}
console.log(`\nResultado: ${ok} ok, ${mal} fallas`);
process.exit(mal ? 1 : 0);

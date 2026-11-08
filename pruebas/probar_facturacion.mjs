/**
 * pruebas/probar_facturacion.mjs — Facturar una venta subiendo su XML.
 *
 *   DB_NAME=cotizaciones_test node pruebas/probar_facturacion.mjs   (servidor en :8017)
 *
 * Escenario real que reportaba errores sin salida:
 *   1. Venta normal con un cliente sin datos fiscales y sin método de pago.
 *   2. En el detalle se sube el XML ya emitido SIN elegir método a mano.
 *   3. El XML completa método, forma, uso, UUID, folio y la ficha del cliente.
 *   4. La venta queda facturada y la pantalla ya no muestra errores de emisión.
 *   5. «Corregir datos del CFDI» cambia el pago sin tocar el UUID.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync, unlinkSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }

const EMAIL = 'prueba.facturacion@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
const php = (c) => execFileSync('php', ['-r', c], { env: { ...process.env, DB_NAME: DB }, encoding: 'utf8' });

let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

/* --- Entorno ------------------------------------------------------------ */
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
const hash = php(`echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba facturación','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);

/* Cliente sin datos fiscales: el XML los va a completar. */
sql(`DELETE FROM clientes WHERE nombre_empresa='T49 Receptor facturación'`);
sql(`INSERT INTO clientes (nombre, nombre_empresa, rfc, codigo_postal, regimen_fiscal, uso_cfdi)
     VALUES ('T49 Contacto facturación','T49 Receptor facturación','AAA490101AAA','','','')`);
const cliente = sql(`SELECT id FROM clientes WHERE nombre_empresa='T49 Receptor facturación'`);

/* Empresa emisora con CP fiscal (la validación lo exige). */
const cpEmpresa = sql(`SELECT COALESCE(cp,'') FROM empresas WHERE id=1`);
sql(`UPDATE empresas SET cp='06000' WHERE id=1`);
const rfcEmpresa = sql(`SELECT COALESCE(NULLIF(rfc,''), (SELECT rfc FROM contribuyentes WHERE activo=1 ORDER BY id LIMIT 1)) FROM empresas WHERE id=1`);
const nombreEmpresa = sql(`SELECT nombre FROM empresas WHERE id=1`);

sql('UPDATE productos SET stock = 100 WHERE id = 37');
php(`require 'includes/app.php'; App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>1,'descuento_pct'=>0,'condiciones_compra'=>''],[37=>['cantidad'=>2,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`);
const venta = sql(`SELECT MAX(id) FROM ventas WHERE cliente_id=${cliente}`);
const total = sql(`SELECT total FROM ventas WHERE id=${venta}`);
const uuid = 'FA490000-0000-4000-8000-000000000049';

const xml = `<?xml version="1.0" encoding="UTF-8"?>
<Comprobante Version="4.0" Fecha="${new Date().toISOString().slice(0,10)}T10:30:00" TipoDeComprobante="I"
  SubTotal="200.00" Total="${total}" Moneda="MXN" MetodoPago="PPD" FormaPago="99"
  Serie="F49" Folio="1" LugarExpedicion="06000" Exportacion="01">
  <Emisor Rfc="${rfcEmpresa}" Nombre="${nombreEmpresa}" RegimenFiscal="612"/>
  <Receptor Rfc="AAA490101AAA" Nombre="T49 Receptor facturación"
    DomicilioFiscalReceptor="06600" RegimenFiscalReceptor="601" UsoCFDI="G03"/>
  <Conceptos>
    <Concepto ClaveProdServ="01010101" Cantidad="2" ClaveUnidad="H87"
      ValorUnitario="100.00" Importe="200.00" Descripcion="Prueba de facturación"/>
  </Conceptos>
  <Impuestos TotalImpuestosTrasladados="32.00">
    <Traslados><Traslado Impuesto="002" Base="200.00" TasaOCuota="0.160000" Importe="32.00"/></Traslados>
  </Impuestos>
  <Complemento>
    <TimbreFiscalDigital Version="1.1" UUID="${uuid}" FechaTimbrado="${new Date().toISOString().slice(0,10)}T10:31:00"/>
  </Complemento>
</Comprobante>`;
const carpeta = mkdtempSync(join(tmpdir(), 'fact49-'));
const rutaXml = join(carpeta, 'factura.xml');
writeFileSync(rutaXml, xml);

const limpiar = () => {
  try {
    const docs = sql(`SELECT archivo FROM documentos WHERE entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id=${venta})`);
    if (docs) {
      // Los archivos viven bajo el almacén; se dejan (la fila se borra igual).
    }
    sql(`DELETE FROM documentos WHERE entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id=${venta})`);
    sql(`DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE venta_id=${venta})`);
    sql(`DELETE FROM ingresos WHERE venta_id=${venta}`);
    sql(`DELETE FROM venta_detalles WHERE venta_id=${venta}`);
    sql(`DELETE FROM movimientos_inventario WHERE venta_id=${venta}`);
    sql(`DELETE FROM ventas WHERE id=${venta}`);
    sql(`DELETE FROM clientes WHERE id=${cliente}`);
    sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
  } finally {
    sql(`UPDATE empresas SET cp='${cpEmpresa.replace(/'/g, "''")}' WHERE id=1`);
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

  console.log('0. Aviso fiscal en el alta de venta');
  await pagina.goto(`${BASE}/admin/ventas/crear`, { waitUntil: 'domcontentloaded' });
  await pagina.evaluate((id) => {
    const sel = document.querySelector('#cliente_id');
    sel.value = String(id);
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }, cliente);
  await pagina.waitForFunction(() => {
    const nodo = document.querySelector('[data-estado-fiscal-cliente]');
    return nodo && !nodo.hidden && nodo.textContent.includes('Para facturar falta');
  }, { timeout: 5000 });
  const avisoFiscal = await pagina.locator('[data-estado-fiscal-cliente]').innerText();
  verifica('avisa los datos fiscales que faltan',
    avisoFiscal.includes('código postal fiscal') && avisoFiscal.includes('régimen fiscal'), avisoFiscal);

  console.log('1. Detalle antes de facturar');
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${venta}`, { waitUntil: 'domcontentloaded' });
  verifica('el formulario de facturación está visible', await pagina.locator('#form-facturacion').count() === 1);
  verifica('el método de pago empieza vacío', (await pagina.locator('#f-metodo').inputValue()) === '');
  verifica('el RFC del cliente está vacío o es el de la ficha', (await pagina.locator('#f-rfc').inputValue()) === 'AAA490101AAA');
  verifica('la pantalla empieza por el documento de la factura',
    (await pagina.locator('#form-facturacion .titulo-6').first().innerText()).includes('Documento de la factura')
    && (await pagina.locator('#doc-archivo').isVisible()));

  console.log('2. Subir el XML sin elegir método');
  await pagina.setInputFiles('#doc-archivo', rutaXml);
  await pagina.waitForFunction(() => {
    const folio = document.getElementById('factura_folio');
    const uuid = document.getElementById('uuid_fiscal');
    const metodo = document.getElementById('f-metodo');
    return folio && folio.value !== '' && uuid && uuid.value !== '' && metodo && metodo.value !== '';
  }, { timeout: 5000 });
  const xmlLeido = await pagina.locator('#xml-leido').innerText();
  verifica('el XML llena folio, UUID, fecha y pago antes de guardar',
    xmlLeido.includes('Folio') && xmlLeido.includes('UUID') && xmlLeido.includes('Método de pago'), xmlLeido.slice(0, 300));
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    pagina.locator('#form-facturacion button[type=submit]').click(),
  ]);
  const aviso = (await pagina.locator('.alerta, .nota--exito').allTextContents()).join(' | ');
  verifica('la factura se registra con el XML', /Factura registrada con su CFDI|XML de la factura vinculado/.test(aviso), aviso);

  const ing = sql(`SELECT estado_facturacion, metodo_pago, forma_pago, uso_cfdi, tipo_cfdi, uuid_fiscal
                     FROM ingresos WHERE venta_id=${venta}`);
  const [estado, metodo, forma, uso, tipo, uuidGuardado] = ing.split('\t');
  verifica('la venta queda facturada', estado === 'facturada', estado);
  verifica('el método y la forma salen del XML', metodo === 'PPD' && forma === '99', `${metodo}/${forma}`);
  verifica('el uso y el tipo salen del XML', uso === 'G03' && tipo === 'I', `${uso}/${tipo}`);
  verifica('el UUID es el del XML', uuidGuardado === uuid, uuidGuardado);
  const cli = sql(`SELECT codigo_postal, regimen_fiscal, uso_cfdi FROM clientes WHERE id=${cliente}`);
  verifica('la ficha del cliente se completa con el XML', cli === '06600\t601\tG03', cli);

  console.log('3. La pantalla ya no muestra errores de emisión');
  await pagina.goto(`${BASE}/admin/ventas/crear`, { waitUntil: 'domcontentloaded' });
  await pagina.evaluate((id) => {
    const sel = document.querySelector('#cliente_id');
    sel.value = String(id);
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }, cliente);
  await pagina.waitForFunction(() => {
    const nodo = document.querySelector('[data-estado-fiscal-cliente]');
    return nodo && !nodo.hidden && nodo.textContent.includes('completos');
  }, { timeout: 5000 });
  verifica('con la ficha completa lo indica en el alta',
    (await pagina.locator('[data-estado-fiscal-cliente]').innerText()).includes('completos'));

  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${venta}`, { waitUntil: 'domcontentloaded' });
  const texto = await pagina.locator('#facturacion').innerText();
  verifica('no hay «errores obligatorios»', !texto.includes('errores obligatorios'), texto.slice(0, 300));
  verifica('se informa la factura registrada', texto.includes('Factura registrada'));
  verifica('existe «Corregir datos del CFDI»', (await pagina.locator('summary', { hasText: 'Corregir datos del CFDI' }).count()) === 1);

  console.log('4. Corregir datos sin cambiar el UUID');
  await pagina.locator('summary', { hasText: 'Corregir datos del CFDI' }).click();
  await pagina.selectOption('#c-metodo', 'PUE');
  await pagina.selectOption('#c-forma', '03');
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    pagina.locator('#c-metodo').locator('xpath=ancestor::form').locator('button[type=submit]').click(),
  ]);
  const ing2 = sql(`SELECT metodo_pago, forma_pago, uuid_fiscal FROM ingresos WHERE venta_id=${venta}`);
  const [metodo2, forma2, uuid2] = ing2.split('\t');
  verifica('se actualiza el método y la forma', metodo2 === 'PUE' && forma2 === '03', `${metodo2}/${forma2}`);
  verifica('el UUID no cambia', uuid2 === uuid, uuid2);

  console.log('5. Facturada con CFDI pero sin método (el caso reportado)');
  sql(`UPDATE ingresos SET metodo_pago=NULL, forma_pago=NULL WHERE venta_id=${venta}`);
  sql(`UPDATE clientes SET codigo_postal='', regimen_fiscal='', uso_cfdi='' WHERE id=${cliente}`);
  await pagina.reload({ waitUntil: 'domcontentloaded' });
  const textoCaso = await pagina.locator('#facturacion').innerText();
  verifica('no pinta «errores obligatorios»', !textoCaso.includes('errores obligatorios'), textoCaso.slice(0, 300));
  verifica('sigue informando la factura registrada', textoCaso.includes('Factura registrada'));
  await pagina.locator('summary', { hasText: 'Corregir datos del CFDI' }).click();
  await pagina.fill('#c-rfc', 'AAA490101AAA');
  await pagina.fill('#c-cp', '06600');
  await pagina.selectOption('#c-regimen', '601');
  await pagina.selectOption('#c-uso', 'G03');
  await pagina.selectOption('#c-metodo', 'PPD');
  await pagina.selectOption('#c-forma', '99');
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    pagina.locator('#c-metodo').locator('xpath=ancestor::form').locator('button[type=submit]').click(),
  ]);
  const ing3 = sql(`SELECT metodo_pago, forma_pago FROM ingresos WHERE venta_id=${venta}`);
  verifica('los datos se guardan aunque el XML no traiga método', ing3 === 'PPD\t99', ing3);

  console.log('6. Facturada a mano y luego se adjunta el XML');
  php(`require 'includes/app.php'; App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>1,'descuento_pct'=>0,'condiciones_compra'=>''],[37=>['cantidad'=>2,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});`);
  const venta2 = sql(`SELECT MAX(id) FROM ventas WHERE cliente_id=${cliente}`);
  const total2 = sql(`SELECT total FROM ventas WHERE id=${venta2}`);
  sql(`UPDATE ingresos SET estado_facturacion='facturada', facturacion_manual=1, uuid_fiscal=NULL,
             factura_folio=NULL, fecha_facturacion=NULL, metodo_pago=NULL, forma_pago=NULL WHERE venta_id=${venta2}`);
  const uuid2Xml = 'FA490000-0000-4000-8000-000000000050';
  const rutaXml2 = join(carpeta, 'factura2.xml');
  writeFileSync(rutaXml2, xml.replace(`Total="${total}"`, `Total="${total2}"`).replace(uuid, uuid2Xml));
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${venta2}`, { waitUntil: 'domcontentloaded' });
  verifica('la venta facturada a mano ofrece adjuntar el XML',
    (await pagina.locator('#form-facturacion button[type=submit]').innerText()).includes('Adjuntar XML'));
  await pagina.setInputFiles('#doc-archivo', rutaXml2);
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    pagina.locator('#form-facturacion button[type=submit]').click(),
  ]);
  const ing4 = sql(`SELECT estado_facturacion, facturacion_manual, uuid_fiscal, metodo_pago FROM ingresos WHERE venta_id=${venta2}`);
  const [estado4, manual4, uuid4, metodo4] = ing4.split('\t');
  verifica('el XML se adjunta sin error y queda como respaldo',
    estado4 === 'facturada' && manual4 === '0' && uuid4 === uuid2Xml && metodo4 === 'PPD', ing4);
  sql(`DELETE FROM documentos WHERE entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id=${venta2})`);
  sql(`DELETE FROM ingresos WHERE venta_id=${venta2}`);
  sql(`DELETE FROM venta_detalles WHERE venta_id=${venta2}`);
  sql(`DELETE FROM movimientos_inventario WHERE venta_id=${venta2}`);
  sql(`DELETE FROM ventas WHERE id=${venta2}`);
  try { unlinkSync(rutaXml2); } catch { /* ya no está */ }

  console.log('7. Venta inteligente: el XML ya emitido se registra solo');
  const uuidSmart = 'FA490000-0000-4000-8000-000000000051';
  const rutaSmart = join(carpeta, 'factura-smart.xml');
  const xmlSmart = `<?xml version="1.0" encoding="UTF-8"?>
<Comprobante Version="4.0" Fecha="2026-11-07T10:30:00" TipoDeComprobante="I"
  SubTotal="360.00" Total="417.60" Moneda="MXN" MetodoPago="PPD" FormaPago="99"
  Serie="F49" Folio="2" LugarExpedicion="06000" Exportacion="01">
  <Emisor Rfc="${rfcEmpresa}" Nombre="${nombreEmpresa}" RegimenFiscal="612"/>
  <Receptor Rfc="AAA490101AAA" Nombre="T49 Receptor facturación"
    DomicilioFiscalReceptor="06600" RegimenFiscalReceptor="601" UsoCFDI="G03"/>
  <Conceptos>
    <Concepto ClaveProdServ="01010101" Cantidad="1" ClaveUnidad="H87"
      ValorUnitario="360.00" Importe="360.00" Descripcion="Registro de ángulo 40x60 cm"/>
  </Conceptos>
  <Impuestos TotalImpuestosTrasladados="57.60">
    <Traslados><Traslado Impuesto="002" Base="360.00" TasaOCuota="0.160000" Importe="57.60"/></Traslados>
  </Impuestos>
  <Complemento>
    <TimbreFiscalDigital Version="1.1" UUID="${uuidSmart}" FechaTimbrado="2026-11-07T10:31:00"/>
  </Complemento>
</Comprobante>`;
  writeFileSync(rutaSmart, xmlSmart);
  const lecturaId = php(`require 'includes/app.php'; echo App\\Lecturas::preparar([['name'=>'factura-smart.xml','tmp_name'=>${JSON.stringify(rutaSmart)},'size'=>${readFileSync(rutaSmart).length},'error'=>0]], 'venta', ${usuario}, false);`);
  verifica('la lectura del XML se prepara', /^\d+$/.test(lecturaId), lecturaId);

  if (/^\d+$/.test(lecturaId)) {
    await pagina.goto(`${BASE}/admin/ventas/crear?lectura=${lecturaId}`, { waitUntil: 'domcontentloaded' });
    verifica('el cliente del XML queda preseleccionado', (await pagina.locator('#cliente_id').inputValue()) === cliente,
      await pagina.locator('#cliente_id').inputValue());
    await pagina.waitForSelector('#cuerpo-renglones tr', { timeout: 5000 });
    verifica('el renglón del XML queda cargado',
      (await pagina.locator('#cuerpo-renglones tr').count()) >= 1);
    await Promise.all([
      pagina.waitForURL(/\/admin\/ventas\/detalle\?id=\d+/, { timeout: 20000 }),
      pagina.click('[data-cotizador-guardar]'),
    ]);
    const ventaSmart = sql(`SELECT MAX(id) FROM ventas WHERE cliente_id=${cliente}`);
    const ingSmart = sql(`SELECT estado_facturacion, uuid_fiscal, metodo_pago, forma_pago, factura_documento_id
                            FROM ingresos WHERE venta_id=${ventaSmart}`);
    const [estadoS, uuidS, metodoS, formaS, docS] = ingSmart.split('\t');
    verifica('la venta inteligente queda facturada sin un segundo paso', estadoS === 'facturada', estadoS);
    verifica('conserva el UUID y el pago del XML', uuidS === uuidSmart && metodoS === 'PPD' && formaS === '99',
      `${uuidS}/${metodoS}/${formaS}`);
    verifica('el XML quedó adjunto al ingreso', Number(docS) > 0, docS);

    sql(`DELETE FROM documentos WHERE entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id=${ventaSmart})`);
    sql(`DELETE FROM ingresos WHERE venta_id=${ventaSmart}`);
    sql(`DELETE FROM venta_detalles WHERE venta_id=${ventaSmart}`);
    sql(`DELETE FROM movimientos_inventario WHERE venta_id=${ventaSmart}`);
    sql(`DELETE FROM ventas WHERE id=${ventaSmart}`);
  }

  const archivoLectura = sql(`SELECT archivo FROM lecturas_documentales WHERE id=${lecturaId}`);
  if (archivoLectura) {
    try { unlinkSync(join(process.cwd(), 'storage/documentos', archivoLectura)); } catch { /* ya no está */ }
  }
  sql(`DELETE FROM lecturas_documentales WHERE id=${lecturaId}`);
  try { unlinkSync(rutaSmart); } catch { /* ya no está */ }

  console.log('8. Facturada con CFDI: el XML completa folio, fecha y pago');
  const uuidCompletar = 'FA490000-0000-4000-8000-000000000052';
  const rutaCompletar = join(carpeta, 'factura-completar.xml');
  const xmlCompletar = `<?xml version="1.0" encoding="UTF-8"?>
<Comprobante Version="4.0" Fecha="2026-11-08T10:30:00" TipoDeComprobante="I"
  SubTotal="200.00" Total="${total}" Moneda="MXN" MetodoPago="PPD" FormaPago="03"
  Serie="F49" Folio="3" LugarExpedicion="06000" Exportacion="01">
  <Emisor Rfc="${rfcEmpresa}" Nombre="${nombreEmpresa}" RegimenFiscal="612"/>
  <Receptor Rfc="AAA490101AAA" Nombre="T49 Receptor facturación"
    DomicilioFiscalReceptor="06600" RegimenFiscalReceptor="601" UsoCFDI="G03"/>
  <Conceptos>
    <Concepto ClaveProdServ="01010101" Cantidad="2" ClaveUnidad="H87"
      ValorUnitario="100.00" Importe="200.00" Descripcion="Prueba de facturación"/>
  </Conceptos>
  <Impuestos TotalImpuestosTrasladados="32.00">
    <Traslados><Traslado Impuesto="002" Base="200.00" TasaOCuota="0.160000" Importe="32.00"/></Traslados>
  </Impuestos>
  <Complemento>
    <TimbreFiscalDigital Version="1.1" UUID="${uuidCompletar}" FechaTimbrado="2026-11-08T10:31:00"/>
  </Complemento>
</Comprobante>`;
  writeFileSync(rutaCompletar, xmlCompletar);
  const ventaCompletar = Number(php(`require 'includes/app.php';
    App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>1,'descuento_pct'=>0,'condiciones_compra'=>''],[37=>['cantidad'=>2,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});
    $v=App\\Ventas::$ultimoId; $i=App\\Contabilidad::ingresoDeVenta($v);
    App\\Archivos::subir('ingreso', $i['id'], ['name'=>'factura-completar.xml','tmp_name'=>${JSON.stringify(rutaCompletar)},'size'=>${readFileSync(rutaCompletar).length},'error'=>0], 'auto', ${usuario}, null, '', false);
    App\\Contabilidad::actualizarFacturacion($i['id'], 'facturada', '', '${uuidCompletar}', '', ${usuario}, false);
    echo $v;`));
  verifica('se creó la venta facturada con CFDI y sin folio ni fecha', ventaCompletar > 0, String(ventaCompletar));
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${ventaCompletar}`, { waitUntil: 'domcontentloaded' });
  verifica('la venta facturada con CFDI ofrece el documento de la factura para subir el XML',
    (await pagina.locator('#doc-completar').count()) === 1
    && (await pagina.locator('#doc-completar').isVisible()));
  await pagina.setInputFiles('#doc-completar', rutaCompletar);
  await pagina.waitForSelector('#xml-leido-completar:not([hidden])', { timeout: 5000 });
  const resumenCompletar = await pagina.locator('#xml-leido-completar').innerText();
  verifica('el resumen del XML muestra folio, UUID y pago', resumenCompletar.includes('Folio') && resumenCompletar.includes('UUID') && resumenCompletar.includes('Método de pago'), resumenCompletar.slice(0, 300));
  await Promise.all([
    pagina.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
    pagina.locator('#doc-completar').locator('xpath=ancestor::form').locator('button[type=submit]').click(),
  ]);
  const avisoCompletar = (await pagina.locator('.alerta, .nota--exito').allTextContents()).join(' | ');
  verifica('el XML completa los datos sin cambiar el UUID', /Datos completados desde el XML/.test(avisoCompletar), avisoCompletar);
  const ingCompletar = sql(`SELECT factura_folio, fecha_facturacion, metodo_pago, forma_pago, uso_cfdi, uuid_fiscal, factura_documento_id
                              FROM ingresos WHERE venta_id=${ventaCompletar}`);
  const [folioC, fechaC, metodoC, formaC, soloC, uuidC, docC] = ingCompletar.split('\t');
  verifica('el folio, la fecha y el pago se llenan del XML', folioC === 'F493' && fechaC === '2026-11-08' && metodoC === 'PPD' && formaC === '03' && soloC === 'G03', ingCompletar);
  verifica('el UUID y el documento existente no cambian', uuidC === uuidCompletar && Number(docC) > 0, ingCompletar);

  const docsCompletar = sql(`SELECT archivo FROM documentos WHERE entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id=${ventaCompletar})`);
  sql(`DELETE FROM documentos WHERE entidad='ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id=${ventaCompletar})`);
  sql(`DELETE FROM ingresos WHERE venta_id=${ventaCompletar}`);
  sql(`DELETE FROM venta_detalles WHERE venta_id=${ventaCompletar}`);
  sql(`DELETE FROM movimientos_inventario WHERE venta_id=${ventaCompletar}`);
  sql(`DELETE FROM ventas WHERE id=${ventaCompletar}`);
  docsCompletar.split('\n').filter(Boolean).forEach((archivo) => {
    try { unlinkSync(join(process.cwd(), 'storage/documentos', archivo)); } catch { /* ya no está */ }
  });
  try { unlinkSync(rutaCompletar); } catch { /* ya no está */ }

  console.log('9. Consola');
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

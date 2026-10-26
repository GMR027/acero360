/**
 * probar_inteligente.mjs — Registro Venta Inteligente y Registro compra inteligente.
 *
 * Verifica que los dos botones de Contabilidad:
 *   1. existen junto a «Exportar CSV» y abren el diálogo de carga;
 *   2. leen un CFDI y abren /admin/ventas/crear con el cliente, la fecha y los
 *      renglones que sí se cotejaron con el catálogo;
 *   3. dejan los conceptos sin equivalente en su propia lista, sin tocar el catálogo;
 *   4. abren /admin/contabilidad/gastos/crear con proveedor, total, IVA y UUID,
 *      más los cuatro datos del CFDI (forma, tipo, uso y método) autocompletados;
 *   5. guardan el gasto y adjuntan solos el XML que se leyó;
 *   6. registran la venta con la lectura y dejan esos cuatro datos en su ingreso,
 *      que la revisión previa a facturar trae ya precargados.
 *
 * Uso: node pruebas/probar_inteligente.mjs
 */
import { chromium } from 'playwright';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const EMAIL = 'prueba.inteligente@cotizaciones.local';
const CLAVE = 'Prueba1234!';

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

/** Cuenta de prueba propia: las FK de usuario_id son RESTRICT, se borra al final. */
function prepararUsuarioPrueba() {
  limpiarDePrueba();
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba inteligente', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

/** Quita los restos de una corrida anterior; todo apunta al usuario de prueba. */
function limpiarDePrueba() {
  const id = Number(sql(`SELECT IFNULL(MAX(id), 0) FROM usuarios WHERE email = '${EMAIL}'`));
  if (id > 0) {
    sql(`DELETE FROM documentos WHERE subido_por = ${id}`);
    sql(`DELETE FROM gasto_reparto WHERE gasto_id IN (SELECT id FROM gastos WHERE usuario_id = ${id})`);
    sql(`DELETE FROM gastos WHERE usuario_id = ${id}`);
  }
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
}

/** CFDI 4.0 de venta: el receptor es un cliente real y dos renglones sí existen en el catálogo. */
function cfdiVenta(uuid) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
  Version="4.0" Serie="A" Folio="101" Fecha="2026-10-15T09:30:00" SubTotal="1370.00" Total="1589.20"
  Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="I">
  <cfdi:Emisor Rfc="AAA010101AAA" Nombre="Soluciones en Acero" RegimenFiscal="601"/>
  <cfdi:Receptor Rfc="XAXX010101000" Nombre="Cliente Demo 1" RegimenFiscal="601" UsoCFDI="G03"/>
  <cfdi:Conceptos>
    <cfdi:Concepto NoIdentificacion="RA-1" Cantidad="2" Unidad="pza" Descripcion="Registro de angulo 40x40 cm" ValorUnitario="360.00" Importe="720.00"/>
    <cfdi:Concepto Cantidad="1" Unidad="pza" Descripcion="Barra de acero inoxidable 1/2 pulg" ValorUnitario="650.00" Importe="650.00"/>
  </cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="219.20">
    <cfdi:Traslados><cfdi:Traslado Base="1370.00" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000" Importe="219.20"/></cfdi:Traslados>
  </cfdi:Impuestos>
  <tfd:TimbreFiscalDigital UUID="${uuid}" FechaTimbrado="2026-10-15T09:31:00" RfcProvCertif="SAT00000000000000000000" SelloCFD="x" NoCertificado="0000" Version="1.1"/>
</cfdi:Comprobante>`;
}

/** CFDI 4.0 de compra con un renglón que sí existe en el catálogo. */
function cfdiCompra(uuid) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
  Version="4.0" Serie="B" Folio="55" Fecha="2026-10-18T12:00:00" SubTotal="650.00" Total="754.00"
  Moneda="MXN" FormaPago="01" MetodoPago="PUE" TipoDeComprobante="E">
  <cfdi:Emisor Rfc="XAXX010101000" Nombre="Ferreteria de la Esquina" RegimenFiscal="601"/>
  <cfdi:Receptor Rfc="DEMO000101A01" Nombre="Mi Taller" RegimenFiscal="601" UsoCFDI="G03"/>
  <cfdi:Conceptos>
    <cfdi:Concepto NoIdentificacion="TM-1" Cantidad="1" Unidad="pza" Descripcion="Tapa metalica 60x60 sencilla" ValorUnitario="650.00" Importe="650.00"/>
  </cfdi:Conceptos>
  <cfdi:Impuestos TotalImpuestosTrasladados="104.00">
    <cfdi:Traslados><cfdi:Traslado Base="650.00" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000" Importe="104.00"/></cfdi:Traslados>
  </cfdi:Impuestos>
  <tfd:TimbreFiscalDigital UUID="${uuid}" FechaTimbrado="2026-10-18T12:01:00" RfcProvCertif="SAT00000000000000000000" SelloCFD="x" NoCertificado="0000" Version="1.1"/>
</cfdi:Comprobante>`;
}

/**
 * Foto de una factura (PNG generado con GD): no tiene texto seleccionable,
 * así que sólo se puede leer con OCR. Es el caso «me mandaron la foto por
 * WhatsApp».
 */
function escribirFotoFactura(ruta) {
  const base64 = execFileSync('php', ['-r', `
    $img = imagecreatetruecolor(1250, 800);
    $blanco = imagecolorallocate($img, 255, 255, 255);
    $negro = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $blanco);
    $fuente = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf";
    if (!is_readable($fuente)) { echo ""; exit(0); }
    $lineas = [
      60  => "FACTURA DE VENTA",
      110 => "Fecha: 15/10/2026",
      160 => "RFC Receptor: ZZY880101AAA",
      195 => "Nombre del receptor: Cliente de la etapa 24",
      250 => "Descripcion Cant Precio Importe",
      290 => "Placa de referencia 1 650.00 650.00",
      325 => "Tornillo hexagonal 200 2.50 500.00",
      380 => "Subtotal: 1150.00",
      415 => "IVA 16%: 184.00",
      450 => "Total a pagar: $1,334.00",
      500 => "UUID: 9F8E7D6C-5B4A-4C3D-8E2F-1A2B3C4D5E6F",
    ];
    foreach ($lineas as $y => $txt) { imagettftext($img, 17, 0, 40, $y, $negro, $fuente, $txt); }
    ob_start(); imagepng($img); echo base64_encode(ob_get_clean());
  `], { encoding: 'utf8' });

  if (!base64) {
    throw new Error('No hay fuente TTF ni GD para generar la foto de prueba.');
  }
  writeFileSync(ruta, Buffer.from(base64, 'base64'));
}

const carpeta = mkdtempSync(`${tmpdir()}${'/'}inteligente-`);
const rutaVenta = `${carpeta}/venta.xml`;
const rutaCompra = `${carpeta}/compra.xml`;
const uuidVenta = 'C0FFEE00-1111-4222-8333-444455556666';
const uuidCompra = 'BEEF0000-9999-4888-8777-666655554444';

const usuarioId = prepararUsuarioPrueba();
const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
const pagina = await contexto.newPage();

const erroresConsola = [];
pagina.on('pageerror', (error) => erroresConsola.push(String(error)));
pagina.on('console', (mensaje) => {
  if (mensaje.type() === 'error') {
    erroresConsola.push(mensaje.text());
  }
});

/** Venta creada durante la corrida (se borra al final, con su ingreso y sus renglones). */
let ventaCreada = 0;

try {
  writeFileSync(rutaVenta, cfdiVenta(uuidVenta));
  writeFileSync(rutaCompra, cfdiCompra(uuidCompra));

  console.log('\n1. Entrada y botones de Contabilidad');
  await pagina.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin**', { timeout: 15000 }), pagina.click('button[type="submit"]')]);

  await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
  comprobar('el botón «Registro Venta Inteligente» está junto a los de exportar', await pagina.locator('[data-inteligente="venta"]').count() === 1);
  comprobar('el botón «Registro compra inteligente» está junto a los de exportar', await pagina.locator('[data-inteligente="gasto"]').count() === 1);
  comprobar('los tres formatos de exportación siguen intactos', await pagina.locator('.formulario__acciones a[href*="formato="]').count() === 3);

  // Venta en verde y compra en rojo: se mide el color calculado, no la clase.
  const rgbDe = async (selector) =>
    (await pagina.$eval(selector, (el) => getComputedStyle(el).backgroundColor)).match(/\d+/g).map(Number);
  const [rv, gv] = await rgbDe('[data-inteligente="venta"]');
  comprobar('el botón de venta inteligente sale en verde', gv > rv && gv > 0, `rgb(${rv}, ${gv}, —)`);
  const [rg, bg] = await rgbDe('[data-inteligente="gasto"]');
  comprobar('el botón de compra inteligente sale en rojo', rg > bg && bg > 0, `rgb(${rg}, ${bg}, —)`);

  console.log('\n2. Registro Venta Inteligente');
  await pagina.click('[data-inteligente="venta"]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  comprobar('abre el diálogo de carga', await pagina.locator('#modal-principal:not([hidden])').count() === 1);
  comprobar('el diálogo acepta XML, PDF y fotos', (await pagina.getAttribute('#inteligente-archivo', 'accept')) === '.xml,.pdf,.jpg,.jpeg,.png,.webp');

  await pagina.setInputFiles('#inteligente-archivo', rutaVenta);
  await Promise.all([
    pagina.waitForURL(/\/admin\/ventas\/crear\?lectura=\d+/, { timeout: 20000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  comprobar('lleva al formulario de venta con la lectura cargada', /\/admin\/ventas\/crear\?lectura=\d+/.test(pagina.url()));

  comprobar('la fecha de la factura quedó precargada', (await pagina.inputValue('#fecha')) === '2026-10-15');
  const clienteId = await pagina.locator('#cliente_id option:checked').getAttribute('value');
  comprobar('el cliente receptor de la factura quedó elegido', Number(clienteId) > 0, `cliente_id=${clienteId}`);

  const renglones = await pagina.locator('#cuerpo-renglones tr').count();
  comprobar('sólo entró el renglón con producto equivalente', renglones === 1, `renglones=${renglones}`);
  comprobar('el renglón trae SKU y nombre del catálogo', (await pagina.locator('#cuerpo-renglones tr').first().textContent()).includes('RA-1'));
  comprobar('la cantidad vino del documento', (await pagina.locator('#cuerpo-renglones input[name$="[cantidad]"]').first().inputValue()) === '2');

  comprobar('el concepto sin equivalente aparece en su lista', await pagina.locator('[data-cargar-busqueda]').count() === 1);
  comprobar('no se creó ningún producto en el catálogo', Number(sql('SELECT COUNT(*) FROM productos WHERE nombre LIKE "%inoxidable%"')) === 0);
  comprobar('el aviso aclara que nada se ha guardado todavía', (await pagina.locator('[data-lectura-cargada]').textContent()).includes('nada se ha registrado'));

  comprobar('el formulario de venta conserva la lectura al enviar',
    ((await pagina.getAttribute('form.cotizador', 'action')) || '').includes('lectura='));

  await pagina.click('[data-cargar-busqueda]');
  comprobar('el botón llena el buscador del catálogo', (await pagina.inputValue('#buscar-producto')).includes('inoxidable'));

  // Se REGISTRA la venta: los cuatro datos del CFDI pasan al ingreso y la
  // revisión previa a facturar los trae precargados (la ficha del cliente no
  // trae uso de CFDI, así que lo que se ve viene del documento).
  await Promise.all([
    pagina.waitForURL(/\/admin\/ventas\/detalle\?id=\d+/, { timeout: 20000 }),
    pagina.click('[data-cotizador-guardar]'),
  ]);
  ventaCreada = Number(new URL(pagina.url()).searchParams.get('id'));
  comprobar('el formulario registra la venta con la lectura cargada', ventaCreada > 0, pagina.url());
  comprobar('los cuatro datos del CFDI pasan al ingreso de la venta',
    sql(`SELECT CONCAT(IFNULL(forma_pago, '-'), '|', IFNULL(tipo_cfdi, '-'), '|', IFNULL(uso_cfdi, '-'), '|', IFNULL(metodo_pago, '-')) FROM ingresos WHERE venta_id = ${ventaCreada}`) === '03|I|G03|PUE');

  await pagina.click('#btn-revision-fiscal');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  const valorDe = async (id) => pagina.locator(`#${id} option:checked`).getAttribute('value');
  comprobar('la revisión previa trae uso, método, forma y tipo precargados',
    (await valorDe('f-uso')) === 'G03' && (await valorDe('f-metodo')) === 'PUE'
    && (await valorDe('f-forma')) === '03' && (await valorDe('f-tipo')) === 'I',
    `uso=${await valorDe('f-uso')} metodo=${await valorDe('f-metodo')} forma=${await valorDe('f-forma')} tipo=${await valorDe('f-tipo')}`);
  await pagina.keyboard.press('Escape');

  console.log('\n3. Registro compra inteligente');
  await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
  await pagina.click('[data-inteligente="gasto"]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  await pagina.setInputFiles('#inteligente-archivo', rutaCompra);
  await Promise.all([
    pagina.waitForURL(/\/admin\/contabilidad\/gastos\/crear\?lectura=\d+/, { timeout: 20000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  comprobar('lleva al formulario de gasto con la lectura cargada', /gastos\/crear\?lectura=\d+/.test(pagina.url()));

  comprobar('la fecha del documento quedó precargada', (await pagina.inputValue('#fecha')) === '2026-10-18');
  comprobar('el total quedó precargado', (await pagina.inputValue('#total')) === '754.00');
  comprobar('la tasa de IVA se dedujo del documento', (await pagina.locator('#iva_tasa option:checked').getAttribute('value')) === '16');
  comprobar('quedó marcado como gasto con factura', (await pagina.locator('#comprobante_fiscal option:checked').getAttribute('value')) === '1');
  comprobar('el folio fiscal (UUID) quedó precargado', (await pagina.inputValue('#uuid_fiscal')) === uuidCompra);
  comprobar('quedó clasificado como compra', (await pagina.locator('#naturaleza option:checked').getAttribute('value')) === 'compra');
  comprobar('el concepto se armó con el emisor', (await pagina.inputValue('#concepto')).includes('Ferreteria de la Esquina'));
  comprobar('la categoría se sugirió por el proveedor', Number(await pagina.locator('#categoria_id option:checked').getAttribute('value')) > 0);

  // Los cuatro datos del CFDI se autocompletan solos, en sus propios campos visibles.
  comprobar('la forma de pago quedó precargada y visible', (await pagina.locator('#forma_pago_sat option:checked').getAttribute('value')) === '01');
  comprobar('el tipo de comprobante quedó precargado', (await pagina.locator('#tipo_cfdi option:checked').getAttribute('value')) === 'E');
  comprobar('el uso del CFDI quedó precargado', (await pagina.locator('#uso_cfdi option:checked').getAttribute('value')) === 'G03');
  comprobar('el método de pago quedó precargado', (await pagina.locator('#metodo_pago_sat option:checked').getAttribute('value')) === 'PUE');

  const gastosAntes = Number(sql('SELECT COUNT(*) FROM gastos'));
  await pagina.click('button[type="submit"]');
  await pagina.waitForURL(/\/admin\/contabilidad\/gastos\/detalle\?id=\d+/, { timeout: 20000 });
  comprobar('el gasto se guardó con los datos leídos', Number(sql('SELECT COUNT(*) FROM gastos')) === gastosAntes + 1);

  const gastoId = Number(new URL(pagina.url()).searchParams.get('id'));
  comprobar('el XML leído se adjuntó solo al gasto', Number(sql(`SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id = ${gastoId}`)) === 1);
  comprobar('el gasto guarda el UUID del CFDI', sql(`SELECT uuid_fiscal FROM gastos WHERE id = ${gastoId}`) === uuidCompra);
  comprobar('el gasto guarda los datos del CFDI', sql(`SELECT COUNT(*) FROM documentos WHERE entidad = 'gasto' AND entidad_id = ${gastoId} AND datos_cfdi IS NOT NULL`) === '1');
  comprobar('el gasto guarda los cuatro datos del CFDI',
    sql(`SELECT CONCAT(IFNULL(forma_pago_sat, '-'), '|', IFNULL(tipo_cfdi, '-'), '|', IFNULL(uso_cfdi, '-'), '|', IFNULL(metodo_pago_sat, '-')) FROM gastos WHERE id = ${gastoId}`) === '01|E|G03|PUE');

  console.log('\n4. Foto de factura (OCR)');
  const rutaFoto = `${carpeta}/factura.png`;
  escribirFotoFactura(rutaFoto);

  await pagina.goto(`${BASE}/admin/contabilidad`, { waitUntil: 'domcontentloaded' });
  await pagina.click('[data-inteligente="gasto"]');
  await pagina.waitForSelector('#modal-principal:not([hidden])', { timeout: 5000 });
  const ayuda = await pagina.locator('#modal-principal .campo__ayuda').last().textContent();
  comprobar('el diálogo avisa que las fotos y PDF escaneados se leen con OCR', ayuda.includes('OCR') && !ayuda.includes('no se pueden leer'), ayuda);

  await pagina.setInputFiles('#inteligente-archivo', rutaFoto);
  await Promise.all([
    pagina.waitForURL(/\/admin\/contabilidad\/gastos\/crear\?lectura=\d+/, { timeout: 60000 }),
    pagina.click('[data-confirmar-modal]'),
  ]);
  comprobar('la foto se leyó y abrió el formulario de gasto', /gastos\/crear\?lectura=\d+/.test(pagina.url()));
  comprobar('el total salió del OCR', (await pagina.inputValue('#total')) === '1334.00', await pagina.inputValue('#total'));
  comprobar('la fecha salió del OCR', (await pagina.inputValue('#fecha')) === '2026-10-15');
  comprobar('el UUID salió del OCR', (await pagina.inputValue('#uuid_fiscal')) === '9F8E7D6C-5B4A-4C3D-8E2F-1A2B3C4D5E6F');
  comprobar('el aviso recuerda que el OCR hay que revisarlo', (await pagina.locator('[data-lectura-cargada]').textContent()).toLowerCase().includes('ocr'));

  const renglonesOcr = await pagina.locator('[data-cargar-busqueda]').count();
  comprobar('los renglones de la tabla también salieron del OCR', renglonesOcr === 2, `renglones sin equivalente=${renglonesOcr}`);

  console.log('\n5. Consola del navegador');
  comprobar('sin errores de JavaScript', erroresConsola.length === 0, erroresConsola.join(' | '));
} catch (error) {
  fallos += 1;
  console.log(`  ✗ la prueba se interrumpió — ${error.message}`);
} finally {
  await navegador.close();

  // Se limpia todo lo de la corrida, empezando por lo que depende del usuario
  // (las FK de `usuarios.id` son RESTRICT y no dejarían huérfanos).
  const rutasLecturas = sql(`SELECT archivo FROM lecturas_documentales WHERE creado_por = ${usuarioId}`)
    .split('\n')
    .filter(Boolean);
  for (const ruta of rutasLecturas) {
    try {
      unlinkSync(`${dirname(dirname(fileURLToPath(import.meta.url)))}/storage/documentos/${ruta}`);
    } catch (error) {
      // El archivo ya no está: no hay nada que limpiar.
    }
  }
  sql(`DELETE FROM lecturas_documentales WHERE creado_por = ${usuarioId}`);
  // La venta creada en el paso 2 se borra con todo lo que cuelga de ella.
  if (ventaCreada > 0) {
    sql(`DELETE FROM cobros WHERE ingreso_id IN (SELECT id FROM ingresos WHERE venta_id = ${ventaCreada})`);
    sql(`DELETE FROM documentos WHERE entidad = 'ingreso' AND entidad_id IN (SELECT id FROM ingresos WHERE venta_id = ${ventaCreada})`);
    sql(`DELETE FROM ingresos WHERE venta_id = ${ventaCreada}`);
    sql(`DELETE FROM venta_detalles WHERE venta_id = ${ventaCreada}`);
    sql(`DELETE FROM movimientos_inventario WHERE venta_id = ${ventaCreada}`);
    sql(`DELETE FROM ventas WHERE id = ${ventaCreada}`);
  }
  limpiarDePrueba();
  sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
}

console.log(`\n${correctas} comprobaciones correctas, ${fallos} fallos.`);
process.exit(fallos ? 1 : 0);

/**
 * probar_proveedor_gasto.mjs — Proveedor desde «Pagado a» en el gasto.
 *
 * Recorre el requisito completo:
 *
 *   1. Al elegir «Proveedor» en /admin/contabilidad/gastos/crear aparece un
 *      campo de búsqueda que filtra el directorio por NOMBRE y RFC, y el RFC
 *      del elegido queda visible bajo el campo.
 *   2. Si lo escrito no coincide con ningún proveedor, aparece el botón para
 *      registrar ese proveedor.
 *   3. El botón lleva el texto capturado a /admin/proveedores/crear (nombre y
 *      RFC emisor del gasto) y, al guardarlo, se vuelve al formulario del gasto
 *      con lo capturado precargado y el proveedor nuevo YA elegido.
 *   4. El gasto se guarda con el proveedor nuevo.
 *
 * Crea su propio proveedor, usuario y gasto, y los borra al final.
 *
 * Uso: DB_NAME=cotizaciones_test node pruebas/probar_proveedor_gasto.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const DB = process.env.DB_NAME || 'cotizaciones';
const EMAIL = 'prueba.proveedor.gasto@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const NOMBRE_EXISTENTE = 'T99 Ferretería del Alba Prueba';
const RFC_EXISTENTE = 'T99010101AA1';
const TEXTO_INEXISTENTE = 'ZZQQ Proveedor Que No Existe 9999';
const RFC_EMISOR = 'ZZQQ010101A1';
const CONCEPTO = 'Compra de prueba para alta de proveedor desde el gasto';
const TOTAL = '1234.56';

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

function prepararUsuario() {
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba proveedor gasto', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

/** Proveedor que ya está en el directorio antes de abrir el formulario. */
function prepararProveedor() {
  sql(`DELETE FROM proveedores WHERE nombre = '${NOMBRE_EXISTENTE}'`);
  sql(
    `INSERT INTO proveedores (nombre, rfc, tipo_proveedor, activo) ` +
      `VALUES ('${NOMBRE_EXISTENTE}', '${RFC_EXISTENTE}', 'Material', 1)`
  );
  return Number(sql(`SELECT id FROM proveedores WHERE nombre = '${NOMBRE_EXISTENTE}'`));
}

function limpiarGasto() {
  sql('DELETE g FROM gasto_reparto g JOIN gastos x ON x.id = g.gasto_id WHERE x.concepto = ' + `'${CONCEPTO}'`);
  sql(`DELETE FROM gastos WHERE concepto = '${CONCEPTO}'`);
}

const usuarioId = prepararUsuario();
const proveedorExistenteId = prepararProveedor();
let navegador = null;
/** Proveedores activos antes del caso del directorio vacío (para restaurarlos). */
let activosAntes = '';

try {
  navegador = await chromium.launch();
  const pagina = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  const errores = [];
  pagina.on('pageerror', (error) => errores.push(String(error)));

  await pagina.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin**', { timeout: 15000 }), pagina.click('button[type="submit"]')]);

  await pagina.goto(`${BASE}/admin/contabilidad/gastos/crear`, { waitUntil: 'networkidle' });

  console.log('\n1. «Pagado a → Proveedor» abre un buscador por nombre y RFC');
  await pagina.selectOption('#beneficiario_tipo', 'proveedor');
  await pagina.waitForTimeout(100);

  comprobar('el selector real se oculta y queda como fuente de verdad del envío',
    await pagina.locator('#proveedor_id').isHidden());
  comprobar('aparece el campo de búsqueda',
    (await pagina.locator('#proveedor_id-busqueda').count()) === 1);
  comprobar('el buscador invita a escribir nombre o RFC',
    (await pagina.locator('#proveedor_id-busqueda').getAttribute('placeholder') || '').includes('RFC'));

  await pagina.fill('#proveedor_id-busqueda', NOMBRE_EXISTENTE);
  await pagina.waitForTimeout(150);

  comprobar('la lista muestra el RFC del proveedor',
    (await pagina.locator('.selector-busqueda__rfc').first().textContent() || '').includes(RFC_EXISTENTE));
  comprobar('el botón de alta NO aparece porque sí hay coincidencia',
    await pagina.locator('[data-registrar-proveedor]').isHidden());

  await pagina.keyboard.press('Enter');
  await pagina.waitForTimeout(150);

  comprobar('al elegirlo, el campo queda con nombre y RFC',
    (await pagina.locator('#proveedor_id-busqueda').inputValue()).includes(NOMBRE_EXISTENTE)
      && (await pagina.locator('#proveedor_id-busqueda').inputValue()).includes(RFC_EXISTENTE));
  comprobar('el <select> recibe el id del proveedor',
    (await pagina.locator('#proveedor_id').inputValue()) === String(proveedorExistenteId));
  comprobar('el RFC elegido se muestra bajo el campo',
    (await pagina.locator('[data-proveedor-rfc]').textContent() || '').includes(RFC_EXISTENTE));

  console.log('\n2. Lo que se captura antes de salir');
  await pagina.fill('#concepto', CONCEPTO);
  await pagina.fill('#total', TOTAL);
  await pagina.selectOption('#comprobante_fiscal', '0');
  await pagina.evaluate(() => {
    // El RFC emisor lo deja el XML leído en el gasto: es el RFC del proveedor.
    document.querySelector('[name="rfc_emisor"]').value = 'ZZQQ010101A1';
  });

  console.log('\n3. Si el proveedor no existe aparece el botón para registrarlo');
  await pagina.fill('#proveedor_id-busqueda', TEXTO_INEXISTENTE);
  await pagina.waitForTimeout(200);

  const boton = pagina.locator('[data-registrar-proveedor]');
  comprobar('el botón de alta se muestra sin coincidencias', await boton.isVisible());
  comprobar('el botón repite lo que se escribió',
    (await boton.textContent() || '').includes(TEXTO_INEXISTENTE));

  await Promise.all([
    pagina.waitForURL('**/admin/proveedores/crear*', { timeout: 15000 }),
    boton.click(),
  ]);

  console.log('\n4. La alta trae lo capturado en el gasto');
  await pagina.waitForSelector('#nombre');
  comprobar('el nombre escrito se precarga', (await pagina.locator('#nombre').inputValue()) === TEXTO_INEXISTENTE);
  comprobar('el RFC emisor del gasto se precarga', (await pagina.locator('#rfc').inputValue()) === RFC_EMISOR);
  comprobar('la URL de regreso apunta al formulario del gasto',
    (pagina.url()).includes('/admin/proveedores/crear') && decodeURIComponent(pagina.url()).includes('/admin/contabilidad/gastos/crear'));

  await Promise.all([
    pagina.waitForURL('**/admin/contabilidad/gastos/crear*', { timeout: 15000 }),
    pagina.click('button[type="submit"]'),
  ]);
  await pagina.waitForTimeout(300);

  const proveedorNuevoId = Number(sql(
    `SELECT id FROM proveedores WHERE nombre = '${TEXTO_INEXISTENTE}'`
  ));
  comprobar('el proveedor se guardó en el directorio', proveedorNuevoId > 0);

  console.log('\n5. De vuelta en el gasto con la información precapturada');
  comprobar('la URL de regreso trae el proveedor nuevo',
    pagina.url().includes(`proveedor=${proveedorNuevoId}`));
  comprobar('el proveedor nuevo ya está elegido en el <select>',
    (await pagina.locator('#proveedor_id').inputValue()) === String(proveedorNuevoId));
  comprobar('el buscador muestra el proveedor nuevo',
    (await pagina.locator('#proveedor_id-busqueda').inputValue()).includes(TEXTO_INEXISTENTE));
  comprobar('«Pagado a» sigue en Proveedor',
    (await pagina.locator('#beneficiario_tipo').inputValue()) === 'proveedor');
  comprobar('el concepto capturado se restauró',
    (await pagina.locator('#concepto').inputValue()) === CONCEPTO);
  comprobar('el total capturado se restauró',
    (await pagina.locator('#total').inputValue()) === TOTAL);
  comprobar('el tipo de comprobante se restauró',
    (await pagina.locator('#comprobante_fiscal').inputValue()) === '0');
  comprobar('el RFC del proveedor nuevo se muestra bajo el campo',
    (await pagina.locator('[data-proveedor-rfc]').textContent() || '').includes(RFC_EMISOR));
  comprobar('el borrador se consumió (no queda nada en la pestaña)',
    (await pagina.evaluate(() => window.sessionStorage.length)) === 0);

  console.log('\n6. El gasto se guarda con ese proveedor');
  await Promise.all([
    pagina.waitForURL('**/admin/contabilidad/gastos/detalle*', { timeout: 15000 }),
    pagina.click('button[type="submit"]'),
  ]);

  const fila = sql(
    `SELECT CONCAT(beneficiario_tipo, '|', IFNULL(proveedor_id, 0), '|', beneficiario) ` +
      `FROM gastos WHERE concepto = '${CONCEPTO}' ORDER BY id DESC LIMIT 1`
  ).split('|');
  comprobar('el gasto quedó ligado al proveedor nuevo',
    fila[0] === 'proveedor' && Number(fila[1]) === proveedorNuevoId,
    fila.join(' / '));
  comprobar('el gasto copió el nombre del proveedor como beneficiario',
    fila[2] === TEXTO_INEXISTENTE, fila[2]);

  console.log('\n7. Con el directorio vacío también se puede registrar');
  activosAntes = sql('SELECT IFNULL(GROUP_CONCAT(id), "") FROM proveedores WHERE activo = 1');
  sql('UPDATE proveedores SET activo = 0 WHERE activo = 1');
  await pagina.goto(`${BASE}/admin/contabilidad/gastos/crear`, { waitUntil: 'networkidle' });
  await pagina.selectOption('#beneficiario_tipo', 'proveedor');
  await pagina.waitForTimeout(150);

  comprobar('sin proveedores activos no se construye el buscador',
    (await pagina.locator('#proveedor_id-busqueda').count()) === 0);
  comprobar('el botón de alta se ofrece igualmente',
    await pagina.locator('[data-registrar-proveedor]').isVisible());

  comprobar('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (error) {
  fallos += 1;
  console.log(`  ✗ la prueba se interrumpió — ${error.message}`);
} finally {
  if (navegador) await navegador.close().catch(() => {});
  if (activosAntes !== '') {
    sql(`UPDATE proveedores SET activo = 1 WHERE id IN (${activosAntes})`);
  }
  limpiarGasto();
  sql(`DELETE FROM proveedores WHERE nombre = '${TEXTO_INEXISTENTE}' OR nombre = '${NOMBRE_EXISTENTE}'`);
  sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

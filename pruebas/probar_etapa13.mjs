/**
 * pruebas/probar_etapa13.mjs — Lectura inteligente: cargar XML, pantalla de revisión, confirmar, duplicado y captura manual.
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa13.mjs   (servidor en :8017)
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa13@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

for (const t of ['lecturas_documentales', 'documentos', 'gasto_reparto', 'gastos']) { sql(`DELETE FROM ${t}`); }
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E13','${EMAIL}','${hash}',1)`);
const rfc = sql('SELECT rfc FROM contribuyentes ORDER BY id LIMIT 1');
const hoy = new Date().toISOString().slice(0, 10);
const carpeta = mkdtempSync(join(tmpdir(), 'e13-'));
const xml = join(carpeta, 'factura.xml');
writeFileSync(xml, `<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Serie="Z" Folio="9" Fecha="${hoy}T09:00:00" SubTotal="500.00" Total="580.00" Moneda="MXN" FormaPago="03" MetodoPago="PUE" TipoDeComprobante="I">
<cfdi:Emisor Rfc="AAA010101AAA" Nombre="Proveedor Prueba SA"/><cfdi:Receptor Rfc="${rfc}" Nombre="Mi Taller"/>
<cfdi:Conceptos><cfdi:Concepto Descripcion="Solera 1/4" Cantidad="5" ValorUnitario="100.00" Importe="500.00"/></cfdi:Conceptos>
<cfdi:Impuestos TotalImpuestosTrasladados="80.00"><cfdi:Traslados><cfdi:Traslado Impuesto="002" Importe="80.00"/></cfdi:Traslados></cfdi:Impuestos>
<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="0A1B2C3D-0000-4000-8000-123456789ABC"/></cfdi:Complemento></cfdi:Comprobante>`);
const png = join(carpeta, 'ticket.png');
execFileSync('php', ['-r', `$i=imagecreatetruecolor(30,30);imagepng($i,'${png}');`]);

const navegador = await chromium.launch();
try {
  const pagina = await (await navegador.newContext()).newPage();
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Carga y revisión');
  await pagina.goto(BASE + '/admin/contabilidad/gastos');
  verifica('el listado de gastos ofrece «Leer factura»', await pagina.locator('a:has-text("Leer factura")').count() > 0);
  await pagina.goto(BASE + '/admin/contabilidad/lectura?destino=gasto');
  await pagina.setInputFiles('#archivo', xml);
  await Promise.all([pagina.waitForURL('**/lectura/revisar**'), pagina.click('button:has-text("Leer documento")')]);
  verifica('muestra la pantalla de revisión', await pagina.locator('h1, h2').filter({ hasText: /Revisar datos leídos/ }).count() > 0);
  verifica('UUID y folio comercial en campos distintos', await pagina.inputValue('#f_uuid') === '0A1B2C3D-0000-4000-8000-123456789ABC' && await pagina.inputValue('#f_folio_comercial') === 'Z9');
  verifica('total y fecha leídos', await pagina.inputValue('#f_total') === '580.00' && await pagina.inputValue('#f_fecha') === hoy);
  verifica('todavía no existe ningún gasto', sql('SELECT COUNT(*) FROM gastos') === '0');

  console.log('Confirmación');
  await pagina.fill('#beneficiario', 'Proveedor Prueba SA');
  await pagina.fill('#concepto', 'Solera');
  await Promise.all([pagina.waitForURL('**/gastos/detalle**'), pagina.click('button:has-text("Confirmar datos")')]);
  verifica('crea el gasto', sql('SELECT COUNT(*) FROM gastos') === '1');
  verifica('guarda UUID y folio por separado', sql('SELECT CONCAT(uuid_fiscal, "|", folio_comercial) FROM gastos') === '0A1B2C3D-0000-4000-8000-123456789ABC|Z9');
  verifica('registra quién y cuándo confirmó', sql("SELECT COUNT(*) FROM lecturas_documentales WHERE estado='confirmada' AND confirmado_por IS NOT NULL AND confirmado_en IS NOT NULL") === '1');

  console.log('Duplicado');
  await pagina.goto(BASE + '/admin/contabilidad/lectura?destino=gasto');
  await pagina.setInputFiles('#archivo', xml);
  await Promise.all([pagina.waitForURL('**/lectura/revisar**'), pagina.click('button:has-text("Leer documento")')]);
  verifica('avisa del posible duplicado', await pagina.locator('text=Posible duplicado').count() > 0);
  verifica('el botón de confirmar queda deshabilitado', await pagina.locator('button:has-text("Confirmar datos")').isDisabled());

  console.log('Captura manual');
  await pagina.goto(BASE + '/admin/contabilidad/lectura?destino=gasto');
  await pagina.setInputFiles('#archivo', png);
  await Promise.all([pagina.waitForURL('**/lectura/revisar**'), pagina.click('button:has-text("Leer documento")')]);
  verifica('imagen sin lectura: campos vacíos y editables', await pagina.inputValue('#f_total') === '');
  await pagina.fill('#f_fecha', hoy); await pagina.fill('#f_total', '58'); await pagina.fill('#beneficiario', 'Tienda'); await pagina.fill('#concepto', 'Ticket');
  await Promise.all([pagina.waitForURL('**/gastos/detalle**'), pagina.click('button:has-text("Confirmar datos")')]);
  verifica('se guarda con captura manual', sql('SELECT COUNT(*) FROM gastos') === '2');
} finally {
  await navegador.close();
}
console.log(`\nResultado: ${ok} correctas · ${mal} con fallos`);
process.exit(mal ? 1 : 0);

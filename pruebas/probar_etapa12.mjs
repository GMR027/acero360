/**
 * pruebas/probar_etapa12.mjs — Control de facturación en la venta (detalle), listas separadas.
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa12.mjs   (servidor en :8017)
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa12@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const RAIZ = new URL('..', import.meta.url).pathname;
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };
const anio = new Date().getFullYear();

for (const t of ['documentos', 'cobros', 'ingresos', 'entregas', 'preparaciones']) { sql(`DELETE FROM ${t}`); }
sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
sql('UPDATE productos SET stock = 100 WHERE id = 37');
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E12','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const cliente = sql('SELECT id FROM clientes ORDER BY id LIMIT 1');
execFileSync('php', ['-r', `require '${RAIZ}/includes/app.php';
foreach ([1, 0] as $iva) {
  App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>${cliente},'fecha'=>date('Y-m-d'),'aplica_iva'=>$iva,'descuento_pct'=>0],[37=>['cantidad'=>1,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1]],${usuario});
}`], { env: { ...process.env, DB_NAME: DB } });
const [vIva, vSin] = sql("SELECT id FROM ventas ORDER BY id").split('\n');

const navegador = await chromium.launch();
try {
  const pagina = await (await navegador.newContext()).newPage();
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Control visible en la venta');
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${vIva}`);
  const panel = pagina.locator('#facturacion');
  verifica('venta con IVA: pendiente de facturar, con IVA', (await panel.innerText()).toLowerCase().includes('pendiente de facturar') && (await panel.innerText()).toLowerCase().includes('con iva'));
  verifica('sin CFDI avisa que será confirmación manual', (await panel.innerText()).includes('No hay un CFDI adjunto'));
  await pagina.selectOption('#fv_estado', 'facturada');
  await pagina.click('#facturacion button[type=submit]');
  verifica('sin confirmar no se factura', sql(`SELECT estado_facturacion FROM ingresos WHERE venta_id=${vIva}`) === 'sin_clasificar');
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${vIva}`);
  await pagina.selectOption('#fv_estado', 'facturada');
  await pagina.check('#fv_manual');
  await pagina.click('#facturacion button[type=submit]');
  verifica('confirmada a mano: facturada y marcada manual', sql(`SELECT CONCAT(estado_facturacion,'/',facturacion_manual) FROM ingresos WHERE venta_id=${vIva}`) === 'facturada/1');
  verifica('regresa al detalle de la venta con el historial', pagina.url().includes('/admin/ventas/detalle') && (await pagina.locator('#facturacion').innerText()).includes('confirmación manual'));
  await pagina.goto(`${BASE}/admin/ventas/detalle?id=${vSin}`);
  verifica('venta sin IVA: no facturada, sin IVA', (await pagina.locator('#facturacion').innerText()).toLowerCase().includes('no facturada') && (await pagina.locator('#facturacion').innerText()).toLowerCase().includes('sin iva'));

  console.log('Apartados independientes');
  await pagina.goto(`${BASE}/admin/contabilidad/sin-facturar?anio=${anio}&vista=no_facturada`);
  verifica('«No facturadas» lista la venta sin IVA', (await pagina.locator('tbody tr').count()) === 1);
  await pagina.goto(`${BASE}/admin/contabilidad/sin-facturar?anio=${anio}&vista=sin_clasificar`);
  verifica('«Pendientes» ya no lista la facturada', (await pagina.locator('tbody tr').count()) === 0);
} finally {
  await navegador.close();
}
console.log(`Resultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

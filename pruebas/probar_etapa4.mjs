/**
 * probar_etapa4.mjs — pantalla de entrega en un navegador real.
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/probar_etapa4.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba (DB_NAME=cotizaciones_test).'); process.exit(2); }
const EMAIL = 'prueba.etapa4@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
let ok = 0, mal = 0;
const verifica = (n, c, d = '') => { c ? ok++ : mal++; console.log((c ? '  ✓ ' : '  ✗ ') + n + (!c && d ? ' → ' + d : '')); };

function limpiar() {
  for (const t of ['periodos_historial', 'calculos_fiscales', 'declaraciones', 'ajustes_fiscales', 'retenciones']) { sql(`DELETE FROM ${t}`); }
  sql('DELETE FROM documentos'); sql('DELETE FROM cobros'); sql('DELETE FROM ingresos');
  sql('DELETE FROM entregas'); sql('DELETE FROM preparaciones');
  sql('DELETE FROM movimientos_inventario WHERE venta_id IS NOT NULL OR cotizacion_id IS NOT NULL');
  sql('DELETE FROM ventas'); sql('DELETE FROM cotizaciones');
}
limpiar();
sql('UPDATE productos SET stock = 100 WHERE id = 37');
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Prueba E4','${EMAIL}','${hash}',1)`);
const usuario = sql(`SELECT id FROM usuarios WHERE email='${EMAIL}'`);
const [c1, c2] = sql('SELECT id FROM clientes ORDER BY id LIMIT 2').split('\n');

const sembrar = `require '${RAIZ}/includes/app.php';
$l = fn($c) => ['cantidad'=>$c,'precio'=>100,'descuento_pct'=>0,'no_recordar'=>1];
foreach ([[${c1},5],[${c2},2]] as [$cl,$n]) {
  App\\Ventas::crear(['empresa_id'=>1,'cliente_id'=>$cl,'fecha'=>date('Y-m-d'),'aplica_iva'=>1,'descuento_pct'=>0],[37=>$l($n)],${usuario});
}`;
execFileSync('php', ['-r', sembrar], { env: { ...process.env, DB_NAME: DB } });
const stockInicial = sql('SELECT stock FROM productos WHERE id=37');

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 1440, height: 900 } });
const errores = [];
pagina.on('pageerror', (e) => errores.push(e.message));
pagina.on('console', (m) => { if (m.type() === 'error') errores.push(m.text()); });
const pdfTexto = async (ruta) => {
  const r = await pagina.request.get(BASE + ruta);
  const archivo = join(tmpdir(), 'e4.pdf');
  writeFileSync(archivo, await r.body());
  return { tipo: r.headers()['content-type'], texto: execFileSync('pdftotext', [archivo, '-'], { encoding: 'utf8' }) };
};

try {
  await pagina.goto(BASE + '/login');
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

  console.log('Preparación de pedidos');
  await pagina.goto(BASE + '/admin/entregas');
  verifica('lista las 2 ventas confirmadas', (await pagina.locator('tbody tr').first().count()) === 1
    && (await pagina.locator('input[name="ventas[]"]').count()) === 2);
  verifica('resumen oculto sin selección', await pagina.locator('[data-resumen-agrupado]').isHidden());
  await pagina.check('#marcar-todas');
  verifica('al marcar solo se cuenta lo seleccionado, sin controles de fases', (await pagina.locator('[data-resumen-contador]').innerText()).includes('2 ventas') && (await pagina.locator('button:has-text("Preparado"), button:has-text("En reparto")').count()) === 0);
  await Promise.all([pagina.waitForURL('**/admin/entregas/ver?*'), pagina.click('[data-crear-preparacion]')]);
  verifica('«Mostrar en pantalla» agrupa 5 + 2 = 7 en una tarjeta', (await pagina.locator('.producto-grupo').count()) === 1
    && (await pagina.locator('.producto-grupo__cantidad').first().innerText()).trim().startsWith('7'));
  verifica('mostrar la selección no guarda nada ni mueve el inventario', sql('SELECT COUNT(*) FROM preparaciones') === '0' && sql('SELECT stock FROM productos WHERE id=37') === stockInicial);
  verifica('la pantalla no tiene botones de fase', (await pagina.locator('button:has-text("Cambiar estado"), button:has-text("Preparado")').count()) === 0);

  console.log('Guardar la vista');
  await pagina.fill('#notas', 'Ruta norte');
  await Promise.all([pagina.waitForURL('**/admin/entregas/detalle?id=*'), pagina.click('button:has-text("Guardar esta vista")')]);
  verifica('vista guardada con su nombre', /PREP-\d{4}-\d{4}/.test(await pagina.content()) && (await pagina.content()).includes('Ruta norte'));
  verifica('la vista guardada muestra el producto agrupado (7)', (await pagina.locator('.producto-grupo__cantidad').first().innerText()).trim().startsWith('7'));
  verifica('guardar la vista no movió el inventario', sql('SELECT stock FROM productos WHERE id=37') === stockInicial);
  const prepId = sql('SELECT MAX(id) FROM preparaciones');
  await pagina.goto(BASE + '/admin/entregas');
  verifica('aparece en «Vistas guardadas»', (await pagina.locator('h2:has-text("Vistas guardadas")').count()) === 1 && (await pagina.content()).includes('Ruta norte'));

  console.log('Entrega parcial');
  await pagina.goto(`${BASE}/admin/entregas/detalle?id=${prepId}`);
  const ventaA = sql(`SELECT v.id FROM ventas v WHERE v.cliente_id=${c1}`);
  await pagina.click(`a[href*="entregar?venta=${ventaA}"]`);
  await pagina.fill('input[name="cantidad[37]"]', '3');
  await Promise.all([pagina.waitForURL('**/admin/entregas/detalle?id=*'), pagina.click('button:has-text("Confirmar entrega")')]);
  verifica('entrega parcial: 3 piezas registradas', sql(`SELECT SUM(er.cantidad) FROM entrega_renglones er JOIN entregas e ON e.id=er.entrega_id WHERE e.venta_id=${ventaA}`) === '3');
  verifica('entregar NO descuenta inventario otra vez', sql('SELECT stock FROM productos WHERE id=37') === stockInicial);
  verifica('el detalle muestra 3 / 5 entregadas', (await pagina.content()).includes('3 / 5'));

  console.log('Entregas por cliente');
  await pagina.goto(BASE + '/admin/entregas/clientes');
  verifica('agrupa por cliente (2 clientes con pendientes)', (await pagina.locator('.entrega-cliente').count()) === 2);
  verifica('cliente A debe 2 pendientes', (await pagina.locator('.entrega-cliente').first().innerText()).includes('2 pieza(s) por entregar'));

  console.log('PDF');
  const lista = await pdfTexto(`/admin/entregas/pdf?id=${prepId}&tipo=preparacion`);
  verifica('lista de preparación es PDF con el folio', lista.tipo.includes('pdf') && lista.texto.includes('Lista de preparación'));
  const ent = await pdfTexto(`/admin/entregas/pdf?id=${prepId}&tipo=entrega`);
  verifica('PDF de entrega con línea de firma', ent.tipo.includes('pdf') && ent.texto.includes('Recibí de conformidad'));
  verifica('exportar no cambió el inventario', sql('SELECT stock FROM productos WHERE id=37') === stockInicial);

  console.log('Móvil');
  await pagina.setViewportSize({ width: 390, height: 800 });
  for (const ruta of ['/admin/entregas', `/admin/entregas/ver?ventas[]=${ventaA}`, '/admin/entregas/clientes', `/admin/entregas/detalle?id=${prepId}`, `/admin/entregas/entregar?venta=${ventaA}&prep=${prepId}`]) {
    await pagina.goto(BASE + ruta);
    const desborde = await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    verifica(ruta.split('?')[0] + ' @390px sin desborde', !desborde);
  }
  verifica('sin errores de consola', errores.length === 0, errores.join(' | '));
} finally {
  await navegador.close();
  limpiar();
  sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
}
console.log(`\nResultado: ${ok} correctas · ${mal} fallos`);
process.exit(mal ? 1 : 0);

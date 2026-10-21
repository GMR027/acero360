/**
 * auditar_movil.mjs — uso real en celular (390 px):
 *   • objetivos táctiles de al menos 44 × 44 px (botones, enlaces de acción, casillas, campos)
 *   • tablas legibles SIN desplazamiento horizontal dentro de su contenedor
 *   • campos de texto con 16 px (el navegador del teléfono no hace zoom al enfocarlos)
 *   • sin desbordamiento horizontal de la página
 *
 *   DB_NAME=cotizaciones_test php -S 127.0.0.1:8017 index.php &
 *   DB_NAME=cotizaciones_test node pruebas/auditar_movil.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8017';
const DB = process.env.DB_NAME || 'cotizaciones';
if (DB === 'cotizaciones') { console.error('Usa una BD de prueba.'); process.exit(2); }
const EMAIL = 'auditoria.movil@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) => execFileSync('mysql', ['-u', 'root', DB, '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();
const hash = execFileSync('php', ['-r', `echo password_hash('${CLAVE}', PASSWORD_BCRYPT);`], { encoding: 'utf8' });
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);
sql(`INSERT INTO usuarios (nombre,email,contrasena,rol) VALUES ('Auditoría móvil','${EMAIL}','${hash}',1)`);

const paginas = [
  '/admin', '/admin/clientes', '/admin/productos', '/admin/empresas', '/admin/usuarios', '/admin/cotizaciones', '/admin/cotizaciones/crear',
  '/admin/ventas', '/admin/ventas/crear', '/admin/inventario', '/admin/inventario/completo', '/admin/proveedores', '/admin/proveedores/entradas',
  '/admin/compras', '/admin/compras/pedidos', '/admin/compras/recepciones', '/admin/compras/facturas', '/admin/compras/pagos', '/admin/compras/crear', '/admin/entregas', '/admin/entregas/clientes',
  '/admin/contabilidad', '/admin/contabilidad/ingresos', '/admin/contabilidad/gastos', '/admin/contabilidad/gastos/crear',
  '/admin/contabilidad/documentos', '/admin/contabilidad/sin-facturar', '/admin/contabilidad/cierre',
  '/admin/fiscal', '/admin/fiscal/configuracion', '/admin/estadisticas', '/admin/escritorio',
];
const MIN = 44;

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true });
await pagina.goto(BASE + '/login');
await pagina.fill('#email', EMAIL);
await pagina.fill('#contrasena', CLAVE);
await Promise.all([pagina.waitForURL('**/admin'), pagina.click('button[type=submit]')]);

const hallazgos = [];
for (const ruta of paginas) {
  await pagina.goto(BASE + ruta);
  const r = await pagina.evaluate((min) => {
    const visible = (el) => { const b = el.getBoundingClientRect(); const e = getComputedStyle(el); return b.width > 0 && b.height > 0 && e.visibility !== 'hidden' && e.display !== 'none'; };
    const chicos = [];
    document.querySelectorAll('a.btn, button, input:not([type=hidden]):not([type=file]), select, textarea, .chip, .sidebar__enlace, .bottomnav a').forEach((el) => {
      if (!visible(el) || el.closest('[hidden]') || el.closest('.solo-lectores') || el.classList.contains('saltar-contenido')) { return; }
      const b = el.getBoundingClientRect();
      const esCasilla = el.matches('input[type=checkbox], input[type=radio]');
      const caja = esCasilla && el.closest('label') ? el.closest('label').getBoundingClientRect() : b;
      if (caja.height < min - 0.5 || (caja.width < min - 0.5 && !el.matches('input[type=search], input[type=text], input[type=email], input[type=number], input[type=date], input[type=password], select, textarea'))) {
        chicos.push((el.tagName + '.' + (el.className || '').toString().split(' ')[0] + ' ' + Math.round(caja.width) + '×' + Math.round(caja.height)).slice(0, 60));
      }
    });
    const tablasConScroll = [...document.querySelectorAll('.tabla-contenedor')].filter((c) => visible(c) && c.scrollWidth > c.clientWidth + 2).length;
    const textoChico = [...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), select, textarea')]
      .filter((el) => visible(el) && parseFloat(getComputedStyle(el).fontSize) < 16).length;
    return { chicos: [...new Set(chicos)], tablasConScroll, textoChico, desborde: document.documentElement.scrollWidth > window.innerWidth + 1 };
  }, MIN);
  if (r.chicos.length || r.tablasConScroll || r.textoChico || r.desborde) { hallazgos.push({ ruta, ...r }); }
}
// Otros anchos (tablet y laptop pequeña): solo desbordamiento horizontal.
const desbordes = [];
for (const ancho of [768, 1024]) {
  await pagina.setViewportSize({ width: ancho, height: 900 });
  for (const ruta of paginas) {
    await pagina.goto(BASE + ruta);
    if (await pagina.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) { desbordes.push(`${ruta} @${ancho}px`); }
  }
}
if (desbordes.length) { console.log('\nDesbordes en otros anchos:', desbordes.join(', ')); }
await navegador.close();
sql(`DELETE FROM usuarios WHERE email='${EMAIL}'`);

console.log(`\n═══ Auditoría móvil (390 px + 768 y 1024 px, ${paginas.length} pantallas) ═══`);
for (const h of hallazgos) {
  console.log(`● ${h.ruta}`);
  if (h.chicos.length) { console.log(`   · objetivos táctiles < ${MIN}px (${h.chicos.length}): ${h.chicos.slice(0, 4).join(' | ')}`); }
  if (h.tablasConScroll) { console.log(`   · ${h.tablasConScroll} tabla(s) requieren desplazamiento horizontal`); }
  if (h.textoChico) { console.log(`   · ${h.textoChico} campo(s) con texto < 16px`); }
  if (h.desborde) { console.log('   · la página se desborda horizontalmente'); }
}
const resumen = { tactiles: hallazgos.filter((h) => h.chicos.length).length, tablas: hallazgos.reduce((a, h) => a + h.tablasConScroll, 0), texto: hallazgos.filter((h) => h.textoChico).length, desborde: hallazgos.filter((h) => h.desborde).length + desbordes.length };
console.log(`\nResumen → pantallas con objetivos pequeños: ${resumen.tactiles} · tablas con scroll horizontal: ${resumen.tablas} · campos con texto chico: ${resumen.texto} · desbordes: ${resumen.desborde}`);
process.exit(resumen.tactiles || resumen.tablas || resumen.texto || resumen.desborde ? 1 : 0);

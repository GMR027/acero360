/**
 * auditar_consola.mjs — Errores de JavaScript y recursos que no cargan.
 *
 * Recorre las pantallas del panel escuchando la consola del navegador y las
 * peticiones de red: un icono que da 404, un módulo que revienta al cargar
 * o una advertencia repetida pasan desapercibidos en una revisión visual.
 *
 * Sólo informa.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const EMAIL = 'prueba.audit@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) =>
  execFileSync('mysql', ['-u', 'root', (process.env.DB_NAME || 'cotizaciones'), '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();

const paginas = [
  '/', '/login', '/registro', '/admin', '/admin/perfil',
  '/admin/usuarios', '/admin/usuarios/crear',
  '/admin/clientes', '/admin/clientes/crear',
  '/admin/notas', '/admin/notas/crear',
  '/admin/empresas', '/admin/empresas/crear',
  '/admin/productos', '/admin/productos/crear',
  '/admin/cotizaciones', '/admin/cotizaciones/crear',
  '/admin/ventas', '/admin/ventas/crear', '/admin/inventario', '/admin/compras', '/admin/compras/crear', '/admin/fiscal', '/admin/fiscal/registros', '/admin/fiscal/catalogo', '/admin/fiscal/configuracion', '/admin/contabilidad/cierre', '/admin/contabilidad', '/admin/contabilidad/ingresos', '/admin/contabilidad/gastos', '/admin/contabilidad/gastos/crear', '/admin/contabilidad/documentos', '/admin/contabilidad/sin-facturar', '/admin/contabilidad/periodos', '/admin/entregas', '/admin/entregas/clientes',
  '/admin/proveedores', '/admin/proveedores/crear',
  '/admin/proveedores/categorias', '/admin/proveedores/categorias/crear',
  '/admin/proveedores/entradas', '/admin/proveedores/entradas/crear',
];

const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
sql(
  "INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Auditor de UI', '" +
    EMAIL + "', '" + hash + "', '', 1) ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)"
);
const usuarioId = Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));

const problemas = new Map();
const apuntar = (clave, detalle) => {
  if (!problemas.has(clave)) problemas.set(clave, { detalle, paginas: [] });
  problemas.get(clave).paginas.push(rutaActual);
};

let rutaActual = '';
const navegador = await chromium.launch();
try {
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await contexto.newPage();

  page.on('console', (msg) => {
    if (msg.type() !== 'error' && msg.type() !== 'warning') return;
    const texto = msg.text().replace(/\s+/g, ' ').slice(0, 160);
    apuntar(`${msg.type()}|${texto}`, `${msg.type()}: ${texto}`);
  });
  page.on('pageerror', (err) => apuntar(`pageerror|${err.message}`, `pageerror: ${err.message}`));
  page.on('requestfailed', (req) => {
    const motivo = req.failure()?.errorText || '';
    // Cambiar de página cancela las peticiones que siguen en vuelo (las
    // fuentes de Google, por ejemplo): eso no es un fallo de la aplicación.
    if (motivo.includes('ERR_ABORTED')) return;
    apuntar(`red|${req.url()}`, `petición fallida (${motivo}): ${req.url()}`);
  });
  page.on('response', (res) => {
    if (res.status() >= 400) apuntar(`http${res.status()}|${res.url()}`, `HTTP ${res.status()}: ${res.url()}`);
  });

  rutaActual = '/login';
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#contrasena', CLAVE);
  await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

  for (const ruta of paginas) {
    rutaActual = ruta;
    await page.goto(`${BASE}${ruta}`, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(150);
  }

  console.log('═══ Consola y red ═══');
  if (problemas.size === 0) {
    console.log('  ✓ sin errores de JavaScript ni recursos fallidos');
  } else {
    for (const p of problemas.values()) {
      console.log(`  ✗ ${p.detalle}`);
      console.log(`      → ${p.paginas.length} página(s): ${[...new Set(p.paginas)].slice(0, 5).join(', ')}`);
    }
  }
  console.log(`\nTotal: ${problemas.size} problema(s) distintos en ${paginas.length} pantallas.`);
} catch (e) {
  console.log('ERROR: ' + e.message);
} finally {
  if (usuarioId > 0) sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
  await navegador.close();
}

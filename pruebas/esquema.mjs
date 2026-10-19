/**
 * esquema.mjs — Vuelca el esqueleto semántico de una página: encabezados,
 * regiones y duplicados. Ayuda a decidir si un <h1> sobra o es un <h2>.
 *
 * Uso: node pruebas/esquema.mjs /admin/clientes/detalle?id=15 /admin/productos/editar?id=52
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const EMAIL = 'prueba.audit@cotizaciones.local';
const CLAVE = 'Prueba1234!';
const sql = (c) =>
  execFileSync('mysql', ['-u', 'root', (process.env.DB_NAME || 'cotizaciones'), '-N', '-B', '-e', c], { encoding: 'utf8' }).trim();

const rutas = process.argv.slice(2).filter((a) => a.startsWith('/'));
if (rutas.length === 0) {
  console.log('Indica rutas: node esquema.mjs /admin/clientes');
  process.exit(0);
}

const hash = execFileSync(
  'php',
  ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`],
  { encoding: 'utf8' }
).trim();
sql(
  "INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) " +
    `VALUES ('Auditor de UI', '${EMAIL}', '${hash}', '', 1) ` +
    'ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)'
);
const usuarioId = Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));

const navegador = await chromium.launch();
try {
  const page = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#contrasena', CLAVE);
  await Promise.all([
    page.waitForURL('**/admin**', { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);

  for (const ruta of rutas) {
    await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(150);
    const salida = await page.evaluate(() => {
      const limpio = (s) => (s || '').replace(/\s+/g, ' ').trim().slice(0, 70);
      const encabezados = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((h) => ({
        nivel: h.tagName,
        texto: limpio(h.textContent),
        visible: h.getBoundingClientRect().height > 0,
        enTopbar: !!h.closest('.topbar'),
      }));
      const regiones = [...document.querySelectorAll('.encabezado-pagina')].map((e, i) => ({
        indice: i,
        alto: Math.round(e.getBoundingClientRect().height),
        textos: limpio(e.textContent).slice(0, 90),
      }));
      return {
        titulo: document.title,
        encabezados,
        regiones,
        tablas: [...document.querySelectorAll('table')].map((t) => ({
          id: t.id || '-',
          caption: limpio(t.querySelector('caption')?.textContent) || 'SIN CAPTION',
        })),
        etiquetasRotas: [...document.querySelectorAll('.campo')].map((c) => {
          const control = c.querySelector('input:not([type=hidden]), select, textarea');
          if (!control) return null;
          // Un campo está rotulado si hay label[for] que apunte a su id,
          // si el propio control está envuelto en <label> o si lleva aria-label.
          const id = control.getAttribute('id');
          const conFor = id && document.querySelector(`label[for="${id}"]`);
          const envuelto = control.closest('label');
          const explicito = control.getAttribute('aria-label') || control.getAttribute('aria-labelledby');
          if (!conFor && !envuelto && !explicito)
            return `campo sin label asociado: ${limpio(control.outerHTML).slice(0, 70)}`;
          return null;
        }).filter(Boolean),
      };
    });
    console.log(`\n══ ${ruta}`);
    console.log(`  title: ${salida.titulo}`);
    salida.encabezados.forEach((h) =>
      console.log(
        `  ${h.nivel}${h.enTopbar ? ' (topbar)' : ''}${h.visible ? '' : ' [oculto]'}: ${h.texto}`
      )
    );
    salida.regiones.forEach((r) => console.log(`  · encabezado-pagina[${r.indice}] ${r.alto}px: ${r.textos}`));
    salida.tablas.forEach((t) => console.log(`  · tabla#${t.id} caption=${t.caption}`));
    salida.etiquetasRotas.forEach((e) => console.log(`  ! ${e}`));
  }
} finally {
  if (usuarioId > 0) sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
  await navegador.close();
}

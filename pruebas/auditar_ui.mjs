/**
 * auditar_ui.mjs — Auditoría de diseño, interfaz y accesibilidad.
 *
 * Recorre todas las pantallas GET del panel (y las públicas) y devuelve lo
 * que un repaso manual tarda horas en encontrar:
 *   · desbordamiento horizontal a 1440 y 390 px
 *   · estructura: <html lang>, viewport, título, exactamente un <h1>
 *   · controles sin nombre accesible (iconos sin aria-label, campos sin label)
 *   · imágenes sin alt, tablas sin caption ni scope en las cabeceras
 *   · ids duplicados
 *   · toolbars de listado sin Buscar/Limpiar ni sin contador
 *   · estados vacíos ausentes
 *   · objetivos táctiles pequeños en móvil
 *
 * No modifica nada: sólo informa. Usa la cuenta de prueba típica de las suites.
 *
 * Uso: node pruebas/auditar_ui.mjs [--solo=/admin]
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const EMAIL = 'prueba.audit@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const sql = (c) =>
  execFileSync('mysql', ['-u', 'root', (process.env.DB_NAME || 'cotizaciones'), '-N', '-B', '-e', c], {
    encoding: 'utf8',
  }).trim();

const idDe = (consulta) => Number(sql(consulta) || 0);

/** Páginas que no necesitan parámetros. */
const paginas = [
  '/', '/login', '/registro',
  '/admin', '/admin/perfil',
  '/admin/usuarios', '/admin/usuarios/crear',
  '/admin/clientes', '/admin/clientes/crear',
  '/admin/notas', '/admin/notas/crear',
  '/admin/empresas', '/admin/empresas/crear',
  '/admin/productos', '/admin/productos/crear',
  '/admin/cotizaciones', '/admin/cotizaciones/crear',
  '/admin/cotizaciones/precios-cliente',
  '/admin/ventas', '/admin/ventas/crear',
  '/admin/inventario',
  '/admin/proveedores', '/admin/proveedores/crear',
  '/admin/proveedores/categorias', '/admin/proveedores/categorias/crear',
  '/admin/proveedores/entradas', '/admin/proveedores/entradas/crear',
];

/** Páginas que sí necesitan un id real (el detalle se mide con datos). */
const paginasConId = [
  ['cliente', '/admin/clientes/detalle?id=', 'clientes'],
  ['cotizacion', '/admin/cotizaciones/detalle?id=', 'cotizaciones'],
  ['venta', '/admin/ventas/detalle?id=', 'ventas'],
  ['proveedor', '/admin/proveedores/detalle?id=', 'proveedores'],
  ['entrada', '/admin/proveedores/entradas/detalle?id=', 'proveedor_entradas'],
  ['nota', '/admin/notas/editar?id=', 'notas'],
  ['producto', '/admin/productos/editar?id=', 'productos'],
  ['cliente-editar', '/admin/clientes/editar?id=', 'clientes'],
  ['cotizacion-editar', '/admin/cotizaciones/editar?id=', 'cotizaciones'],
  ['usuario-editar', '/admin/usuarios/editar?id=', 'usuarios'],
  ['empresa-editar', '/admin/empresas/editar?id=', 'empresas'],
];

/** Listados que deberían llevar toolbar con buscador y contador. */
const listados = [
  '/admin/usuarios', '/admin/clientes', '/admin/notas', '/admin/empresas',
  '/admin/productos', '/admin/cotizaciones', '/admin/ventas', '/admin/inventario',
  '/admin/proveedores', '/admin/proveedores/categorias', '/admin/proveedores/entradas',
];

const hallazgos = [];
const registrar = (pagina, tipo, detalle) =>
  hallazgos.push({ pagina, tipo, detalle });

/** Comprobaciones que se hacen dentro de la página. */
const inspeccionar = () => {
  const problemas = [];

  /* --- estructura --- */
  if (!document.documentElement.getAttribute('lang'))
    problemas.push(['lang', 'falta lang en <html>']);
  if (!document.querySelector('meta[name="viewport"]'))
    problemas.push(['viewport', 'falta meta viewport']);
  if (!document.title || document.title.trim() === '')
    problemas.push(['titulo', 'la pestaña no tiene título']);
  const h1 = document.querySelectorAll('h1');
  if (h1.length === 0) problemas.push(['h1', 'la página no tiene <h1>']);
  if (h1.length > 1)
    problemas.push(['h1', `${h1.length} elementos <h1>: ${[...h1].map((h) => h.textContent.trim().slice(0, 30)).join(' | ')}`]);

  /* --- nombre accesible de los controles --- */
  const sinNombre = (el) => {
    const texto = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (texto) return false;
    if (el.getAttribute('aria-label')) return false;
    if (el.getAttribute('title')) return false;
    const etiqueta = el.getAttribute('aria-labelledby');
    if (etiqueta && document.getElementById(etiqueta)) return false;
    return true;
  };
  document.querySelectorAll('a, button, [role="button"]').forEach((el) => {
    if (sinNombre(el))
      problemas.push(
        ['accesible', `<${el.tagName.toLowerCase()}> sin nombre accesible: ${el.outerHTML.slice(0, 110)}`]
      );
  });

  /* --- campos sin etiqueta --- */
  document.querySelectorAll('input, select, textarea').forEach((el) => {
    if (el.type === 'hidden') return;
    const id = el.getAttribute('id');
    const conLabel =
      (id && document.querySelector(`label[for="${CSS.escape(id)}"]`)) ||
      el.closest('label') ||
      el.getAttribute('aria-label') ||
      el.getAttribute('aria-labelledby') ||
      el.getAttribute('title');
    if (!conLabel)
      problemas.push(
        ['campo', `${el.tagName.toLowerCase()}[${el.type || ''}] sin label: ${el.outerHTML.slice(0, 110)}`]
      );
  });

  /* --- imágenes --- */
  document.querySelectorAll('img').forEach((img) => {
    if (img.getAttribute('alt') === null)
      problemas.push(['img', `<img> sin alt: ${(img.getAttribute('src') || '').slice(0, 60)}`]);
  });

  /* --- tablas --- */
  document.querySelectorAll('table').forEach((t) => {
    const accesible = t.querySelector('caption') || t.getAttribute('aria-label') || t.getAttribute('aria-labelledby');
    if (!accesible) problemas.push(['tabla', 'tabla sin <caption> ni aria-label']);
    t.querySelectorAll('thead th').forEach((th) => {
      if (!th.getAttribute('scope'))
        problemas.push(['tabla', `<th> sin scope: "${th.textContent.trim().slice(0, 24)}"`]);
    });
    // Cabeceras centradas: regla de diseño del proyecto.
    [...t.querySelectorAll('thead th')].forEach((th) => {
      const a = getComputedStyle(th).textAlign;
      if (a !== 'center')
        problemas.push(['centrado', `<th> "${th.textContent.trim().slice(0, 20)}" alineado a ${a}`]);
    });
  });

  /* --- ids duplicados --- */
  const vistos = new Set();
  document.querySelectorAll('[id]').forEach((el) => {
    if (vistos.has(el.id)) problemas.push(['id', `id duplicado: #${el.id}`]);
    vistos.add(el.id);
  });

  /* --- scroll horizontal en el documento --- */
  if (document.documentElement.scrollWidth > window.innerWidth)
    problemas.push(['desborde', `scrollWidth ${document.documentElement.scrollWidth} > ${window.innerWidth}`]);

  /* --- objetivos táctiles (WCAG 2.5.8: mínimo 24×24 px) ---
     Excepciones que la propia norma permite: controles nativos cuyo
     objetivo real es su <label>, y enlaces en línea dentro de un párrafo. */
  if (window.innerWidth <= 480) {
    document.querySelectorAll('button, a.btn, .sidebar__enlace, .bottomnav a, a.badge').forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) return;
      if (r.height >= 24 && r.width >= 24) return;
      // Controles nativos: el objetivo es la etiqueta asociada.
      if (el.matches('input, select') && (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`))) return;
      // Enlaces en línea dentro de un párrafo o texto de ayuda.
      if (el.tagName === 'A' && el.closest('p, .campo__ayuda, .texto-secundario')) return;
      problemas.push(
        ['táctil', `objeto de ${Math.round(r.width)}×${Math.round(r.height)}px: ${(el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 30) || el.className}`]
      );
    });
  }

  return problemas;
};

/** Comprobaciones propias de los listados (sin filtro: «Limpiar» aún no
 *  debe existir porque no hay nada que limpiar). */
const inspeccionarListado = () => {
  const problemas = [];
  const barra = document.querySelector('.tabla-toolbar');
  if (!barra) {
    problemas.push(['toolbar', 'el listado no tiene .tabla-toolbar']);
    return problemas;
  }
  const texto = barra.textContent.replace(/\s+/g, ' ');
  if (!/buscar/i.test(texto)) problemas.push(['toolbar', 'sin botón "Buscar"']);
  if (!barra.querySelector('.badge')) problemas.push(['toolbar', 'sin contador de registros']);
  const tabla = document.querySelector('.tabla');
  if (tabla && !tabla.querySelector('tbody tr') && !document.querySelector('.vacio'))
    problemas.push(['vacío', 'listado vacío sin estado .vacio']);
  return problemas;
};

/** Con un filtro aplicado, "Limpiar" pasa a ser obligatorio. */
const inspeccionarListadoFiltrado = () => {
  const barra = document.querySelector('.tabla-toolbar');
  if (!barra) return 'sin .tabla-toolbar';
  const texto = barra.textContent.replace(/\s+/g, ' ');
  const fallos = [];
  if (!/buscar/i.test(texto)) fallos.push('desapareció "Buscar"');
  if (!/limpiar/i.test(texto)) fallos.push('no aparece "Limpiar" con filtro activo');
  if (!barra.querySelector('.badge')) fallos.push('sin contador de resultados');
  return fallos.join('; ');
};

async function prepararUsuario() {
  const viejo = sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`);
  if (viejo) sql(`DELETE FROM usuarios WHERE id = ${Number(viejo)}`);
  const hash = execFileSync(
    'php',
    ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`],
    { encoding: 'utf8' }
  ).trim();
  sql(
    'INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ' +
      `VALUES ('Auditor de UI', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

let usuarioId = 0;
const navegador = await chromium.launch();

try {
  usuarioId = await prepararUsuario();
  const contexto = await navegador.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await contexto.newPage();

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#contrasena', CLAVE);
  await Promise.all([
    page.waitForURL('**/admin**', { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);

  /* --- páginas con id real --- */
  for (const [tipo, base, tabla] of paginasConId) {
    const id = idDe(`SELECT id FROM ${tabla} ORDER BY id DESC LIMIT 1`);
    if (id > 0) paginas.push(`${base}${id}`);
  }

  const filtro = process.argv.find((a) => a.startsWith('--solo='))?.slice(7) || '';
  const lista = paginas.filter((p) => p.includes(filtro));

  for (const ancho of [1440, 390]) {
    await page.setViewportSize({ width: ancho, height: 900 });
    console.log(`\n═══ Vista ${ancho}px ═══`);
    for (const ruta of lista) {
      const r = await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
      if (!r || r.status() !== 200) {
        registrar(ruta, 'HTTP', `status=${r ? r.status() : '?'}`);
        continue;
      }
      // Los endpoints JSON (precios por cliente) no son páginas: no se inspeccionan.
      if (!(r.headers()['content-type'] || '').includes('text/html')) continue;
      await page.waitForTimeout(120);
      const problemas = await page.evaluate(inspeccionar);
      if (ancho === 1440 && listados.some((l) => ruta === l || ruta.startsWith(l + '?'))) {
        problemas.push(...(await page.evaluate(inspeccionarListado)));
      }
      problemas.forEach(([tipo, detalle]) => registrar(`${ruta} @${ancho}`, tipo, detalle));
    }
  }

  /* --- Listados con un filtro aplicado: "Limpiar" es obligatorio aquí --- */
  console.log('\n═══ Listados con filtro activo (390px) ═══');
  await page.setViewportSize({ width: 390, height: 844 });
  for (const listado of listados) {
    const r = await page.goto(`${BASE}${listado}?q=zzzzprueba`, { waitUntil: 'domcontentloaded' });
    if (!r || r.status() !== 200) {
      registrar(`${listado}?q=`, 'HTTP', `status=${r ? r.status() : '?'}`);
      continue;
    }
    await page.waitForTimeout(120);
    const fallo = await page.evaluate(inspeccionarListadoFiltrado);
    if (fallo) registrar(`${listado}?q=`, 'toolbar', fallo);
  }

  /* --- resumen por tipo --- */
  console.log('\n═══ Hallazgos ═══');
  if (hallazgos.length === 0) console.log('  (ninguno)');
  const porTipo = new Map();
  hallazgos.forEach((h) => {
    if (!porTipo.has(h.tipo)) porTipo.set(h.tipo, []);
    porTipo.get(h.tipo).push(h);
  });
  for (const [tipo, items] of porTipo) {
    console.log(`\n● ${tipo} (${items.length})`);
    // Agrupa por el detalle: el mismo fallo en 20 pantallas es UN patrón.
    const agrupado = new Map();
    items.forEach((i) => {
      const clave = i.detalle.replace(/\d+/g, 'N').slice(0, 130);
      if (!agrupado.has(clave)) agrupado.set(clave, []);
      agrupado.get(clave).push(i.pagina);
    });
    for (const [detalle, sitios] of agrupado) {
      console.log(`   · ${detalle}`);
      console.log(`     → ${sitios.length} página(s): ${sitios.slice(0, 6).join(', ')}${sitios.length > 6 ? ' …' : ''}`);
    }
  }
  console.log(`\nTotal: ${hallazgos.length} hallazgos en ${lista.length} pantallas.`);
} catch (e) {
  console.log(`ERROR: ${e.message}`);
} finally {
  if (usuarioId > 0) sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
  await navegador.close();
}

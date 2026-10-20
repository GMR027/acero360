/**
 * auditar_profundo.mjs — Segunda vuelta de auditoría.
 *
 * Lo que la primera pasada no mira:
 *   · contraste real de cada texto (recorriendo la cadena de fondos)
 *   · foco visible al navegar con teclado
 *   · aria-current en el menú activo
 *   · prefers-reduced-motion en el CSS compilado
 *   · detalle de los objetivos táctiles pequeños (qué elemento es y de qué clase)
 *
 * Sólo informa: no modifica nada.
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
  '/admin/ventas', '/admin/ventas/crear', '/admin/inventario', '/admin/compras', '/admin/compras/crear', '/admin/estadisticas', '/admin/escritorio', '/admin/fiscal', '/admin/fiscal/registros', '/admin/fiscal/catalogo', '/admin/fiscal/configuracion', '/admin/contabilidad/cierre', '/admin/contabilidad', '/admin/contabilidad/ingresos', '/admin/contabilidad/gastos', '/admin/contabilidad/gastos/crear', '/admin/contabilidad/documentos', '/admin/contabilidad/sin-facturar', '/admin/contabilidad/periodos', '/admin/entregas', '/admin/entregas/clientes',
  '/admin/proveedores', '/admin/proveedores/crear',
  '/admin/proveedores/categorias', '/admin/proveedores/categorias/crear',
  '/admin/proveedores/entradas', '/admin/proveedores/entradas/crear',
];

const auditarTexto = () => {
  /* --- utilidades de color: viven dentro porque page.evaluate serializa
         una única función y no comparte el ámbito del módulo. --- */
  const aRgb = (c) => {
    const m = c.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(',').map((n) => parseFloat(n));
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const luminancia = ({ r, g, b }) => {
    const f = (v) => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const ratio = (a, b) => {
    const l1 = luminancia(a);
    const l2 = luminancia(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  };
  const mezclar = (frente, fondo) => ({
    r: frente.r * frente.a + fondo.r * (1 - frente.a),
    g: frente.g * frente.a + fondo.g * (1 - frente.a),
    b: frente.b * frente.a + fondo.b * (1 - frente.a),
    a: 1,
  });

  /** Fondo efectivo: sube por el árbol mezclando capas semitransparentes. */
  const fondoDe = (el) => {
    const capas = [];
    let actual = el;
    while (actual) {
      const bg = aRgb(getComputedStyle(actual).backgroundColor);
      if (bg && bg.a > 0) capas.push(bg);
      if (bg && bg.a === 1) break;
      actual = actual.parentElement;
    }
    const ultima = capas[capas.length - 1];
    const base = ultima && ultima.a === 1 ? { ...capas.pop(), a: 1 } : { r: 255, g: 255, b: 255, a: 1 };
    let resultado = base;
    for (let i = capas.length - 1; i >= 0; i--) resultado = mezclar(capas[i], resultado);
    return resultado;
  };

  const malos = [];
  const vistos = new Set();
  document.querySelectorAll('body *').forEach((el) => {
    if (!el.firstChild || el.children.length > 3) return;
    // Elementos ocultos a la tecnología de apoyo: son decoración, la norma
    // (WCAG 1.4.3) exime al texto puramente decorativo del requisito.
    if (el.closest('[aria-hidden="true"]')) return;
    const texto = [...el.childNodes]
      .filter((n) => n.nodeType === 3)
      .map((n) => n.textContent)
      .join(' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (!texto) return;
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || s.display === 'none' || parseFloat(s.opacity) < 0.5) return;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;

    const fg = aRgb(s.color);
    if (!fg || fg.a < 0.5) return;
    const bg = fondoDe(el);
    const cr = ratio(mezclar(fg, bg), bg);

    const tam = parseFloat(s.fontSize);
    const negrita = parseInt(s.fontWeight, 10) >= 700;
    const grande = tam >= 24 || (tam >= 18.66 && negrita);
    const minimo = grande ? 3 : 4.5;
    if (cr < minimo) {
      const clave = `${s.color}|${s.fontSize}|${texto.slice(0, 24)}`;
      if (vistos.has(clave)) return;
      vistos.add(clave);
      malos.push({
        texto: texto.slice(0, 46),
        clase: (el.className || el.tagName).toString().slice(0, 46),
        color: s.color,
        fondo: `rgb(${Math.round(bg.r)},${Math.round(bg.g)},${Math.round(bg.b)})`,
        ratio: Math.round(cr * 100) / 100,
        min: minimo,
        tam: s.fontSize,
      });
    }
  });
  return malos;
};

const auditarMovil = () => {
  const chicos = [];
  document.querySelectorAll('button, a, [role="button"], input[type="checkbox"], input[type="radio"]').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    // WCAG 2.5.8 (AA): 24×24 px como mínimo.
    if (r.height >= 24 && r.width >= 24) return;
    // Controles nativos: el objetivo real es la etiqueta que los activa.
    if (el.matches('input, select') && el.id && document.querySelector(`label[for="${el.id}"]`)) return;
    // Excepción de "en línea": un enlace dentro de un párrafo no es un objetivo suelto.
    if (el.tagName === 'A' && el.closest('p, .campo__ayuda, .texto-secundario')) return;
    chicos.push({
      clase: (el.className || el.tagName).toString().slice(0, 50),
      texto: (el.textContent || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 30),
      caja: `${Math.round(r.width)}×${Math.round(r.height)}`,
      href: (el.getAttribute('href') || '').slice(0, 40),
    });
  });
  return chicos;
};

const auditarNav = () => {
  const sinCurrent = [...document.querySelectorAll('.sidebar__enlace[aria-current], .bottomnav a[aria-current]')]
    .map((a) => `${a.getAttribute('href')}=${a.getAttribute('aria-current')}`);
  const enlacesSidebar = document.querySelectorAll('.sidebar__enlace, .sidebar__subenlace').length;
  const conCurrent = document.querySelectorAll('.sidebar__enlace[aria-current="page"], .sidebar__subenlace[aria-current="page"], .bottomnav a[aria-current="page"]').length;
  const skip = document.querySelector('a.saltar, [href^="#contenido"], [href^="#principal"]');
  return { sinCurrent, enlacesSidebar, conCurrent, tieneSkip: !!skip };
};

const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
sql(
  "INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Auditor de UI', '" +
    EMAIL +
    "', '" +
    hash +
    "', '', 1) ON DUPLICATE KEY UPDATE contrasena = VALUES(contrasena)"
);
const usuarioId = Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));

const navegador = await chromium.launch();
const hallazgos = [];
try {
  const page = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  /* --- CSS compilado: movimiento reducido y anillos de foco --- */
  const css = await (await fetch(`${BASE}/build/css/app.css`)).text();
  console.log('── CSS compilado ──');
  console.log(`  prefers-reduced-motion: ${/@media[^{]*prefers-reduced-motion/.test(css) ? 'SÍ' : 'NO'}`);
  console.log(`  focus-visible: ${/focus-visible/.test(css) ? 'SÍ (' + (css.match(/focus-visible/g) || []).length + ' veces)' : 'NO'}`);
  const saltos = css.match(/@media[^{]*prefers-reduced-motion[\s\S]{0,400}/);
  if (saltos) console.log(`  bloque: ${saltos[0].slice(0, 300).replace(/\s+/g, ' ')}`);

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL);
  await page.fill('#contrasena', CLAVE);
  await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);

  console.log('\n── Foco visible (Tab) ──');
  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  const focos = [];
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press('Tab');
    const f = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      const s = getComputedStyle(el);
      const contorno = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
      const sombra = s.boxShadow && s.boxShadow !== 'none';
      return {
        tag: el.tagName + '.' + (el.className || '').toString().split(' ')[0],
        texto: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 26),
        contorno,
        ancho: s.outlineWidth,
        sombra,
      };
    });
    if (f) focos.push(f);
  }
  const sinFoco = focos.filter((f) => !f.contorno && !f.sombra);
  focos.forEach((f) =>
    console.log(`  ${f.contorno ? '✓' : f.sombra ? '~' : '✗'} ${f.tag} "${f.texto}" ${f.contorno ? 'outline:' + f.ancho : ''}`)
  );
  if (sinFoco.length) hallazgos.push({ tipo: 'foco', n: sinFoco.length, detalle: sinFoco.map((f) => f.tag).join(', ') });

  console.log('\n── Navegación / anillos ──');
  const nav = await page.evaluate(auditarNav);
  console.log(`  enlaces de menú: ${nav.enlacesSidebar} · con aria-current: ${nav.conCurrent}`);
  console.log(`  aria-current != page: ${nav.sinCurrent.join(', ') || 'ninguno'}`);
  console.log(`  enlace "saltar al contenido": ${nav.tieneSkip ? 'SÍ' : 'NO'}`);
  if (!nav.tieneSkip) hallazgos.push({ tipo: 'skip-link', n: 1, detalle: 'no existe enlace de salto de contenido' });

  console.log('\n── Contraste (1440px) ──');
  const vistosContraste = new Map();
  for (const ruta of paginas) {
    await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(80);
    const malos = await page.evaluate(auditarTexto);
    malos.forEach((m) => {
      const clave = `${m.color}|${m.clase}|${m.texto}`;
      if (!vistosContraste.has(clave)) vistosContraste.set(clave, { ...m, paginas: [] });
      vistosContraste.get(clave).paginas.push(ruta);
    });
  }
  if (vistosContraste.size === 0) console.log('  ✓ sin problemas de contraste');
  for (const m of vistosContraste.values()) {
    console.log(`  ✗ ${m.ratio} (<${m.min}) ${m.tam} · ${m.color} sobre ${m.fondo} · .${m.clase} · "${m.texto}"`);
    console.log(`      → ${m.paginas.slice(0, 5).join(', ')}${m.paginas.length > 5 ? ' …' : ''}`);
    hallazgos.push({ tipo: 'contraste', n: m.paginas.length, detalle: `${m.ratio}: .${m.clase} "${m.texto}"` });
  }

  console.log('\n── Objetivos táctiles (390px) ──');
  await page.setViewportSize({ width: 390, height: 844 });
  const vistosTactil = new Map();
  for (const ruta of paginas) {
    await page.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(80);
    const chicos = await page.evaluate(auditarMovil);
    chicos.forEach((c) => {
      const clave = `${c.clase}|${c.texto}|${c.caja}`;
      if (!vistosTactil.has(clave)) vistosTactil.set(clave, { ...c, paginas: [] });
      vistosTactil.get(clave).paginas.push(ruta);
    });
  }
  if (vistosTactil.size === 0) console.log('  ✓ sin objetivos táctiles por debajo de 24px (WCAG 2.5.8)');
  for (const c of vistosTactil.values()) {
    console.log(`  ✗ ${c.caja} · .${c.clase} · "${c.texto}"${c.href ? ' → ' + c.href : ''}`);
    console.log(`      → ${c.paginas.length} página(s)`);
    hallazgos.push({ tipo: 'táctil', n: c.paginas.length, detalle: `${c.caja} .${c.clase} "${c.texto}"` });
  }
} catch (e) {
  console.log('ERROR: ' + e.message);
} finally {
  if (usuarioId > 0) sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
  await navegador.close();
}

console.log('\n═══ Resumen ═══');
if (!hallazgos.length) console.log('  (sin hallazgos)');
const porTipo = new Map();
hallazgos.forEach((h) => porTipo.set(h.tipo, (porTipo.get(h.tipo) || 0) + h.n));
for (const [t, n] of porTipo) console.log(`  ${t}: ${n}`);

/**
 * probar_distribuidores.mjs — CRUD de distribuidores (migración 056).
 *
 * Lo que se comprueba:
 *   1. «Distribuidores» aparece en el menú justo debajo de «Pedidos» y se marca activo.
 *   2. El listado muestra los distribuidores cargados, busca y filtra por municipio.
 *   3. Alta con validación (enlace de Maps que no es https) y con foto de fachada.
 *   4. Edición (incluye reemplazar la foto: la anterior se borra del disco).
 *   5. Publicar / ocultar desde el listado conservando los filtros.
 *   6. Borrado: desaparece la fila y su foto; el endpoint sólo acepta POST.
 *   7. Sin desbordamiento horizontal en móvil.
 *
 * Crea su propio usuario y distribuidor y los borra al final. No toca los
 * distribuidores existentes.
 *
 * Uso: node pruebas/probar_distribuidores.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EMAIL_PRUEBA = 'prueba.distribuidores@cotizaciones.local';
const CLAVE_PRUEBA = 'Prueba1234!';
const NOMBRE = 'ZZ Ferretería de prueba';
const NOMBRE_EDITADO = 'ZZ Ferretería de prueba (Centro)';
const FOTO = path.join(RAIZ, 'public/image/perfilSinfoto.jpg');

let correctas = 0;
let fallos = 0;
function comprobar(nombre, condicion, detalle = '') {
  if (condicion) { correctas++; console.log(`  ✓ ${nombre}`); }
  else { fallos++; console.log(`  ✗ ${nombre}${detalle ? ` — ${detalle}` : ''}`); }
}

const sql = (consulta) =>
  execFileSync('mysql', ['-u', 'root', (process.env.DB_NAME || 'cotizaciones'), '-N', '-B', '-e', consulta], {
    encoding: 'utf8',
  }).trim();

const existeImagen = (archivo) => !!archivo && existsSync(path.join(RAIZ, 'public/image', archivo));
const mini = (archivo) => archivo.replace(/\.jpg$/, '_mini.jpg');

async function entrar(page) {
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#email', EMAIL_PRUEBA);
  await page.fill('#contrasena', CLAVE_PRUEBA);
  await Promise.all([page.waitForURL('**/admin**', { timeout: 15000 }), page.click('button[type="submit"]')]);
}

function limpiar() {
  for (const archivo of sql(`SELECT IFNULL(imagen, '') FROM distribuidores WHERE nombre LIKE 'ZZ Ferretería de prueba%'`).split('\n')) {
    if (archivo && !archivo.startsWith('distribuidores/')) {
      execFileSync('rm', ['-f', path.join(RAIZ, 'public/image', archivo), path.join(RAIZ, 'public/image', mini(archivo))]);
    }
  }
  sql(`DELETE FROM distribuidores WHERE nombre LIKE 'ZZ Ferretería de prueba%'`);
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL_PRUEBA}'`);
}

const navegador = await chromium.launch();
try {
  console.log('\n0. Preparación');
  limpiar();
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE_PRUEBA)}, PASSWORD_BCRYPT);`], { encoding: 'utf8' }).trim();
  sql(`INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) VALUES ('Prueba distribuidores', '${EMAIL_PRUEBA}', '${hash}', '', 1)`);
  const totalBase = Number(sql('SELECT COUNT(*) FROM distribuidores'));
  comprobar('hay distribuidores cargados por la migración', totalBase >= 1, `total=${totalBase}`);

  const contexto = await navegador.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await contexto.newPage();
  await entrar(page);

  console.log('\n1. Menú');
  await page.goto(`${BASE}/admin/distribuidores`, { waitUntil: 'domcontentloaded' });
  const enlaces = await page.$$eval('.sidebar__nav a.sidebar__enlace', (els) => els.map((e) => e.getAttribute('href')));
  const iPedidos = enlaces.indexOf('/admin/pedidos');
  comprobar('«Distribuidores» va justo debajo de «Pedidos»', iPedidos >= 0 && enlaces[iPedidos + 1] === '/admin/distribuidores', enlaces.join(' '));
  const activo = await page.$eval('.sidebar__nav a[href="/admin/distribuidores"]', (a) => a.getAttribute('aria-current') === 'page');
  comprobar('el enlace se marca activo', activo);

  console.log('\n2. Listado');
  const badge = await page.textContent('.tabla-toolbar .badge');
  comprobar('el contador muestra el total', badge.replace(/\D/g, '') === String(totalBase), badge);
  const municipio = sql('SELECT municipio FROM distribuidores ORDER BY orden DESC, id DESC LIMIT 1');
  const enMunicipio = Number(sql(`SELECT COUNT(*) FROM distribuidores WHERE municipio = '${municipio.replace(/'/g, "''")}'`));
  await page.selectOption('#municipio', municipio);
  await Promise.all([page.waitForURL('**municipio=**'), page.click('.tabla-toolbar button[type="submit"]')]);
  const filas = await page.$$eval('.tabla tbody tr', (r) => r.length);
  comprobar(`filtrar por «${municipio}» deja ${enMunicipio}`, filas === Math.min(enMunicipio, 20), `filas=${filas}`);

  console.log('\n3. Alta');
  await page.goto(`${BASE}/admin/distribuidores/crear`, { waitUntil: 'domcontentloaded' });
  await page.fill('#nombre', NOMBRE);
  await page.fill('#municipio', 'Loreto');
  await page.fill('#direccion', 'Calle de prueba 123, Centro, 23880 Loreto, B.C.S.');
  await page.fill('#maps', 'http://maps.example.com/x');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form.formulario button[type="submit"]')]);
  const error = await page.textContent('.nota--error').catch(() => '');
  comprobar('un enlace de Maps sin https se rechaza', /https/.test(error || ''), error);
  comprobar('no se guardó nada', sql(`SELECT COUNT(*) FROM distribuidores WHERE nombre = '${NOMBRE}'`) === '0');
  comprobar('el formulario conserva lo escrito', (await page.inputValue('#nombre')) === NOMBRE);

  await page.fill('#maps', 'https://maps.app.goo.gl/prueba');
  await page.fill('#telefono', '613 135 0000');
  await page.setInputFiles('#imagen', FOTO);
  await Promise.all([page.waitForURL(`${BASE}/admin/distribuidores`), page.click('form.formulario button[type="submit"]')]);
  const [id, imagen1, orden, publicado] = sql(`SELECT id, IFNULL(imagen,''), orden, publicado FROM distribuidores WHERE nombre = '${NOMBRE}'`).split('\t');
  comprobar('se creó el distribuidor', Number(id) > 0);
  comprobar('queda al final de la lista', Number(orden) === Number(sql('SELECT MAX(orden) FROM distribuidores')), `orden=${orden}`);
  comprobar('queda publicado', publicado === '1');
  comprobar('la fachada y su miniatura están en public/image', /^distribuidor-[0-9a-f]{10}\.jpg$/.test(imagen1) && existeImagen(imagen1) && existeImagen(mini(imagen1)), imagen1);
  await page.goto(`${BASE}/admin/distribuidores?q=${encodeURIComponent(NOMBRE)}`, { waitUntil: 'domcontentloaded' });
  comprobar('la búsqueda lo encuentra', (await page.textContent('.tabla')).includes(NOMBRE));

  console.log('\n4. Edición');
  await page.goto(`${BASE}/admin/distribuidores/editar?id=${id}`, { waitUntil: 'domcontentloaded' });
  await page.fill('#nombre', NOMBRE_EDITADO);
  await page.setInputFiles('#imagen', FOTO);
  await Promise.all([page.waitForURL(`${BASE}/admin/distribuidores`), page.click('form.formulario button[type="submit"]')]);
  const imagen2 = sql(`SELECT IFNULL(imagen,'') FROM distribuidores WHERE id = ${id}`);
  comprobar('se renombró', sql(`SELECT nombre FROM distribuidores WHERE id = ${id}`) === NOMBRE_EDITADO);
  comprobar('la foto nueva reemplaza a la anterior y ésta se borra', imagen2 !== imagen1 && existeImagen(imagen2) && !existeImagen(imagen1) && !existeImagen(mini(imagen1)));

  console.log('\n5. Publicar / ocultar');
  await page.goto(`${BASE}/admin/distribuidores?q=${encodeURIComponent('ZZ Ferretería')}`, { waitUntil: 'domcontentloaded' });
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('form[action="/admin/distribuidores/publicar"] button')]);
  comprobar('se oculta del sitio', sql(`SELECT publicado FROM distribuidores WHERE id = ${id}`) === '0');
  comprobar('vuelve al listado con la búsqueda', page.url().includes('q='), page.url());

  console.log('\n6. Borrado');
  const respuestaGet = await page.goto(`${BASE}/admin/distribuidores/eliminar`, { waitUntil: 'domcontentloaded' });
  comprobar('eliminar por GET no existe', respuestaGet.status() === 404, `status=${respuestaGet.status()}`);
  await page.goto(`${BASE}/admin/distribuidores?q=${encodeURIComponent('ZZ Ferretería')}`, { waitUntil: 'domcontentloaded' });
  await page.click('form[action="/admin/distribuidores/eliminar"] button');
  await Promise.all([page.waitForURL(`${BASE}/admin/distribuidores`), page.click('#modal-pie [data-confirmar-modal]')]);
  comprobar('la fila se borró', sql(`SELECT COUNT(*) FROM distribuidores WHERE id = ${id}`) === '0');
  comprobar('su foto se borró del disco', !existeImagen(imagen2) && !existeImagen(mini(imagen2)));
  comprobar('los demás siguen igual', Number(sql('SELECT COUNT(*) FROM distribuidores')) === totalBase);

  console.log('\n7. Móvil');
  const movil = await navegador.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  const pm = await movil.newPage();
  await entrar(pm);
  for (const ruta of ['/admin/distribuidores', '/admin/distribuidores/crear']) {
    await pm.goto(`${BASE}${ruta}`, { waitUntil: 'domcontentloaded' });
    const desborda = await pm.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    comprobar(`sin desbordamiento horizontal en ${ruta}`, !desborda);
  }
  await movil.close();
  await contexto.close();
} catch (e) {
  fallos++;
  console.log(`  ✗ excepción: ${e.message}`);
} finally {
  await navegador.close();
  limpiar();
}

console.log(`\n${correctas} correctas, ${fallos} fallos`);
process.exit(fallos ? 1 : 0);

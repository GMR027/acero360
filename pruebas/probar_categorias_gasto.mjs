/**
 * probar_categorias_gasto.mjs — Autodetección del tipo de gasto.
 *
 * Comprueba que el formulario de gasto y la lectura inteligente usan el MISMO
 * listado de reglas (CoincidenciasFactura::REGLAS_CATEGORIA) y que sugieren lo
 * que se espera:
 *
 *   1. El atributo data-reglas del selector llega con los pares [nombre, patrón]
 *      y es idéntico al que genera PHP (si alguien vuelve a duplicar la lista,
 *      esta prueba se da cuenta).
 *   2. Al escribir en «¿En qué fue el gasto?» el navegador sugiere la categoría.
 *   3. Las tres categorías nuevas existen, están activas y salen en el selector.
 *   4. Un texto sin regla (el concepto de un CFDI de pagos) NO sugiere nada.
 *
 * Crea su propio usuario y lo borra al final (usuario_id es RESTRICT).
 *
 * Uso: node pruebas/probar_categorias_gasto.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8017');
const EMAIL = 'prueba.categorias.gasto@cotizaciones.local';
const CLAVE = 'Prueba1234!';

const sql = (consulta) =>
  execFileSync('mysql', ['-u', 'root', process.env.DB_NAME || 'cotizaciones', '-N', '-B', '-e', consulta], {
    encoding: 'utf8',
  }).trim();

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

/** Listado de reglas tal como lo genera el servidor (misma fuente que usa la lectura inteligente). */
function reglasDePhp() {
  const crudo = execFileSync(
    'php',
    ['-r', 'require "vendor/autoload.php"; echo json_encode(array_map(static fn ($n, $p) => [$n, $p], array_keys(App\\CoincidenciasFactura::REGLAS_CATEGORIA), array_values(App\\CoincidenciasFactura::REGLAS_CATEGORIA)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);'],
    { encoding: 'utf8', cwd: process.cwd() }
  );
  return JSON.parse(crudo);
}

/** Categoría que sugiere el servidor para un texto (la misma lógica que usa la lectura inteligente). */
function categoriaEnPhp(texto) {
  const crudo = execFileSync(
    'php',
    ['-r', 'require "vendor/autoload.php"; $t = mb_strtolower($argv[1], "UTF-8"); $t = strtr($t, ["á"=>"a","é"=>"e","í"=>"i","ó"=>"o","ú"=>"u","ü"=>"u","ñ"=>"n"]); $t = trim(preg_replace("/\\s+/u", " ", preg_replace("/[^a-z0-9]+/u", " ", $t))); foreach (App\\CoincidenciasFactura::REGLAS_CATEGORIA as $n => $p) { if (preg_match("/" . $p . "/iu", $t)) { echo $n; exit; } }', texto],
    { encoding: 'utf8', cwd: process.cwd() }
  ).trim();
  return crudo === '' ? null : crudo;
}

/** Textos que el formulario y la lectura inteligente deben clasificar igual. */
const CASOS = [
  ['gasolina premium 91 octanos', 'Combustible y transporte'],
  ['servicio de peaje y cruce carretero', 'Combustible y transporte'],
  ['cable thw 6 mt', 'Ferretería y materiales'],
  ['disco de corte 14', 'Ferretería y materiales'],
  ['tornillo hexagonal 3/8', 'Ferretería y materiales'],
  ['varilla tec 60 5/16', 'Material y acero'],
  ['registro para vialidades 50x70', 'Material y acero'],
  ['predial 2026', 'Impuestos y derechos'],
  ['comision bancaria mensual', 'Gastos bancarios y comisiones'],
  ['flete y maniobra', 'Flete y maniobras'],
  ['curso de soldadura', 'Seguridad y capacitación'],
  ['electrodo de soldadura', 'Insumos de taller'],
  ['alineacion y balanceo', 'Mantenimiento'],
];

const CATEGORIAS_NUEVAS = ['Gastos bancarios y comisiones', 'Flete y maniobras', 'Seguridad y capacitación'];

function prepararUsuario() {
  sql(`DELETE FROM usuarios WHERE email = '${EMAIL}'`);
  const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(CLAVE)}, PASSWORD_BCRYPT);`], {
    encoding: 'utf8',
  }).trim();
  sql(
    `INSERT INTO usuarios (nombre, email, contrasena, telefono, rol) ` +
      `VALUES ('Prueba categorías gasto', '${EMAIL}', '${hash}', '', 1)`
  );
  return Number(sql(`SELECT id FROM usuarios WHERE email = '${EMAIL}'`));
}

const usuarioId = prepararUsuario();
let navegador = null;

try {
  console.log('\n1. Las reglas del servidor y las del navegador son la misma lista');
  const esperadas = reglasDePhp();
  comprobar(`el servidor manda ${esperadas.length} reglas`, esperadas.length >= 19, `quedaron ${esperadas.length}`);
  comprobar('cada regla es un par [nombre, patrón]', esperadas.every((par) => Array.isArray(par) && par.length === 2 && par[1] !== ''));

  navegador = await chromium.launch();
  const pagina = await (await navegador.newContext({ viewport: { width: 1440, height: 900 } })).newPage();

  const errores = [];
  pagina.on('pageerror', (error) => errores.push(String(error)));

  await pagina.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await pagina.fill('#email', EMAIL);
  await pagina.fill('#contrasena', CLAVE);
  await Promise.all([pagina.waitForURL('**/admin**', { timeout: 15000 }), pagina.click('button[type="submit"]')]);

  await pagina.goto(`${BASE}/admin/contabilidad/gastos/crear`, { waitUntil: 'networkidle' });

  const llegan = await pagina.evaluate(() => {
    const selector = document.querySelector('[data-sugerir-categoria]');
    try {
      return JSON.parse(selector.getAttribute('data-reglas') || '[]');
    } catch (error) {
      return [];
    }
  });

  comprobar('el formulario recibe las mismas reglas que la lectura inteligente',
    JSON.stringify(llegan) === JSON.stringify(esperadas),
    `navegador=${llegan.length} php=${esperadas.length}`);

  console.log('\n2. Cada texto sugiere la categoría esperada');
  for (const [texto, esperado] of CASOS) {
    const servidor = categoriaEnPhp(texto);
    comprobar(`PHP sugiere «${esperado}» para «${texto}»`, servidor === esperado, `dio ${servidor ?? '(nada)'}`);

    await pagina.fill('#concepto', texto);
    await pagina.dispatchEvent('#concepto', 'input');
    await pagina.waitForTimeout(50);
    const navegadorSugiere = (await pagina.locator('#categoria_id option:checked').textContent()).trim();
    comprobar(`el formulario sugiere «${esperado}» para «${texto}»`, navegadorSugiere === esperado, `dio ${navegadorSugiere}`);
    await pagina.selectOption('#categoria_id', '');
  }

  console.log('\n3. Sin regla no se inventa nada');
  await pagina.fill('#concepto', 'Pago');
  await pagina.dispatchEvent('#concepto', 'input');
  await pagina.waitForTimeout(50);
  comprobar('el concepto de un CFDI de pagos no sugiere categoría',
    (await pagina.locator('#categoria_id option:checked').textContent()).trim() === '— Elige un tipo —');

  console.log('\n4. Las categorías nuevas existen y salen en el selector');
  for (const nombre of CATEGORIAS_NUEVAS) {
    const id = sql(`SELECT IFNULL(MAX(id), 0) FROM categorias_gasto WHERE nombre = '${nombre}' AND activa = 1`);
    comprobar(`«${nombre}» está dada de alta y activa`, Number(id) > 0);
    comprobar(`«${nombre}» aparece en el selector`,
      (await pagina.locator(`#categoria_id option:text-is("${nombre}")`).count()) === 1);
  }

  comprobar('sin errores de JavaScript', errores.length === 0, errores.join(' | '));
} catch (error) {
  fallos += 1;
  console.log(`  ✗ la prueba se interrumpió — ${error.message}`);
} finally {
  if (navegador) await navegador.close().catch(() => {});
  sql(`DELETE FROM usuarios WHERE id = ${usuarioId}`);
}

console.log(`\nResultado: ${correctas} correctas, ${fallos} fallidas.`);
process.exit(fallos > 0 ? 1 : 0);

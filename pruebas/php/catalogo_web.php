<?php
/**
 * pruebas/php/catalogo_web.php — Datos del catálogo público (migración 053).
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/catalogo_web.php
 *
 * Comprueba: slug generado al crear (sin acentos, único), que NO cambia al
 * renombrar, slug editado a mano (normalizado y sin duplicados), descripción
 * y orden, que ubicaciones_cliente (sin campos web) sigue igual, y que
 * productos.publicado_web se guarda y se conserva al editar.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';

use App\CatalogoTexto;
use App\Productos;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};
$uno = fn (string $q) => $db->query($q)->fetch_row()[0] ?? null;

$limpiar = function () use ($db) {
    $db->query("DELETE FROM productos WHERE nombre LIKE 'T53 %'");
    $db->query("DELETE FROM categorias_producto WHERE nombre LIKE 'T53%'");
    $db->query("DELETE FROM ubicaciones_cliente WHERE nombre LIKE 'T53%'");
};
$limpiar();

/* --- Esquema ----------------------------------------------------------------- */
$prueba('productos.publicado_web existe', (bool) $uno("SHOW COLUMNS FROM productos LIKE 'publicado_web'"));
foreach (['slug', 'descripcion', 'imagen', 'orden'] as $col) {
    $prueba("categorias_producto.$col existe", (bool) $uno("SHOW COLUMNS FROM categorias_producto LIKE '$col'"));
}
$prueba('toda categoría tiene slug', (int) $uno("SELECT COUNT(*) FROM categorias_producto WHERE slug IS NULL OR slug = ''") === 0);

/* --- Slug al crear ------------------------------------------------------------ */
$a = new CatalogoTexto('categorias_producto', ['nombre' => 'T53 Línea Ángulo']);
$prueba('categoría válida', $a->validar() === [], implode(' ', CatalogoTexto::getErrores()));
$a->crear();
$prueba('slug sin acentos', $a->slug === 't53-linea-angulo', $a->slug);
$prueba('slug guardado', $uno("SELECT slug FROM categorias_producto WHERE id = {$a->id}") === 't53-linea-angulo');

$b = new CatalogoTexto('categorias_producto', ['nombre' => 'T53 línea / ángulo']);
$b->validar();
$b->crear();
$prueba('slug repetido recibe sufijo', $b->slug === 't53-linea-angulo-2', $b->slug);

/* --- Renombrar no cambia el slug --------------------------------------------- */
$a2 = CatalogoTexto::buscarID('categorias_producto', $a->id);
$a2->nombre = 'T53 Registros renombrados';
$a2->descripcion = '  Texto   para la web  ';
$a2->orden = 7;
$prueba('renombrar es válido', $a2->validar() === [], implode(' ', CatalogoTexto::getErrores()));
$a2->actualizar();
$fila = $db->query("SELECT nombre, slug, descripcion, orden FROM categorias_producto WHERE id = {$a->id}")->fetch_assoc();
$prueba('renombrar conserva el slug', $fila['slug'] === 't53-linea-angulo', $fila['slug']);
$prueba('descripción normalizada', $fila['descripcion'] === 'Texto para la web', (string) $fila['descripcion']);
$prueba('orden guardado', (int) $fila['orden'] === 7);

/* --- Slug editado a mano ----------------------------------------------------- */
$a3 = CatalogoTexto::buscarID('categorias_producto', $a->id);
$a3->slug = 'T53 Mi Dirección';
$prueba('slug manual válido', $a3->validar() === [], implode(' ', CatalogoTexto::getErrores()));
$prueba('slug manual normalizado', $a3->slug === 't53-mi-direccion', $a3->slug);
$a3->actualizar();

$b3 = CatalogoTexto::buscarID('categorias_producto', $b->id);
$b3->slug = 't53-mi-direccion';
$prueba('slug ocupado por otra se rechaza', $b3->validar() !== []);
CatalogoTexto::getErrores();

$larga = CatalogoTexto::buscarID('categorias_producto', $b->id);
$larga->descripcion = str_repeat('x', 256);
$prueba('descripción de más de 255 se rechaza', $larga->validar() !== []);
CatalogoTexto::getErrores();

/* --- Ubicaciones sin campos web --------------------------------------------- */
$u = new CatalogoTexto('ubicaciones_cliente', ['nombre' => 'T53 Ubicación']);
$prueba('ubicación válida', $u->validar() === []);
$prueba('ubicación se crea', $u->crear());
$u->nombre = 'T53 Ubicación renombrada';
$prueba('ubicación se renombra', $u->validar() === [] && $u->actualizar());
$prueba('tomarFormularioWeb no aplica a ubicaciones', $u->tomarFormularioWeb(['descripcion' => 'x'], []) === [] && $u->descripcion === '');

/* --- Productos.publicado_web ------------------------------------------------- */
$empresa = (int) $uno('SELECT id FROM empresas WHERE activa = 1 ORDER BY id LIMIT 1');
$p = new Productos(['empresa_id' => $empresa, 'negocios' => [$empresa], 'nombre' => 'T53 Producto web',
    'precio' => 10, 'activo' => 1, 'publicado_web' => 1, 'categoria_id' => $a->id]);
$prueba('producto válido', $p->validar() === [], implode(' ', Productos::getErrores()));
$p->crear();
$prueba('publicado_web se guarda', (int) $uno("SELECT publicado_web FROM productos WHERE id = {$p->id}") === 1);
$p2 = Productos::buscarID($p->id);
$p2->precio = 12;
$p2->actualizar();
$prueba('editar otro campo conserva publicado_web', (int) $uno("SELECT publicado_web FROM productos WHERE id = {$p->id}") === 1);
$p2->publicado_web = 0;
$p2->actualizar();
$prueba('desmarcar publicado_web', (int) $uno("SELECT publicado_web FROM productos WHERE id = {$p->id}") === 0);
$sinMarca = new Productos(['empresa_id' => $empresa, 'negocios' => [$empresa], 'nombre' => 'T53 Producto nuevo', 'precio' => 1]);
$sinMarca->validar();
$sinMarca->crear();
$prueba('producto nuevo NO se publica por omisión', (int) $uno("SELECT publicado_web FROM productos WHERE id = {$sinMarca->id}") === 0);

$limpiar();
echo $fallos === 0 ? "Resultado: todas las pruebas pasaron\n" : "Resultado: $fallos fallas\n";
exit($fallos === 0 ? 0 : 1);

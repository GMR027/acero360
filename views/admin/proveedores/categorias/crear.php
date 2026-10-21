<?php
/**
 * views/admin/proveedores/categorias/crear.php — Alta de categorías.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * El alta y la edición comparten el mismo formulario:
 * templates/formularioCategorias.php
 *
 * Tras guardar se vuelve al listado: no hay detalle propio, y la alerta de
 * éxito (flash de sesión) avisa que la categoría ya está disponible en el
 * campo «Categoría» de todas las entradas.
 */

soloAdmin();

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $categoria = new \App\CategoriasProveedor([
        'nombre' => trim($_POST['nombre'] ?? ''),
    ]);

    $errores = $categoria->validar();

    if (empty($errores) && $categoria->crear()) {
        redirigir('/admin/proveedores/categorias', 'exito', 'Categoría creada correctamente.');
    }

    $errores = $errores ?: \App\CategoriasProveedor::getErrores();
} else {
    $categoria = new \App\CategoriasProveedor();
}

template('header', [
    'titulo'    => 'Nueva categoría',
    'subtitulo' => 'Categorías de compra',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores/categorias">Volver</a>',
]);
template('tabsCompras', ['tab' => 'categorias']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Agrega una categoría para clasificar las entradas de material. Se aplicará a
            <strong>todos</strong> los proveedores desde el momento en que la guardes.
        </p>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/proveedores/categorias/crear" method="post"
          data-validar novalidate>
        <?php template('formularioCategorias', ['categoria' => $categoria, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>

<?php
/**
 * views/admin/proveedores/categorias/editar.php — Modificación de categorías.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 *
 * Renombrar es el único cambio seguro para una categoría en uso: sólo se
 * actualiza el texto de la tabla, mientras las entradas siguen apuntando al
 * mismo id. Es la razón por la que la tabla guarda el id y no el nombre.
 */

soloAdmin();

/** Categoría a editar. Si no existe, se muestra el 404. */
$categoria = \App\CategoriasProveedor::buscarID((int) ($_GET['id'] ?? 0));

if (!$categoria) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $categoria->nombre = trim($_POST['nombre'] ?? '');

    $errores = $categoria->validar();

    if (empty($errores) && $categoria->actualizar()) {
        redirigir('/admin/proveedores/categorias', 'exito', 'Categoría actualizada correctamente.');
    }

    $errores = $errores ?: \App\CategoriasProveedor::getErrores();
}

/** Cuántas entradas usan esta categoría: se avisa si no se puede borrar. */
$usos = \App\CategoriasProveedor::enUso((int) $categoria->id);

template('header', [
    'titulo'    => 'Editar categoría',
    'subtitulo' => 'Categorías de compra',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proveedores/categorias">Volver</a>',
]);
template('tabsCompras', ['tab' => 'categorias']);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Modifica el nombre de <strong><?= escaparValores($categoria->nombre) ?></strong>.
            <?php if ($usos > 0) : ?>
                Lo usan <?= number_format($usos) ?> <?= $usos === 1 ? 'entrada' : 'entradas' ?>:
                renombrarla sólo cambia el texto que se muestra, nunca las entradas.
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/proveedores/categorias/editar?id=<?= (int) $categoria->id ?>"
          method="post" data-validar novalidate>
        <?php template('formularioCategorias', ['categoria' => $categoria, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>

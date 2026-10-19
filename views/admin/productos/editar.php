<?php
/**
 * views/admin/productos/editar.php — Modificación de productos.
 *
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 * Si no se adjunta una imagen nueva, se conserva la que ya tenía.
 */

soloAdmin();

$producto = \App\Productos::buscarID((int) ($_GET['id'] ?? 0));

if (!$producto) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores     = [];
$esEnvio     = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$imagenPrevia = $producto->imagen;
$empresas    = \App\Empresas::mostrarTodos(100, 0);

if ($esEnvio) {
    $producto->empresa_id  = (int) ($_POST['empresa_id'] ?? 0);
    $producto->nombre      = trim($_POST['nombre'] ?? '');
    $producto->sku         = trim($_POST['sku'] ?? '');
    $producto->categoria   = trim($_POST['categoria'] ?? '');
    $producto->unidad      = $_POST['unidad'] ?? 'pza';
    $producto->descripcion = trim($_POST['descripcion'] ?? '');
    $producto->precio      = str_replace(',', '.', trim($_POST['precio'] ?? '0'));
    $producto->activo      = isset($_POST['activo']) ? 1 : 0;

    $errores = $producto->validar();

    // Sólo se procesa la imagen si se adjuntó una nueva.
    $archivo = $_FILES['imagen'] ?? null;
    if (is_array($archivo) && !empty($archivo['tmp_name'])) {
        $imagen = \App\Imagenes::subir($archivo, 'producto', 'cuadrada');
        if ($imagen !== null) {
            $producto->imagen = $imagen;
        } else {
            $errores[] = \App\Imagenes::ultimoError();
        }
    }

    if (empty($errores) && $producto->actualizar()) {
        // La imagen anterior sólo se borra cuando ya quedó sustituida.
        if ($producto->imagen !== $imagenPrevia) {
            \App\Imagenes::eliminar($imagenPrevia ?: null);
        }
        redirigir('/admin/productos', 'exito', 'Producto actualizado correctamente.');
    }

    $errores = $errores ?: \App\Productos::getErrores();
}

template('header', [
    'titulo'     => 'Editar producto',
    'subtitulo'  => 'Catálogo de productos',
    'rutaActiva' => '/admin/productos',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">Modifica <strong><?= escaparValores($producto->nombre) ?></strong>.</p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/productos">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/productos/editar?id=<?= (int) $producto->id ?>" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioProductos', [
            'producto' => $producto,
            'errores'  => $errores,
            'empresas' => $empresas,
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

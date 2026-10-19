<?php
/**
 * views/admin/productos/crear.php — Alta de productos.
 *
 * El formulario admite carga de imagen, así que necesita multipart/form-data.
 * Alta y edición comparten templates/formularioProductos.php.
 */

soloAdmin();

$errores  = [];
$esEnvio  = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);
$empresas = \App\Empresas::mostrarTodos(100, 0);

if ($esEnvio) {
    $producto = new \App\Productos([
        'empresa_id'  => (int) ($_POST['empresa_id'] ?? 0),
        'nombre'      => trim($_POST['nombre'] ?? ''),
        'sku'         => trim($_POST['sku'] ?? ''),
        'categoria'   => trim($_POST['categoria'] ?? ''),
        'unidad'      => $_POST['unidad'] ?? 'pza',
        'descripcion' => trim($_POST['descripcion'] ?? ''),
        'precio'      => str_replace(',', '.', trim($_POST['precio'] ?? '0')),
        'activo'      => isset($_POST['activo']) ? 1 : 0,
    ]);

    $errores = $producto->validar();

    // Imagen: SÓLO <input type="file">, recortada a cuadrado 1000×1000.
    $archivo = $_FILES['imagen'] ?? null;
    if (is_array($archivo) && !empty($archivo['tmp_name'])) {
        $imagen = \App\Imagenes::subir($archivo, 'producto', 'cuadrada');
        if ($imagen !== null) {
            $producto->imagen = $imagen;
        } else {
            $errores[] = \App\Imagenes::ultimoError();
        }
    }

    if (empty($errores) && $producto->crear()) {
        redirigir('/admin/productos', 'exito', 'Producto creado correctamente.');
    }

    $errores = $errores ?: \App\Productos::getErrores();
} else {
    $producto = new \App\Productos([
        'empresa_id' => (int) ($_GET['empresa'] ?? 0),
    ]);
}

template('header', [
    'titulo'     => 'Nuevo producto',
    'subtitulo'  => 'Catálogo de productos',
    'rutaActiva' => '/admin/productos',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Nuevo producto</h1> -->
        <p class="texto-secundario">
            El precio de lista es fijo; el precio por cliente se acuerda en cada cotización.
            Los campos marcados con asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/productos">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/productos/crear" method="post"
          enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioProductos', [
            'producto' => $producto,
            'errores'  => $errores,
            'empresas' => $empresas,
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

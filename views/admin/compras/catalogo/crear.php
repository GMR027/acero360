<?php
/**
 * views/admin/compras/catalogo/crear.php — Nuevo producto semiterminado de un proveedor.
 */

soloAdmin();

$errores  = [];
$registro = ['proveedor_id' => (int) ($_GET['proveedor'] ?? 0), 'activo' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $registro = [
        'proveedor_id'   => (int) ($_POST['proveedor_id'] ?? 0),
        'nombre'         => trim((string) ($_POST['nombre'] ?? '')),
        'codigo'         => trim((string) ($_POST['codigo'] ?? '')),
        'descripcion'    => trim((string) ($_POST['descripcion'] ?? '')),
        'costo_unitario' => str_replace(',', '.', trim((string) ($_POST['costo_unitario'] ?? ''))),
        'activo'         => isset($_POST['activo']) ? 1 : 0,   // casilla desmarcada = inactivo
    ];
    // Imagen del artículo: solo <input type="file">; se recorta a cuadrado.
    $archivo = $_FILES['imagen'] ?? null;
    $imagenNueva = null;
    if (is_array($archivo) && !empty($archivo['tmp_name'])) {
        $imagenNueva = \App\Imagenes::subir($archivo, 'catalogo', 'cuadrada');
        if ($imagenNueva === null) {
            $errores[] = \App\Imagenes::ultimoError();
        }
    }
    if (!empty($_POST['quitar_imagen']) && $imagenNueva === null) {
        $imagenNueva = '';
    }
    if ($imagenNueva !== null) {
        $registro['imagen'] = $imagenNueva;
    }
    if (!$errores && \App\ProductosProveedor::crear($registro, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/compras/catalogo/detalle?id=' . \App\ProductosProveedor::$ultimoId, 'exito', 'Producto del proveedor guardado.');
    }
    $errores = array_merge($errores, \App\ProductosProveedor::getErrores());
    if (!empty($imagenNueva)) {   // no se guardó el registro: no dejar el archivo huérfano
        \App\Imagenes::eliminar($imagenNueva);
        unset($registro['imagen']);
    }
}

template('header', ['titulo' => 'Nuevo producto de proveedor', 'subtitulo' => 'Catálogo de semiterminados']);
template('tabsCompras', ['tab' => 'catalogo']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/catalogo/crear" method="post" enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioProductoProveedor', [
            'registro' => $registro, 'errores' => $errores, 'editando' => false,
            'proveedores' => \App\Proveedores::paraSelector(), 'cancelar' => '/admin/compras/catalogo',
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

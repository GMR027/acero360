<?php
/**
 * views/admin/compras/catalogo/editar.php — Edita un producto semiterminado de un proveedor.
 */

soloAdmin();

$id       = (int) ($_GET['id'] ?? 0);
$registro = \App\ProductosProveedor::buscarID($id);
if (!$registro) {
    paginaNoEncontrada();
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $registro = [
        'id'             => $id,
        'proveedor_id'   => (int) ($_POST['proveedor_id'] ?? 0),
        'nombre'         => trim((string) ($_POST['nombre'] ?? '')),
        'codigo'         => trim((string) ($_POST['codigo'] ?? '')),
        'descripcion'    => trim((string) ($_POST['descripcion'] ?? '')),
        'costo_unitario' => str_replace(',', '.', trim((string) ($_POST['costo_unitario'] ?? ''))),
        'activo'         => isset($_POST['activo']) ? 1 : 0,
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
    if (!$errores && \App\ProductosProveedor::actualizar($id, $registro, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/compras/catalogo/detalle?id=' . $id, 'exito', 'Producto del proveedor actualizado.');
    }
    $errores = array_merge($errores, \App\ProductosProveedor::getErrores());
    if (!empty($imagenNueva)) {
        \App\Imagenes::eliminar($imagenNueva);
    }
    $registro['imagen'] = \App\ProductosProveedor::buscarID($id)['imagen'] ?? null;
}

// El proveedor actual se ofrece aunque esté inactivo (para no perder la asignación al editar).
$proveedores = \App\Proveedores::paraSelector();
if (!in_array((int) ($registro['proveedor_id'] ?? 0), array_column($proveedores, 'id'), true)) {
    $actual = \App\Proveedores::buscarID((int) $registro['proveedor_id']);
    if ($actual) {
        array_unshift($proveedores, ['id' => (int) $actual->id, 'nombre' => $actual->nombre, 'activo' => false, 'etiqueta' => $actual->nombre . ' (inactivo)']);
    }
}

template('header', ['titulo' => 'Editar producto de proveedor', 'subtitulo' => 'Catálogo de semiterminados']);
template('tabsCompras', ['tab' => 'catalogo']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/catalogo/editar?id=<?= $id ?>" method="post" enctype="multipart/form-data" data-validar novalidate>
        <?php template('formularioProductoProveedor', [
            'registro' => $registro, 'errores' => $errores, 'editando' => true,
            'proveedores' => $proveedores, 'cancelar' => '/admin/compras/catalogo/detalle?id=' . $id,
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

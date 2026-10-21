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
    $seleccion = array_values(array_unique(array_map('intval', array_filter((array) ($_POST['negocios'] ?? [])))));
    $producto->negocios    = $seleccion;
    // El negocio principal se conserva mientras siga asignado.
    $producto->empresa_id  = in_array((int) $producto->empresa_id, $seleccion, true)
        ? (int) $producto->empresa_id : ($seleccion[0] ?? 0);
    $producto->costo_actual = str_replace(',', '.', trim($_POST['costo_actual'] ?? '0'));
    $producto->stock_minimo = trim($_POST['stock_minimo'] ?? '') === '' ? null : (int) $_POST['stock_minimo'];
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

<?php $semiterminados = \App\ProductosProveedor::deProducto((int) $producto->id); ?>
<section class="tarjeta tarjeta--sin-relleno mt-lg" id="semiterminados">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Semiterminados de proveedores que se usan para este producto</h2>
    <p class="texto-sm texto-secundario" style="padding:.5rem 1rem 0">Referencia informativa: no cambia el costo ni el precio de este producto.
        Se administra desde el catálogo de proveedores.</p>
    <?php if (!$semiterminados) : ?>
        <p class="texto-secundario" style="padding:1rem">Aún no hay semiterminados relacionados. Puedes relacionarlos desde
            <a href="/admin/compras/catalogo">Compras y proveedores → Catálogo</a>.</p>
    <?php else : ?>
        <div class="tabla-contenedor"><table class="tabla">
            <caption class="solo-lectores">Semiterminados relacionados con este producto</caption>
            <thead><tr><th scope="col">Proveedor</th><th scope="col">Semiterminado</th><th scope="col">Código</th>
                <th scope="col" class="tabla--numerica">Costo de compra</th><th scope="col" class="tabla--centro">Estado</th></tr></thead>
            <tbody>
            <?php foreach ($semiterminados as $sm) : ?>
                <tr<?= (int) $sm['activo'] === 1 ? '' : ' style="opacity:.65"' ?>>
                    <td><a href="/admin/proveedores/detalle?id=<?= (int) $sm['proveedor_id'] ?>"><?= escaparValores($sm['proveedor_nombre']) ?></a></td>
                    <td><a href="/admin/compras/catalogo/detalle?id=<?= (int) $sm['id'] ?>"><strong><?= escaparValores($sm['nombre']) ?></strong></a></td>
                    <td><?= escaparValores((string) $sm['codigo']) ?: '—' ?></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($sm['costo_unitario']) ?></td>
                    <td class="tabla--centro"><span class="badge <?= (int) $sm['activo'] === 1 ? 'badge--aprobado' : 'badge--neutro' ?>"><?= (int) $sm['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

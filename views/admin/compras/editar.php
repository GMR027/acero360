<?php
/**
 * views/admin/compras/editar.php — Edita una compra mientras no tenga recepciones, pagos ni gasto vigentes.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$orden = \App\OrdenesCompra::buscarID($id);

if (!$orden) {
    paginaNoEncontrada();
}
if (!in_array($orden['estado'], ['solicitada', 'sin_recepcion'], true)) {
    redirigir('/admin/compras/detalle?id=' . $id, 'error', 'Solo se puede editar una compra que aún no tenga recepciones.');
}

$errores = [];
$lineas  = \App\OrdenesCompra::lineasDeOrden($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $orden  = ['id' => $id] + \App\OrdenesCompra::encabezadoDePost($_POST);
    $lineas = \App\OrdenesCompra::lineasDePost($_POST);

    if (\App\OrdenesCompra::actualizar($id, $orden, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/compras/detalle?id=' . $id, 'exito', 'Compra actualizada.');
    }
    $errores = \App\OrdenesCompra::getErrores();
}

template('header', ['titulo' => 'Editar compra', 'subtitulo' => 'Compras y proveedores']);
template('tabsCompras', ['tab' => 'pedidos']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/editar?id=<?= $id ?>" method="post" novalidate>
        <?php template('formularioOrdenCompra', [
            'orden' => $orden, 'lineas' => $lineas, 'errores' => $errores,
            'proveedores' => \App\Proveedores::paraSelector(),
            'catalogo' => \App\Productos::paraCotizador(),
            'categorias' => \App\CategoriasProveedor::mapa(),
            'empresas' => \App\Empresas::paraSelector(),
            'materiales' => \App\Materiales::listar(),
            'catalogos' => \App\ProductosProveedor::paraFormulario(array_column($lineas, 'proveedor_producto_id')),
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

<?php
/**
 * views/admin/compras/crear.php — Nueva compra (semiterminado o insumo). No toca el inventario.
 */

soloAdmin();

$errores = [];
$orden   = ['tipo' => 'semiterminado', 'proveedor_id' => (int) ($_GET['proveedor'] ?? 0), 'fecha_solicitud' => date('Y-m-d')];
$lineas  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $orden  = \App\OrdenesCompra::encabezadoDePost($_POST);
    $lineas = \App\OrdenesCompra::lineasDePost($_POST);

    if (\App\OrdenesCompra::crear($orden, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/compras/detalle?id=' . \App\OrdenesCompra::$ultimoId, 'exito', 'Compra registrada. El inventario no se modificó: solo cambia al recibir.');
    }
    $errores = \App\OrdenesCompra::getErrores();
}

template('header', ['titulo' => 'Nueva compra', 'subtitulo' => 'Compras y proveedores']);
template('tabsCompras', ['tab' => 'pedidos']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/crear" method="post" novalidate>
        <?php template('formularioOrdenCompra', [
            'orden' => $orden, 'lineas' => $lineas, 'errores' => $errores,
            'proveedores' => \App\Proveedores::paraSelector(),
            'catalogo' => \App\Productos::paraCotizador(),
            'categorias' => \App\CategoriasProveedor::mapa(),
            'empresas' => \App\Empresas::paraSelector(),
            'materiales' => \App\Materiales::listar(),
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

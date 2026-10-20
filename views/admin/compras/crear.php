<?php
/**
 * views/admin/compras/crear.php — Nueva orden de compra (no toca el inventario).
 */

soloAdmin();

$errores = [];
$orden   = ['proveedor_id' => (int) ($_GET['proveedor'] ?? 0), 'fecha_solicitud' => date('Y-m-d'), 'observaciones' => ''];
$lineas  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $orden = [
        'folio'           => trim((string) ($_POST['folio'] ?? '')),
        'proveedor_id'    => (int) ($_POST['proveedor_id'] ?? 0),
        'fecha_solicitud' => (string) ($_POST['fecha_solicitud'] ?? ''),
        'observaciones'   => trim($_POST['observaciones'] ?? ''),
    ];
    $lineas = \App\OrdenesCompra::lineasDePost($_POST);

    if (\App\OrdenesCompra::crear($orden, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/compras/detalle?id=' . \App\OrdenesCompra::$ultimoId, 'exito', 'Orden de compra creada. El inventario no se modificó.');
    }
    $errores = \App\OrdenesCompra::getErrores();
}

template('header', ['titulo' => 'Nueva orden de compra', 'subtitulo' => 'Compras a proveedores']);
template('tabsCompras', ['tab' => 'ordenes']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/crear" method="post" novalidate>
        <?php template('formularioOrdenCompra', [
            'orden' => $orden, 'lineas' => $lineas, 'errores' => $errores,
            'proveedores' => \App\Proveedores::paraSelector(),
            'catalogo' => \App\Productos::paraCotizador(),
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

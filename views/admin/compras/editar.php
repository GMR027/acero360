<?php
/**
 * views/admin/compras/editar.php — Edita una orden mientras no tenga recepciones.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? 0);
$orden = \App\OrdenesCompra::buscarID($id);

if (!$orden) {
    paginaNoEncontrada();
}
if ($orden['estado'] !== 'solicitada') {
    redirigir('/admin/compras/detalle?id=' . $id, 'error', 'Solo se puede editar una orden solicitada sin recepciones.');
}

$errores = [];
$lineas  = array_map(static fn ($p) => [
    'producto_id' => (int) $p['producto_id'], 'cantidad' => (int) $p['solicitada'],
    'costo' => $p['costo_esperado'], 'descripcion' => $p['descripcion'],
], \App\OrdenesCompra::partidas($id));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $orden = [
        'id'              => $id,
        'proveedor_id'    => (int) ($_POST['proveedor_id'] ?? 0),
        'fecha_solicitud' => (string) ($_POST['fecha_solicitud'] ?? ''),
        'observaciones'   => trim($_POST['observaciones'] ?? ''),
    ];
    $lineas = \App\OrdenesCompra::lineasDePost($_POST);

    if (\App\OrdenesCompra::actualizar($id, $orden, $lineas, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/compras/detalle?id=' . $id, 'exito', 'Orden actualizada.');
    }
    $errores = \App\OrdenesCompra::getErrores();
}

template('header', ['titulo' => 'Editar orden de compra', 'subtitulo' => 'Compras a proveedores']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/compras/editar?id=<?= $id ?>" method="post" novalidate>
        <?php template('formularioOrdenCompra', [
            'orden' => $orden, 'lineas' => $lineas, 'errores' => $errores,
            'proveedores' => \App\Proveedores::paraSelector(),
            'catalogo' => \App\Productos::paraCotizador(),
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

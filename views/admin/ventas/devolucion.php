<?php
/**
 * views/admin/ventas/devolucion.php — Devolución de piezas de una venta.
 *
 * La devolución es una NOTIFICACIÓN: queda anotada en la venta (ajusta el
 * pendiente de entrega y lo que se le debe al cliente) pero NO modifica el
 * inventario. Si lo que faltó en la entrega fue el producto, eso se reporta
 * desde el seguimiento con el motivo «Producto faltante», que sí corrige
 * inventario y factura.
 *
 * Si viene `volver=seguimiento` (modal del hub de Operación Comercial), al
 * terminar se regresa al seguimiento de la venta.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}

$alSeguimiento = (string) ($_GET['volver'] ?? $_POST['volver'] ?? '') === 'seguimiento';
$volver = $alSeguimiento
    ? '/admin/entregas-seguimiento/venta?id=' . $id
    : '/admin/ventas/detalle?id=' . $id;

if ($venta->estado !== 'registrada') {
    redirigir($volver, 'error', 'Solo se pueden devolver piezas de una venta registrada.');
}

$errores = [];
$hoy = date('Y-m-d');
$fecha = $hoy;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $fecha = (string) ($_POST['fecha'] ?? $hoy);
    $items = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $productoId => $cantidad) {
        $items[(int) $productoId] = ['devuelta' => (int) $cantidad, 'cambio' => (int) ($_POST['cambio'][$productoId] ?? 0)];
    }

    // Un solo envío por formulario: recargar o hacer doble clic no registra la devolución otra vez.
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($volver, 'error', 'Este formulario ya se envió; la devolución no se registró dos veces.');
    }

    if (\App\Inventario::registrarDevolucion($id, $items, $fecha, (string) ($_POST['nota'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        $debe = array_sum(array_column(\App\Inventario::cambiosPendientes($id), 'pendiente'));
        redirigir($volver, 'exito', 'Devolución registrada (sólo notificación: el inventario no cambia).'
            . ($debe > 0 ? " Quedan $debe pieza(s) de cambio por entregar al cliente." : ''));
    }
    $errores = \App\Inventario::getErrores();
}

$devolvibles = \App\Inventario::devolvibles($id);
$detalles    = \App\Ventas::detalles($id);

template('header', ['titulo' => 'Devolución · ' . $venta->folio, 'subtitulo' => 'Devoluciones y cambios', 'rutaActiva' => '/admin/ventas']);
?>

<section class="tarjeta">
    <p class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span>La devolución <strong>no mueve el inventario</strong>: sólo queda anotada en la venta (ajusta el pendiente
            de entrega y deja constancia de lo que se le debe al cliente). Si el producto faltó en la entrega, repórtalo
            desde el <a href="/admin/entregas-seguimiento/venta?id=<?= $id ?>">seguimiento de la venta</a> con el motivo
            «Producto faltante»: ese sí corrige inventario y factura.</span>
    </p>
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <?php template('formDevolucionVenta', [
        'ventaId'     => $id,
        'devolvibles' => $devolvibles,
        'detalles'    => $detalles,
        'token'       => tokenFormulario(),
        'volver'      => $alSeguimiento ? 'seguimiento' : '',
        'fecha'       => $fecha,
        'conAcciones' => true,
        'hrefCancelar' => $volver,
    ]); ?>
</section>

<script>
/* Evita el doble clic: tras el primer envío el botón se desactiva. */
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () { const b = f.querySelector('[data-un-envio]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
});
</script>

<?php template('footer'); ?>

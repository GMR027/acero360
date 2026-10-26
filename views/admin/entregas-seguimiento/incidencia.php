<?php
/**
 * views/admin/entregas-seguimiento/incidencia.php — Registrar una incidencia de entrega.
 *
 * Regla de negocio (IncidenciasEntrega):
 *   · faltante / incompleto → las piezas afectadas REGRESAN AL INVENTARIO
 *     (entrada por devolución ligada a la venta) y, si la venta ya está
 *     facturada, la corrección de factura se fuerza (hay que cancelar y subir
 *     la correcta sólo por lo entregado);
 *   · dañado / cambio / devolución → sólo NOTIFICACIÓN: no mueven inventario.
 * En todos los casos queda anotado en venta_devoluciones y el pendiente de
 * entrega se ajusta. La venta y su importe NO cambian aquí.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}
if ($venta->estado !== 'registrada') {
    redirigir('/admin/entregas-seguimiento/venta?id=' . $id, 'error', 'Solo se registran incidencias de una venta registrada.');
}

$rutaVenta = '/admin/entregas-seguimiento/venta?id=' . $id;
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consumirToken($_POST['token'] ?? null)) {
        redirigir($rutaVenta, 'error', 'Este formulario ya se envió; la incidencia no se registró dos veces.');
    }

    $renglones = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $productoId => $cantidad) {
        $renglones[(int) $productoId] = [
            'cantidad' => (int) $cantidad,
            'cambio'   => (int) ($_POST['cambio'][$productoId] ?? 0),
        ];
    }

    $motivo = (string) ($_POST['motivo'] ?? '');
    if (\App\IncidenciasEntrega::registrar(
        $id,
        $motivo,
        $renglones,
        (string) ($_POST['notas'] ?? ''),
        !empty($_POST['factura_requerida']),
        (int) ($_SESSION['id'] ?? 0),
        isset($_POST['entrega_id']) && (int) $_POST['entrega_id'] > 0 ? (int) $_POST['entrega_id'] : null
    )) {
        $piezas = count(array_filter($renglones, static fn ($r) => $r['cantidad'] > 0));
        $conInventario = in_array($motivo, \App\IncidenciasEntrega::MOTIVOS_INVENTARIO, true);
        redirigir($rutaVenta . '#incidencias', 'exito',
            'Incidencia registrada: ' . $piezas . ' producto(s) afectado(s) '
            . ($conInventario
                ? 'regresaron al inventario'
                : 'quedaron notificados (sin movimiento de inventario)')
            . ' y el seguimiento de la venta quedó actualizado.');
    }
    $errores = \App\IncidenciasEntrega::getErrores();
}

$pendientes = \App\Entregas::pendientesDeVentas([$id])[$id] ?? [];
$disponibles = \App\Inventario::devolvibles($id);
$ingreso = \App\Contabilidad::ingresoDeVenta($id);
$facturada = $ingreso && $ingreso['estado'] === 'vigente' && $ingreso['estado_facturacion'] === 'facturada';
$hoy = date('Y-m-d');

template('header', ['titulo' => 'Incidencia de entrega · ' . $venta->folio, 'subtitulo' => 'Seguimiento de entregas', 'rutaActiva' => '/admin/entregas-seguimiento']);
template('tabsEntregas', ['tab' => 'seguimiento']);
?>

<section class="tarjeta">
    <p class="nota nota--info mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span><strong>Faltante o incompleto:</strong> las piezas <strong>regresan al inventario</strong> y, si la venta ya
            está facturada, hay que cancelar la factura y subir la correcta sólo por lo entregado.
            <strong>Dañado, cambio o devolución:</strong> sólo quedan notificados (no se mueve inventario).
            En todos los casos el pendiente de entrega se ajusta y la venta conserva su importe.</span>
    </p>

    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <p class="campo__ayuda mb-md">Registra: <?= escaparValores($_SESSION['nombre'] ?? 'tu usuario') ?> · venta <?= escaparValores($venta->folio) ?> · <?= $hoy ?>.</p>

    <?php template('formIncidenciaVenta', [
        'id'          => $id,
        'pendientes'  => $pendientes,
        'disponibles' => $disponibles,
        'facturada'   => $facturada,
        'ingreso'     => $ingreso,
        'token'       => tokenFormulario(),
        'conAcciones' => true,
        'hrefCancelar' => $rutaVenta,
    ]); ?>
</section>

<script>
/* Evita el doble clic: tras el primer envío el botón se desactiva.
 * (El comportamiento del motivo —marcar «requiere cambio» y forzar la corrección
 * de factura— vive en src/js/modulos/operacionComercial.js, igual que en el modal del hub.) */
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () { const b = f.querySelector('[data-un-envio]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
});
</script>

<?php template('footer'); ?>

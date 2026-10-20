<?php
/**
 * views/admin/ventas/devolucion.php — Devolución de piezas de una venta.
 *
 * Las piezas regresan al inventario (movimiento «devolucion» ligado a la
 * venta). No se pueden devolver más piezas de las vendidas. La venta y su
 * importe NO cambian aquí: el ajuste económico se maneja en contabilidad.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$venta = \App\Ventas::buscarID($id);

if (!$venta) {
    paginaNoEncontrada();
}
if ($venta->estado !== 'registrada') {
    redirigir('/admin/ventas/detalle?id=' . $id, 'error', 'Solo se pueden devolver piezas de una venta registrada.');
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
        redirigir('/admin/ventas/detalle?id=' . $id, 'error', 'Este formulario ya se envió; la devolución no se registró dos veces.');
    }

    if (\App\Inventario::registrarDevolucion($id, $items, $fecha, (string) ($_POST['nota'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        $debe = array_sum(array_column(\App\Inventario::cambiosPendientes($id), 'pendiente'));
        redirigir('/admin/ventas/detalle?id=' . $id, 'exito', 'Devolución registrada: las piezas regresaron al inventario.'
            . ($debe > 0 ? " Quedan $debe pieza(s) de cambio por entregar al cliente." : ''));
    }
    $errores = \App\Inventario::getErrores();
}

$devolvibles = \App\Inventario::devolvibles($id);
$detalles    = \App\Ventas::detalles($id);

$vistos = [];
template('header', ['titulo' => 'Devolución · ' . $venta->folio, 'subtitulo' => 'Devoluciones y cambios', 'rutaActiva' => '/admin/ventas']);
?>

<section class="tarjeta">
    <p class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span>Las piezas devueltas <strong>suman al inventario</strong> al registrar la devolución. Si alguna debe cambiarse, queda anotada
            como «se deben» en la venta y se descuenta del inventario cuando marques que el cambio ya se entregó.
            Si devuelves piezas que aún no se habían entregado, dejan de estar pendientes de entrega; el resto se sigue entregando.</span>
    </p>
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/ventas/devolucion" method="post" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Vendido</th>
                    <th scope="col" class="tabla--numerica">Ya devuelto</th>
                    <th scope="col" class="tabla--numerica">Cantidad devuelta</th>
                    <th scope="col" class="tabla--numerica">De esas, requieren cambio</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($detalles as $d) :
                        $pid = (int) $d['producto_id'];
                        if (isset($vistos[$pid])) { continue; }
                        $vistos[$pid] = true;
                        $inf = $devolvibles[$pid] ?? ['vendido' => 0, 'devuelto' => 0, 'disponible' => 0]; ?>
                        <tr>
                            <td><strong><?= escaparValores($d['producto_nombre']) ?></strong></td>
                            <td class="tabla--numerica cifra"><?= $inf['vendido'] ?></td>
                            <td class="tabla--numerica cifra"><?= $inf['devuelto'] ?></td>
                            <td><input class="control" type="number" min="0" max="<?= $inf['disponible'] ?>" step="1" value="0"
                                       name="cantidad[<?= $pid ?>]" <?= $inf['disponible'] === 0 ? 'disabled' : '' ?>
                                       aria-label="Piezas devueltas de <?= escaparValores($d['producto_nombre']) ?>"></td>
                            <td><input class="control" type="number" min="0" max="<?= $inf['disponible'] ?>" step="1" value="0"
                                       name="cambio[<?= $pid ?>]" <?= $inf['disponible'] === 0 ? 'disabled' : '' ?>
                                       aria-label="Piezas que requieren cambio de <?= escaparValores($d['producto_nombre']) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="formulario__rejilla mt-lg">
            <div class="campo"><label class="campo__etiqueta" for="fecha">Fecha del registro</label>
                <input class="control" type="date" id="fecha" name="fecha" max="<?= $hoy ?>" value="<?= escaparValores($fecha) ?>" required></div>
            <div class="campo"><label class="campo__etiqueta" for="nota">Nota (opcional)</label>
                <input class="control" type="text" id="nota" name="nota" maxlength="200" value="<?= escaparValores((string) ($_POST['nota'] ?? '')) ?>"></div>
        </div>
        <p class="campo__ayuda">Registra: <?= escaparValores($_SESSION['nombre'] ?? 'tu usuario') ?> · venta <?= escaparValores($venta->folio) ?><?= $venta->cotizacion_id ? ' (vino de una cotización)' : '' ?>.</p>
        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/ventas/detalle?id=<?= $id ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-un-envio>Registrar devolución</button>
        </div>
    </form>
</section>

<script>
/* Evita el doble clic: tras el primer envío el botón se desactiva. */
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () { const b = f.querySelector('[data-un-envio]'); if (b) { setTimeout(function () { b.disabled = true; }, 0); } });
});
</script>

<?php template('footer'); ?>

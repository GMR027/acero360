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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $piezas = [];
    foreach ((array) ($_POST['cantidad'] ?? []) as $productoId => $cantidad) {
        $piezas[(int) $productoId] = (int) $cantidad;
    }

    if (\App\Inventario::devolverDeVenta($id, $piezas, (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/ventas/detalle?id=' . $id, 'exito', 'Devolución registrada: las piezas regresaron al inventario.');
    }
    $errores = \App\Inventario::getErrores();
}

$devolvibles = \App\Inventario::devolvibles($id);
$detalles    = \App\Ventas::detalles($id);

$vistos = [];
template('header', ['titulo' => 'Devolución · ' . $venta->folio, 'subtitulo' => 'Piezas que regresan al inventario', 'rutaActiva' => '/admin/ventas']);
?>

<section class="tarjeta">
    <?php if ($errores) : ?>
        <div class="nota nota--error mb-lg" role="alert">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
            <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
        </div>
    <?php endif; ?>

    <form class="formulario" action="/admin/ventas/devolucion" method="post" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="tabla-contenedor">
            <table class="tabla">
                <thead><tr>
                    <th scope="col">Producto</th>
                    <th scope="col" class="tabla--numerica">Vendido</th>
                    <th scope="col" class="tabla--numerica">Ya devuelto</th>
                    <th scope="col" class="tabla--numerica">Devolver ahora</th>
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
                                       aria-label="Piezas a devolver de <?= escaparValores($d['producto_nombre']) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="campo campo--completo mt-lg">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="motivo">Motivo de la devolución</label>
            <input class="control" type="text" id="motivo" name="motivo" maxlength="200" required minlength="5">
        </div>
        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/ventas/detalle?id=<?= $id ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit">Registrar devolución</button>
        </div>
    </form>
</section>

<?php template('footer'); ?>

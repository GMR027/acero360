<?php
/**
 * templates/formDevolucionVenta.php — Formulario de devolución de piezas de UNA venta.
 *
 * Compartido por la página suelta /admin/ventas/devolucion y por el modal del
 * hub «Operación Comercial». La devolución es una NOTIFICACIÓN: queda anotada
 * en la venta y ajusta el pendiente, pero NO mueve inventario.
 *
 * NO trae botones por defecto (los pone cada contexto); con $conAcciones y
 * $hrefCancelar dibuja el pie de la página suelta.
 *
 * Variables: $ventaId (int), $devolvibles (Inventario::devolvibles),
 *            $detalles (Ventas::detalles), $token (string),
 *            $volver (string: 'seguimiento' o ''), $fecha (string Y-m-d),
 *            $conAcciones (bool, opcional), $hrefCancelar (string, opcional).
 */
$devolvibles = $devolvibles ?? [];
$detalles    = $detalles ?? [];
$volver      = $volver ?? '';
$fecha       = $fecha ?? date('Y-m-d');
$conAcciones = (bool) ($conAcciones ?? false);
$hrefCancelar = (string) ($hrefCancelar ?? '');
$hoy         = date('Y-m-d');
$etiqueta    = 'dev-' . (int) $ventaId;
$vistos      = [];
?>
<form class="formulario" action="/admin/ventas/devolucion" method="post" novalidate>
    <input type="hidden" name="id" value="<?= (int) $ventaId ?>">
    <?php if ($volver !== '') : ?>
        <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
    <?php endif; ?>
    <input type="hidden" name="token" value="<?= escaparValores($token) ?>">

    <p class="campo__ayuda mb-md">Sólo queda anotado en la venta (no modifica el inventario). Si lo que faltó en la
        entrega, usa el motivo «Producto faltante» del seguimiento: ese sí corrige inventario y factura.</p>

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
                        <td class="tabla--numerica cifra"><?= (int) $inf['vendido'] ?></td>
                        <td class="tabla--numerica cifra"><?= (int) $inf['devuelto'] ?></td>
                        <td><input class="control" type="number" min="0" max="<?= (int) $inf['disponible'] ?>" step="1" value="0"
                                   name="cantidad[<?= $pid ?>]" <?= (int) $inf['disponible'] === 0 ? 'disabled' : '' ?>
                                   aria-label="Piezas devueltas de <?= escaparValores($d['producto_nombre']) ?>"></td>
                        <td><input class="control" type="number" min="0" max="<?= (int) $inf['disponible'] ?>" step="1" value="0"
                                   name="cambio[<?= $pid ?>]" <?= (int) $inf['disponible'] === 0 ? 'disabled' : '' ?>
                                   aria-label="Piezas que requieren cambio de <?= escaparValores($d['producto_nombre']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="formulario__rejilla mt-lg">
        <div class="campo"><label class="campo__etiqueta" for="fecha-<?= $etiqueta ?>">Fecha del registro</label>
            <input class="control" type="date" id="fecha-<?= $etiqueta ?>" name="fecha" max="<?= $hoy ?>" value="<?= escaparValores($fecha) ?>" required></div>
        <div class="campo"><label class="campo__etiqueta" for="nota-<?= $etiqueta ?>">Nota (opcional)</label>
            <input class="control" type="text" id="nota-<?= $etiqueta ?>" name="nota" maxlength="200" value=""></div>
    </div>

    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones mt-lg">
            <a class="btn btn--secundario" href="<?= escaparValores($hrefCancelar) ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-un-envio>Registrar devolución</button>
        </div>
    <?php endif; ?>
</form>

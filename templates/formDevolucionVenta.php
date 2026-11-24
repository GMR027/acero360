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

    <div class="linea-form" role="group" aria-label="Productos devolvibles">
        <?php foreach ($detalles as $d) :
            $pid = (int) $d['producto_id'];
            if (isset($vistos[$pid])) { continue; }
            $vistos[$pid] = true;
            $inf = $devolvibles[$pid] ?? ['vendido' => 0, 'devuelto' => 0, 'disponible' => 0]; ?>
            <div class="linea-form__item">
                <div class="linea-form__producto">
                    <strong><?= escaparValores($d['producto_nombre']) ?></strong>
                    <small><?= escaparValores($d['producto_sku']) ?> · <?= escaparValores($d['producto_unidad']) ?></small>
                </div>
                <div class="linea-form__datos">
                    <span class="linea-form__dato"><small>Vendido</small><strong><?= (int) $inf['vendido'] ?></strong></span>
                    <span class="linea-form__dato"><small>Ya devuelto</small><strong><?= (int) $inf['devuelto'] ?></strong></span>
                    <span class="linea-form__dato"><small>Disponible</small><strong><?= (int) $inf['disponible'] ?></strong></span>
                </div>
                <div class="linea-form__campos">
                    <label class="linea-form__campo">
                        <span>Cantidad devuelta</span>
                        <input class="control" type="number" min="0" max="<?= (int) $inf['disponible'] ?>" step="1" value="0"
                               name="cantidad[<?= $pid ?>]" <?= (int) $inf['disponible'] === 0 ? 'disabled' : '' ?>
                               aria-label="Piezas devueltas de <?= escaparValores($d['producto_nombre']) ?>">
                    </label>
                    <label class="linea-form__campo">
                        <span>De esas, requieren cambio</span>
                        <input class="control" type="number" min="0" max="<?= (int) $inf['disponible'] ?>" step="1" value="0"
                               name="cambio[<?= $pid ?>]" <?= (int) $inf['disponible'] === 0 ? 'disabled' : '' ?>
                               aria-label="Piezas que requieren cambio de <?= escaparValores($d['producto_nombre']) ?>">
                    </label>
                </div>
            </div>
        <?php endforeach; ?>
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

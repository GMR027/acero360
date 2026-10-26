<?php
/**
 * templates/devolucionesVenta.php — devoluciones y cambios de una venta: lo que se debe al cliente y el historial.
 * Variables: $ventaId, $registrada (bool: la venta sigue registrada y admite entregas de cambio),
 *            $botonDevolucion (bool, opcional: muestra el botón de alta — el hub de
 *            Operación Comercial lo abre en modal; sin él, sólo se muestra si hay historial).
 *
 * La devolución y el cambio son NOTIFICACIÓN: no mueven inventario.
 */
$cambios = \App\Inventario::cambiosPendientes($ventaId);
$historial = \App\Inventario::historialDevoluciones($ventaId);
$botonDevolucion = (bool) ($botonDevolucion ?? false);
if (!$historial && !$botonDevolucion) {
    return;
}
$debe = array_filter($cambios, static fn ($c) => $c['pendiente'] > 0);
?>
<section class="tarjeta mb-lg" id="cambios">
    <div class="tabla-toolbar">
        <h2 class="titulo-5">Devoluciones y cambios</h2>
        <?php if ($botonDevolucion && $registrada) : ?>
            <!-- Sin JS el enlace navega a la página suelta; con JS abre el modal del hub. -->
            <a class="btn btn--secundario btn--sm" href="/admin/ventas/devolucion?id=<?= (int) $ventaId ?>"
               data-modal-form="tpl-devolucion"
               data-modal-sobre-titulo="Devolución de la venta"
               data-modal-titulo="Registrar devolución"
               data-modal-confirmar="Registrar devolución">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">assignment_return</span>
                <span>Registrar devolución</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($debe) : ?>
        <p class="nota nota--advertencia mt-md" role="status">
            <span class="material-symbols-outlined nota__icono" aria-hidden="true">swap_horiz</span>
            <span><strong>Se le deben al cliente:</strong>
                <?php foreach ($debe as $c) : ?><?= (int) $c['pendiente'] ?> × <?= escaparValores($c['nombre']) ?>; <?php endforeach; ?>
                Al marcarlas como entregadas queda saldado el cambio (sin mover inventario).</span>
        </p>
        <?php if ($registrada) : foreach ($debe as $c) : ?>
            <form class="formulario fila--envuelto" action="/admin/ventas/cambio" method="post" style="display:flex;gap:.5rem;align-items:end;margin:.5rem 0" data-validar>
                <input type="hidden" name="id" value="<?= (int) $ventaId ?>"><input type="hidden" name="producto_id" value="<?= (int) $c['producto_id'] ?>">
                <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
                <span><strong><?= escaparValores($c['nombre']) ?></strong> (faltan <?= (int) $c['pendiente'] ?>)</span>
                <label class="solo-lectores" for="cant-<?= (int) $c['producto_id'] ?>">Piezas entregadas de <?= escaparValores($c['nombre']) ?></label>
                <input class="control control--compacto" type="number" id="cant-<?= (int) $c['producto_id'] ?>" name="cantidad" min="1" max="<?= (int) $c['pendiente'] ?>" value="<?= (int) $c['pendiente'] ?>" required style="width:6rem">
                <label class="solo-lectores" for="fec-<?= (int) $c['producto_id'] ?>">Fecha de entrega</label>
                <input class="control control--compacto" type="date" id="fec-<?= (int) $c['producto_id'] ?>" name="fecha" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                <button class="btn btn--primario btn--sm" type="submit">Marcar cambio entregado</button>
            </form>
        <?php endforeach; endif; ?>
    <?php elseif ($historial) : ?>
        <p class="texto-sm texto-secundario mt-md">Sin cambios pendientes por entregar al cliente.</p>
    <?php endif; ?>

    <?php if ($historial) : ?>
    <div class="tabla-contenedor mt-md">
        <table class="tabla">
            <thead><tr>
                <th scope="col">Fecha</th><th scope="col">Producto</th>
                <th scope="col" class="tabla--numerica">Devueltas</th><th scope="col" class="tabla--numerica">A cambiar</th>
                <th scope="col" class="tabla--numerica">Cambio entregado</th><th scope="col">Registró</th><th scope="col">Nota</th>
            </tr></thead>
            <tbody>
            <?php foreach ($historial as $h) : ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                    <td><?= escaparValores($h['producto_nombre']) ?></td>
                    <td class="tabla--numerica cifra"><?= (int) $h['cantidad_devuelta'] ?></td>
                    <td class="tabla--numerica cifra"><?= (int) $h['cantidad_cambio'] ?></td>
                    <td class="tabla--numerica cifra"><?= (int) $h['cantidad_cambio'] === 0 ? '—' : (int) $h['entregadas'] ?></td>
                    <td><?= escaparValores((string) ($h['usuario_nombre'] ?? '—')) ?></td>
                    <td><?= escaparValores((string) ($h['nota'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

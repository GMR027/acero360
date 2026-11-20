<?php
/**
 * templates/venta/eliminar.php — Baja de la venta, al final del expediente.
 *
 * Variables: $cotizacion, $id.
 */
?>
<section class="tarjeta" id="eliminar-venta">
    <h2 class="titulo-3 mb-md">
        <span class="material-symbols-outlined" aria-hidden="true">delete_forever</span>
        Eliminar venta
    </h2>
    <p class="texto-secundario mb-md">
        Se retira la venta y el inventario recupera las piezas descontadas.
        Para conservarla en el historial sin que cuente, cámbiala a «Cancelada» al editarla.
    </p>

    <form action="/admin/ventas/eliminar" method="post" data-validar
          data-confirmacion-sobre="<?= escaparValores($cotizacion->folio) ?>"
          data-confirmacion="¿Eliminar esta venta?"
          data-confirmacion-mensaje="Se borrará la venta <?= escaparValores($cotizacion->folio) ?> y todos sus renglones.<?php if ($cotizacion->estado === 'registrada') : ?> El inventario recuperará las piezas descontadas.<?php endif; ?> Irá a Ajustes → Papelera y ahí podrás restaurarla durante 90 días."
          data-confirmacion-boton="Sí, eliminar">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <button type="submit" class="btn btn--peligro">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">delete</span>
            <span>Eliminar venta</span>
        </button>
    </form>
</section>

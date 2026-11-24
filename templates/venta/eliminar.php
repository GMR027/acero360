<?php
/**
 * templates/venta/eliminar.php — Baja de la venta, plegada al final del resumen.
 *
 * Variables: $cotizacion, $id.
 */
?>
<details class="expediente-peligro" id="eliminar-venta">
    <summary>
        <span class="material-symbols-outlined" aria-hidden="true">delete_forever</span>
        <span>Eliminar venta</span>
        <small>La venta se puede restaurar desde Ajustes → Papelera durante 90 días.</small>
    </summary>
    <div class="expediente-peligro__cuerpo">
        <p class="texto-secundario">
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
    </div>
</details>

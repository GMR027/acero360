<?php
/**
 * templates/venta/facturacion.php — Facturación y cobro dentro del expediente.
 *
 * La captura completa sigue viviendo en templates/facturacionVenta.php (la
 * misma que usaba el detalle de la venta). Si la venta aún no tiene ingreso
 * contable se explica, en vez de dejar la sección vacía.
 *
 * Variables: $ingreso, $rutaVenta.
 */
?>
<?php if ($ingreso) : ?>
    <?php template('facturacionVenta', ['ingreso' => $ingreso, 'volver' => $rutaVenta]); ?>
<?php else : ?>
    <section class="tarjeta mb-lg" id="facturacion" style="scroll-margin-top:5rem">
        <h2 class="titulo-4">Facturación y cobro</h2>
        <p class="texto-secundario">
            Esta venta no tiene un ingreso contable asociado, así que todavía no hay factura ni cobros que mostrar.
        </p>
    </section>
<?php endif; ?>

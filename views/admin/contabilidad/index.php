<?php
/**
 * views/admin/contabilidad/index.php — Resumen mensual consolidado de ambos negocios.
 * Importes REGISTRADOS (no son cálculos fiscales). Solo lectura.
 */

soloAdmin();

$periodo  = periodoSeleccionado();
$empresas = \App\Empresas::mostrarTodos(100, 0);
$r        = \App\Contabilidad::resumenPeriodo($periodo['anio'], $periodo['mes'], $periodo['empresa']);
$g        = $r['gastos'];
$gasto    = static fn (int $centavos): float => $centavos / 100;
$contrib  = \App\Contabilidad::contribuyente(\App\Contabilidad::contribuyentePrincipal());
$cerrado  = $contrib && \App\Contabilidad::periodoCerrado((int) $contrib['id'], sprintf('%04d-%02d-01', $periodo['anio'], $periodo['mes']));

template('header', ['titulo' => 'Contabilidad', 'subtitulo' => nombreMes($periodo['mes']) . ' ' . $periodo['anio'], 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'resumen', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad', 'periodo' => $periodo, 'empresas' => $empresas]);
?>

<?php
$pend = \App\Contabilidad::pendientes($periodo['anio'], $periodo['mes'], (int) $periodo['empresa']);
$entra = $r['ventas']['total'];
$sale  = $gasto($g['total']);
$queda = $entra - $sale;
?>
<?php if ($cerrado) : ?>
    <p class="nota nota--advertencia mb-lg" role="note"><span class="material-symbols-outlined nota__icono" aria-hidden="true">lock</span>
        <span><strong>Este mes ya está cerrado.</strong> No se pueden cambiar sus ventas ni gastos.</span></p>
<?php endif; ?>

<section class="rejilla-stats mb-lg" aria-label="Resumen del mes">
    <div class="stat"><span class="stat__etiqueta">Vendiste</span>
        <span class="stat__valor"><?= formatoMoneda($entra) ?></span>
        <span class="stat__pie"><?= number_format($r['ventas']['operaciones']) ?> ventas · ya cobraste <?= formatoMoneda($r['cobrado_en_periodo']) ?></span></div>
    <div class="stat"><span class="stat__etiqueta">Gastaste</span>
        <span class="stat__valor"><?= formatoMoneda($sale) ?></span>
        <span class="stat__pie"><?= number_format($g['operaciones']) ?> gastos · ya pagaste <?= formatoMoneda($gasto($g['pagado'])) ?></span></div>
    <div class="stat"><span class="stat__etiqueta">Te queda (antes de impuestos)</span>
        <span class="stat__valor"><?= formatoMoneda($queda) ?></span>
        <span class="stat__pie">Ventas menos gastos del mes</span></div>
</section>

<?php
$avisos = [];
if ($pend['por_cobrar']['n'] > 0) {
    $avisos[] = ['payments', $pend['por_cobrar']['n'] . ' venta(s) sin cobrar por completo: te deben ' . formatoMoneda($pend['por_cobrar']['monto']), '/admin/contabilidad/ingresos?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'Ver ventas'];
}
if ($pend['sin_indicar'] > 0) {
    $avisos[] = ['receipt_long', $pend['sin_indicar'] . ' venta(s) sin indicar si se facturaron', '/admin/contabilidad/sin-facturar?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'Revisar'];
}
if ($pend['gastos_por_pagar']['n'] > 0) {
    $avisos[] = ['request_quote', $pend['gastos_por_pagar']['n'] . ' gasto(s) por pagar: ' . formatoMoneda($pend['gastos_por_pagar']['monto']), '/admin/contabilidad/gastos?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'Ver gastos'];
}
if ($pend['gastos_sin_factura'] > 0) {
    $avisos[] = ['attach_file', $pend['gastos_sin_factura'] . ' gasto(s) sin factura (no se podrán restar de tus impuestos)', '/admin/contabilidad/gastos?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'Ver gastos'];
}
?>
<section class="tarjeta mb-lg">
    <h2 class="titulo-5 mb-md">Lo que te falta hacer</h2>
    <?php if (!$avisos) : ?>
        <p class="texto-secundario"><span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:middle;color:var(--color-exito,#2e7d32)">check_circle</span> Todo al día este mes.</p>
    <?php else : ?>
        <ul class="lista-simple">
            <?php foreach ($avisos as [$icono, $texto, $enlace, $boton]) : ?>
                <li class="lista-simple__elemento" style="display:flex;gap:.75rem;align-items:center;justify-content:space-between;flex-wrap:wrap">
                    <span><span class="material-symbols-outlined" aria-hidden="true" style="vertical-align:middle"><?= $icono ?></span> <?= escaparValores($texto) ?></span>
                    <a class="btn btn--secundario btn--sm" href="<?= escaparValores($enlace) ?>"><?= $boton ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="encabezado-pagina__acciones mt-lg">
        <a class="btn btn--primario" href="/admin/contabilidad/gastos/crear"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span><span>Anotar un gasto</span></a>
        <a class="btn btn--secundario" href="/admin/ventas/crear"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">point_of_sale</span><span>Anotar una venta</span></a>
        <a class="btn btn--secundario" href="/admin/fiscal"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">request_quote</span><span>¿Cuánto separo para impuestos?</span></a>
    </div>
</section>

<details class="mb-lg">
    <summary class="boton-enlace" style="cursor:pointer"><strong>Ver el detalle completo</strong> (IVA, subtotales y desglose por negocio)</summary>
    <div class="mt-md">
    <p class="nota nota--advertencia mb-lg" role="note">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">info</span>
        <span>Importes <strong>registrados</strong> de <?= $periodo['empresa'] ? 'un negocio' : 'tus negocios (RFC ' . ($contrib ? escaparValores($contrib['rfc']) : '—') . ')' ?>. No son cálculos de impuestos.</span>
    </p>
    <section class="rejilla-stats mb-lg" aria-label="Detalle de ventas">
        <div class="stat"><span class="stat__etiqueta">Ventas sin IVA</span><span class="stat__valor"><?= formatoMoneda($r['ventas']['subtotal']) ?></span></div>
        <div class="stat"><span class="stat__etiqueta">IVA cobrado en ventas</span><span class="stat__valor"><?= formatoMoneda($r['ventas']['iva']) ?></span></div>
        <div class="stat"><span class="stat__etiqueta">Gastos sin IVA</span><span class="stat__valor"><?= formatoMoneda($gasto($g['subtotal'])) ?></span></div>
        <div class="stat"><span class="stat__etiqueta">IVA pagado en gastos</span><span class="stat__valor"><?= formatoMoneda($gasto($g['iva'])) ?></span></div>
    </section>
    <section class="rejilla-stats mb-lg" aria-label="Facturación">
        <?php foreach (['facturada' => 'Ventas facturadas', 'no_facturada' => 'Ventas sin factura', 'sin_clasificar' => 'Sin indicar'] as $clave => $etiqueta) : ?>
            <div class="stat"><span class="stat__etiqueta"><?= $etiqueta ?></span>
                <span class="stat__valor"><?= formatoMoneda($r['facturacion'][$clave]['total']) ?></span>
                <span class="stat__pie"><?= number_format($r['facturacion'][$clave]['operaciones']) ?> ventas</span></div>
        <?php endforeach; ?>
    </section>
    </div>
<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Detalle por negocio</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Ventas y gastos por negocio</caption>
            <thead><tr><th scope="col">Negocio</th><th scope="col" class="tabla--numerica">Ventas (subtotal)</th>
                <th scope="col" class="tabla--numerica">IVA ventas</th><th scope="col" class="tabla--numerica">Gastos (subtotal)</th>
                <th scope="col" class="tabla--numerica">IVA gastos</th><th scope="col" class="tabla--numerica">Ventas menos gastos</th></tr></thead>
            <tbody>
                <?php foreach ($r['por_negocio'] as $n) : ?>
                    <tr><td><?= escaparValores($n['nombre']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($n['ventas']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($n['iva_ventas']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($n['gastos']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($n['iva_gastos']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($n['ventas'] - $n['gastos']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$r['por_negocio']) : ?><tr><td colspan="6" class="tabla--centro texto-secundario">Sin movimientos en el periodo.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><td><strong>Consolidado</strong></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($r['ventas']['subtotal']) ?></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($r['ventas']['iva']) ?></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($gasto($g['subtotal'])) ?></td>
                <td class="tabla--numerica cifra"><?= formatoMoneda($gasto($g['iva'])) ?></td>
                <td class="tabla--numerica cifra"><strong><?= formatoMoneda($r['ventas']['subtotal'] - $gasto($g['subtotal'])) ?></strong></td></tr></tfoot>
        </table>
    </div>
    <p class="campo__ayuda" style="padding:0 1rem 1rem">«Ventas menos gastos» = ventas − gastos del mes. No es utilidad fiscal ni base de ISR.</p>
</section>

</details>

<section class="tarjeta mt-lg">
    <h2 class="titulo-5 mb-md">Facturas del mes para tu contador</h2>
    <p class="campo__ayuda mb-md">Descarga en ZIP todos los archivos que subiste este mes.</p>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/contabilidad/zip?tipo=ventas&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">folder_zip</span><span>Documentos de ventas (ZIP)</span></a>
        <a class="btn btn--secundario" href="/admin/contabilidad/zip?tipo=gastos&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">folder_zip</span><span>Documentos de gastos (ZIP)</span></a>
    </div>
</section>

<?php template('footer'); ?>

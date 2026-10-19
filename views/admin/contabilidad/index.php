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

<p class="nota nota--advertencia mb-lg" role="note">
    <span class="material-symbols-outlined nota__icono" aria-hidden="true"><?= $cerrado ? 'lock' : 'info' ?></span>
    <span>
        <?php if ($cerrado) : ?><strong>Periodo cerrado.</strong> <?php endif; ?>
        Importes <strong>registrados</strong> de <?= $periodo['empresa'] ? 'un negocio' : 'ambos negocios (un solo contribuyente' . ($contrib ? ', RFC ' . escaparValores($contrib['rfc']) : '') . ')' ?>.
        No son estimaciones fiscales: el IVA a cargo y el ISR se calcularán en el módulo fiscal y deben validarse con el contador.
    </span>
</p>

<h2 class="titulo-5 mb-md">Ventas</h2>
<section class="rejilla-stats mb-lg" aria-label="Ventas del periodo">
    <div class="stat"><span class="stat__etiqueta">Ventas del periodo</span>
        <span class="stat__valor"><?= number_format($r['ventas']['operaciones']) ?></span><span class="stat__pie">operaciones</span></div>
    <div class="stat"><span class="stat__etiqueta">Subtotal de operaciones</span>
        <span class="stat__valor"><?= formatoMoneda($r['ventas']['subtotal']) ?></span><span class="stat__pie">antes de IVA, con descuentos</span></div>
    <div class="stat"><span class="stat__etiqueta">IVA trasladado registrado</span>
        <span class="stat__valor"><?= formatoMoneda($r['ventas']['iva']) ?></span><span class="stat__pie">Total <?= formatoMoneda($r['ventas']['total']) ?></span></div>
    <div class="stat"><span class="stat__etiqueta">Cobrado en el periodo</span>
        <span class="stat__valor"><?= formatoMoneda($r['cobrado_en_periodo']) ?></span><span class="stat__pie">Por cobrar de estas ventas: <?= formatoMoneda($r['por_cobrar']) ?></span></div>
</section>

<section class="rejilla-stats mb-lg" aria-label="Facturación">
    <?php foreach (['facturada' => 'Facturadas', 'no_facturada' => 'No facturadas', 'sin_clasificar' => 'Sin clasificar'] as $clave => $etiqueta) : ?>
        <div class="stat"><span class="stat__etiqueta"><?= $etiqueta ?></span>
            <span class="stat__valor"><?= formatoMoneda($r['facturacion'][$clave]['total']) ?></span>
            <span class="stat__pie"><?= number_format($r['facturacion'][$clave]['operaciones']) ?> operaciones</span></div>
    <?php endforeach; ?>
</section>

<h2 class="titulo-5 mb-md">Gastos y compras</h2>
<section class="rejilla-stats mb-lg" aria-label="Gastos del periodo">
    <div class="stat"><span class="stat__etiqueta">Gastos registrados</span>
        <span class="stat__valor"><?= number_format($g['operaciones']) ?></span><span class="stat__pie">cada gasto cuenta una vez</span></div>
    <div class="stat"><span class="stat__etiqueta">Subtotal de gastos</span>
        <span class="stat__valor"><?= formatoMoneda($gasto($g['subtotal'])) ?></span><span class="stat__pie">Total <?= formatoMoneda($gasto($g['total'])) ?></span></div>
    <div class="stat"><span class="stat__etiqueta">IVA de gastos registrado</span>
        <span class="stat__valor"><?= formatoMoneda($gasto($g['iva'])) ?></span><span class="stat__pie">potencialmente acreditable: por validar</span></div>
    <div class="stat"><span class="stat__etiqueta">Pagado / pendiente</span>
        <span class="stat__valor"><?= formatoMoneda($gasto($g['pagado'])) ?></span><span class="stat__pie">Pendiente de pago: <?= formatoMoneda($gasto($g['pendiente'])) ?></span></div>
</section>

<?php if ($g['sin_comprobante'] > 0 || $g['por_validar'] > 0) : ?>
    <p class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">fact_check</span>
        <span><?= (int) $g['sin_comprobante'] ?> gasto(s) sin comprobante fiscal y <?= (int) $g['por_validar'] ?> con deducibilidad por validar.
            Un adjunto no hace deducible un gasto: la revisión fiscal es manual.</span>
    </p>
<?php endif; ?>

<section class="tarjeta tarjeta--sin-relleno">
    <h2 class="titulo-5" style="padding:1rem 1rem 0">Detalle por negocio</h2>
    <div class="tabla-contenedor">
        <table class="tabla">
            <caption class="solo-lectores">Ventas y gastos por negocio</caption>
            <thead><tr><th scope="col">Negocio</th><th scope="col" class="tabla--numerica">Ventas (subtotal)</th>
                <th scope="col" class="tabla--numerica">IVA ventas</th><th scope="col" class="tabla--numerica">Gastos (subtotal)</th>
                <th scope="col" class="tabla--numerica">IVA gastos</th><th scope="col" class="tabla--numerica">Diferencia registrada</th></tr></thead>
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
    <p class="campo__ayuda" style="padding:0 1rem 1rem">«Diferencia registrada» = ventas − gastos del mes. No es utilidad fiscal ni base de ISR.</p>
</section>

<section class="tarjeta mt-lg">
    <h2 class="titulo-5 mb-md">Archivo fiscal del mes</h2>
    <p class="campo__ayuda mb-md">Descarga en ZIP los documentos del periodo, con carpetas e índice. Son dos archivos independientes.</p>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/contabilidad/zip?tipo=ventas&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">folder_zip</span><span>Documentos de ventas (ZIP)</span></a>
        <a class="btn btn--secundario" href="/admin/contabilidad/zip?tipo=gastos&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">folder_zip</span><span>Documentos de gastos (ZIP)</span></a>
    </div>
</section>

<?php template('footer'); ?>

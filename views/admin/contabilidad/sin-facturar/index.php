<?php
/**
 * views/admin/contabilidad/sin-facturar/index.php — Ventas que no están marcadas como facturadas.
 * Cada venta aparece una sola vez; se exporta a PDF, Excel y CSV.
 */

soloAdmin();

$periodo  = periodoSeleccionado(false);
$empresas = \App\Empresas::mostrarTodos(100, 0);
$sf       = \App\Contabilidad::sinFacturar($periodo['anio'], $periodo['mes'], $periodo['empresa']);
$t        = $sf['totales'];
$consulta = http_build_query(array_filter($periodo, static fn ($v) => $v !== 0));

template('header', ['titulo' => 'Ventas sin facturar', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'sin', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/sin-facturar', 'periodo' => $periodo, 'empresas' => $empresas, 'conTodoElAnio' => true]);
?>

<section class="rejilla-stats mb-lg" aria-label="Resumen de ventas sin facturar">
    <div class="stat"><span class="stat__etiqueta">Operaciones</span><span class="stat__valor"><?= number_format($t['operaciones']) ?></span>
        <span class="stat__pie"><?= (int) $t['no_facturada'] ?> no facturadas · <?= (int) $t['sin_clasificar'] ?> sin clasificar</span></div>
    <div class="stat"><span class="stat__etiqueta">Subtotal</span><span class="stat__valor"><?= formatoMoneda($t['subtotal']) ?></span><span class="stat__pie">antes de IVA</span></div>
    <div class="stat"><span class="stat__etiqueta">IVA registrado</span><span class="stat__valor"><?= formatoMoneda($t['iva']) ?></span><span class="stat__pie">no es IVA a cargo</span></div>
    <div class="stat"><span class="stat__etiqueta">Total</span><span class="stat__valor"><?= formatoMoneda($t['total']) ?></span><span class="stat__pie">IVA incluido</span></div>
</section>

<div class="encabezado-pagina__acciones mb-lg">
    <?php foreach (['pdf' => ['PDF', 'picture_as_pdf'], 'xlsx' => ['Excel', 'table_view'], 'csv' => ['CSV', 'csv']] as $formato => [$texto, $icono]) : ?>
        <a class="btn btn--secundario" href="/admin/contabilidad/sin-facturar/exportar?<?= $consulta ?>&formato=<?= $formato ?>"<?= $formato === 'pdf' ? ' target="_blank" rel="noopener"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= $icono ?></span><span>Exportar <?= $texto ?></span></a>
    <?php endforeach; ?>
</div>

<p class="campo__ayuda mb-md">Incluye las ventas <strong>sin clasificar</strong> y las marcadas <strong>no facturadas</strong>. La falta de un PDF adjunto no las clasifica: hazlo desde el detalle de cada ingreso.</p>

<section class="tarjeta tarjeta--sin-relleno">
    <?php if (!$sf['filas']) : ?>
        <div class="vacio"><span class="material-symbols-outlined vacio__icono" aria-hidden="true">task_alt</span>
            <p class="vacio__titulo">No hay ventas sin facturar en este periodo</p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Ventas sin facturar</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Venta</th><th scope="col">Cliente</th><th scope="col">Negocio</th>
                    <th scope="col" class="tabla--numerica">Subtotal</th><th scope="col" class="tabla--numerica">IVA</th>
                    <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--centro">Estado</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($sf['filas'] as $f) : $fact = \App\Contabilidad::FACTURACION[$f['estado_facturacion']]; ?>
                        <tr><td><?= date('d/m/Y', strtotime($f['fecha'])) ?></td><td><strong><?= escaparValores($f['folio']) ?></strong></td>
                            <td><?= escaparValores($f['cliente_empresa'] ?: $f['cliente_nombre']) ?></td><td><?= escaparValores($f['empresa_nombre']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda((float) $f['subtotal'] - (float) $f['descuento']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['iva']) ?></td>
                            <td class="tabla--numerica cifra"><strong><?= formatoMoneda($f['total']) ?></strong></td>
                            <td class="tabla--centro"><span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span></td>
                            <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $f['id'] ?>">Clasificar</a></td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4"><strong>Total</strong></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($t['subtotal']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($t['iva']) ?></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($t['total']) ?></strong></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

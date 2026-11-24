<?php
/**
 * views/admin/contabilidad/sin-facturar/index.php — Ventas que no están marcadas como facturadas.
 * Cada venta aparece una sola vez; se exporta a PDF, Excel y CSV.
 */

soloAdmin();

$periodo  = periodoSeleccionado(false);
$empresas = \App\Empresas::mostrarTodos(100, 0);
$vista    = in_array($_GET['vista'] ?? '', ['sin_clasificar', 'no_facturada'], true) ? $_GET['vista'] : '';
$cliente  = max(0, (int) ($_GET['cliente'] ?? 0));
$sf       = \App\Contabilidad::sinFacturar($periodo['anio'], $periodo['mes'], $periodo['empresa'], $vista, $cliente);
$t        = $sf['totales'];
$consulta = http_build_query(array_filter($periodo + ['cliente' => $cliente], static fn ($v) => $v !== 0));
$consultaVista = $consulta . ($vista !== '' ? '&vista=' . $vista : '');

$accionesExportar = '';
foreach (['pdf' => ['PDF', 'picture_as_pdf'], 'xlsx' => ['Excel', 'table_view'], 'csv' => ['CSV', 'csv']] as $formato => [$texto, $icono]) {
    $accionesExportar .= '<a class="btn btn--secundario btn--sm" href="/admin/contabilidad/sin-facturar/exportar?' . escaparValores($consultaVista) . '&formato=' . $formato . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">' . $icono . '</span>'
        . '<span>' . escaparValores($texto) . '</span></a>';
}

template('header', [
    'titulo'     => 'Pendientes de facturar',
    'subtitulo'  => 'Contabilidad',
    'rutaActiva' => '/admin/contabilidad/sin-facturar',
    'acciones'   => $accionesExportar,
]);
template('tabsContabilidad', ['tab' => 'sin', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/sin-facturar', 'periodo' => $periodo, 'empresas' => $empresas, 'conTodoElAnio' => true, 'extra' => ['vista' => $vista], 'clientes' => \App\Clientes::paraSelector(), 'cliente' => $cliente, 'ocultoMovil' => true]);

$kpis = [
    [
        'etiqueta' => 'Operaciones',
        'valor'    => number_format((int) $t['operaciones']),
        'pie'      => (int) $t['no_facturada'] . ' no facturadas · ' . (int) $t['sin_clasificar'] . ' pendientes de facturar',
        'icono'    => 'receipt_long',
    ],
    [
        'etiqueta' => 'Subtotal',
        'valor'    => formatoMoneda($t['subtotal']),
        'pie'      => 'antes de IVA',
        'icono'    => 'calculate',
    ],
    [
        'etiqueta' => 'IVA registrado',
        'valor'    => formatoMoneda($t['iva']),
        'pie'      => 'no es IVA a cargo',
        'icono'    => 'account_balance',
    ],
    [
        'etiqueta' => 'Total',
        'valor'    => formatoMoneda($t['total']),
        'pie'      => 'IVA incluido',
        'icono'    => 'payments',
        'destacado' => true,
    ],
];

$enlaceIngresos = '/admin/contabilidad/ingresos?' . $consulta;
?>

<section class="fin-kpis fin-kpis--4" aria-label="Resumen de ventas sin facturar">
    <?php foreach ($kpis as $k) : ?>
        <div class="fin-kpi<?= !empty($k['destacado']) ? ' fin-kpi--destacado' : '' ?>">
            <span class="fin-kpi__cabecera">
                <span class="fin-kpi__icono" aria-hidden="true">
                    <span class="material-symbols-outlined"><?= escaparValores($k['icono']) ?></span>
                </span>
                <span class="fin-kpi__etiqueta"><?= escaparValores($k['etiqueta']) ?></span>
            </span>
            <strong class="fin-kpi__valor cifra"><?= $k['valor'] ?></strong>
            <span class="fin-kpi__pie"><?= escaparValores($k['pie']) ?></span>
        </div>
    <?php endforeach; ?>
</section>

<div class="sf-toolbar">
    <div class="sf-filtros" role="group" aria-label="Filtrar ventas sin facturar">
        <?php foreach (['' => 'Todas', 'sin_clasificar' => 'Pendientes de facturar', 'no_facturada' => 'No facturadas'] as $clave => $texto) :
            $activo = $vista === $clave; ?>
            <a class="sf-filtro<?= $activo ? ' sf-filtro--activo' : '' ?>"
               href="/admin/contabilidad/sin-facturar?<?= escaparValores($consulta . ($clave !== '' ? '&vista=' . $clave : '')) ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
                <?= escaparValores($texto) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn--texto btn--sm" href="<?= escaparValores($enlaceIngresos) ?>">
        <span>Ver ventas y cobros</span>
        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
    </a>
</div>

<section class="tarjeta fin-card fin-card--tabla" aria-labelledby="titulo-sin-facturar">
    <div class="fin-card__cabecera">
        <div class="fin-card__texto">
            <h2 class="fin-card__titulo" id="titulo-sin-facturar">
                <span class="fin-card__icono" aria-hidden="true"><span class="material-symbols-outlined">receipt_long</span></span>
                Detalle de ventas
                <?php if ((int) $t['operaciones'] > 0) : ?>
                    <span class="badge badge--pendiente badge--sin-punto"><?= number_format((int) $t['operaciones']) ?></span>
                <?php endif; ?>
            </h2>
            <p class="fin-card__sub">Ninguna de estas ventas cuenta como CFDI emitido. Clasifícalas desde su detalle: una factura se confirma con el CFDI adjunto o de forma manual.</p>
        </div>
    </div>

    <?php if (!$sf['filas']) : ?>
        <div class="fin-vacio fin-vacio--ok">
            <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
            <p>No hay ventas sin facturar en este periodo.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor tabla-contenedor--scroll">
            <table class="tabla tabla--sf" data-sin-tarjetas>
                <caption class="solo-lectores">Pendientes de facturar</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Venta</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Negocio</th>
                        <th scope="col" class="tabla--numerica">Subtotal</th>
                        <th scope="col" class="tabla--numerica">IVA</th>
                        <th scope="col" class="tabla--numerica">Total</th>
                        <th scope="col" class="tabla--centro">Estado</th>
                        <th scope="col" class="tabla--centro">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sf['filas'] as $f) : $fact = \App\Contabilidad::FACTURACION[$f['estado_facturacion']]; ?>
                        <tr>
                            <td><span class="sf-fecha cifra"><?= date('d/m/Y', strtotime($f['fecha'])) ?></span></td>
                            <td>
                                <a class="sf-folio cifra" href="<?= escaparValores(\App\Contabilidad::rutaDetalle($f)) ?>">
                                    <?= escaparValores($f['folio']) ?>
                                </a>
                            </td>
                            <td><?= escaparValores($f['cliente_empresa'] ?: $f['cliente_nombre']) ?></td>
                            <td><?= escaparValores($f['empresa_nombre']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda((float) $f['subtotal'] - (float) $f['descuento']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['iva']) ?></td>
                            <td class="tabla--numerica cifra sf-total"><?= formatoMoneda($f['total']) ?></td>
                            <td class="tabla--centro"><span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span></td>
                            <td class="tabla--centro">
                                <span class="sf-acciones">
                                    <a class="btn btn--primario btn--sm" href="<?= escaparValores(\App\Contabilidad::rutaDetalle($f, 'facturacion')) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
                                        <span>Facturar</span>
                                    </a>
                                    <a class="btn btn--texto btn--sm oculto-en-movil" href="<?= escaparValores(\App\Contabilidad::rutaDetalle($f)) ?>">Abrir venta</a>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4"><strong>Total</strong></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($t['subtotal']) ?></td>
                        <td class="tabla--numerica cifra"><?= formatoMoneda($t['iva']) ?></td>
                        <td class="tabla--numerica cifra"><strong><?= formatoMoneda($t['total']) ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="compras-desliza">
            <span class="material-symbols-outlined" aria-hidden="true">swipe</span>
            Desliza la tabla para ver todas las columnas.
        </p>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

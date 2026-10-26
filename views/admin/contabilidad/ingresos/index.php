<?php
/**
 * views/admin/contabilidad/ingresos/index.php — Ingresos por ventas del periodo.
 * Generados automáticamente desde las ventas confirmadas; solo lectura.
 *
 * Dos tablas con los mismos filtros: las ventas con factura o pendientes de
 * facturar, y las que ya se marcaron como «No facturada». Cada tabla pagina
 * por su lado (parámetros «pagina» y «pagina_sin»).
 */

soloAdmin();

$periodo = periodoSeleccionado(false);
$filtros = $periodo + [
    'facturacion' => (string) ($_GET['facturacion'] ?? ''),
    'q'           => trim($_GET['q'] ?? ''),
    'estado'      => ($_GET['estado'] ?? '') === 'cancelado' ? 'cancelado' : '',
    'cobro'       => ($_GET['cobro'] ?? '') === 'pendiente' ? 'pendiente' : '',
    'desde'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['desde'] ?? '')) ? $_GET['desde'] : '',
    'hasta'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['hasta'] ?? '')) ? $_GET['hasta'] : '',
];
// Enlaces del panel principal: rango de fechas (o «todos», sin periodo) en lugar de año y mes.
if ($filtros['desde'] !== '' || $filtros['hasta'] !== '' || ($_GET['todos'] ?? '') === '1') {
    $filtros['anio'] = 0;
    $filtros['mes'] = 0;
}

/* Las dos tablas del periodo. Las de abajo («No facturada») se listan aparte
 * porque ya no se van a facturar: no deben mezclarse con las que sí. */
$conFactura  = $filtros + ['facturacion_grupo' => 'con_factura'];
$sinFactura  = $filtros + ['facturacion_grupo' => 'sin_factura'];
$totalCon    = \App\Contabilidad::contarIngresos($conFactura);
$totalSin    = \App\Contabilidad::contarIngresos($sinFactura);
$pagina      = paginacion(15, $totalCon);
$paginaSin   = paginacion(15, $totalSin, 'pagina_sin');
$ingresos    = \App\Contabilidad::listarIngresos($conFactura, $pagina['limite'], $pagina['offset']);
$ingresosSin = \App\Contabilidad::listarIngresos($sinFactura, $paginaSin['limite'], $paginaSin['offset']);
$empresas    = \App\Empresas::mostrarTodos(100, 0);

// Totales de cada tabla (el pie de cada una) y del periodo completo (las tarjetas de arriba).
$totCon = \App\Contabilidad::totalesIngresos($conFactura);
$totSin = \App\Contabilidad::totalesIngresos($sinFactura);
$tot = \App\Contabilidad::totalesIngresos($filtros);
$comparar = $filtros['anio'] > 0 && $filtros['mes'] > 0;
$ant = null;
if ($comparar) {
    [$anioAnt, $mesAnt] = \App\Contabilidad::mesAnterior($filtros['anio'], $filtros['mes']);
    $ant = \App\Contabilidad::totalesIngresos(['anio' => $anioAnt, 'mes' => $mesAnt] + $filtros);
}
$var = static fn (string $k): ?float => $ant ? \App\Contabilidad::variacion($tot[$k], $ant[$k]) : null;

$parametros = array_filter(['anio' => $filtros['anio'], 'mes' => $filtros['mes'], 'empresa' => $periodo['empresa'], 'facturacion' => $filtros['facturacion'], 'q' => $filtros['q'],
    'estado' => $filtros['estado'], 'cobro' => $filtros['cobro'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'], 'todos' => $filtros['anio'] ? '' : '1'],
    static fn ($v) => $v !== '' && $v !== 0);
$volverLista = $_SERVER['REQUEST_URI'] ?? '/admin/contabilidad/ingresos';
/** Conmutador de una celda: botón tipo switch que envía la acción y regresa a esta lista. */
$conmutador = static function (array $i, string $accion, bool $activo, string $etiqueta, string $titulo, string $mensaje, string $boton) use ($volverLista): string {
    ob_start(); ?>
    <form class="conmutador-estado" action="/admin/contabilidad/ingresos/accion" method="post"
          data-confirmacion-sobre="Ingreso" data-confirmacion="<?= escaparValores($titulo) ?>"
          data-confirmacion-mensaje="<?= escaparValores($mensaje) ?>" data-confirmacion-boton="<?= escaparValores($boton) ?>">
        <input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="accion" value="<?= $accion ?>">
        <input type="hidden" name="volver" value="<?= escaparValores($volverLista) ?>">
        <button class="conmutador-estado__boton" type="submit" role="switch" aria-checked="<?= $activo ? 'true' : 'false' ?>"
                aria-label="<?= escaparValores($etiqueta . ' · ' . $i['folio']) ?>"><span class="conmutador-estado__pista" aria-hidden="true"></span></button>
    </form>
    <?php return (string) ob_get_clean();
};
$rutaBase = '/admin/contabilidad/ingresos' . ($parametros ? '?' . http_build_query($parametros) : '');

/** Una fila de ingreso: las mismas columnas en las dos tablas. */
$filaTabla = static function (array $i) use ($conmutador): string {
    $fact = \App\Contabilidad::FACTURACION[$i['estado_facturacion']];
    $cobro = \App\Contabilidad::etiquetaCobro($i['estado_cobro']);
    ob_start(); ?>
    <tr>
        <td><?= date('d/m/Y', strtotime($i['fecha'])) ?></td>
        <td><strong><?= escaparValores($i['folio']) ?></strong></td>
        <td><?= escaparValores($i['cliente_empresa'] ?: $i['cliente_nombre']) ?></td>
        <td><?= escaparValores(trim((string) $i['cliente_alias']) !== '' ? $i['cliente_alias'] : '—') ?></td>
        <td><?= escaparValores($i['empresa_nombre']) ?></td>
        <td class="tabla--numerica cifra"><?= formatoMoneda((float) $i['subtotal'] - (float) $i['descuento']) ?></td>
        <td class="tabla--numerica cifra"><?= formatoMoneda($i['iva']) ?></td>
        <td class="tabla--numerica cifra">
            <strong><?= formatoMoneda($i['total']) ?></strong>
            <?php
            // Ingreso facturado con importe distinto a su venta (p. ej. tras
            // sustituir la factura por un monto menor): en contabilidad manda la factura.
            if ($i['estado'] === 'vigente' && isset($i['venta_total']) && $i['venta_total'] !== null
                && \App\Contabilidad::centavos($i['total']) !== \App\Contabilidad::centavos($i['venta_total'])) : ?>
                <br><span class="badge badge--pendiente" title="La venta <?= escaparValores($i['folio']) ?> es por <?= formatoMoneda((float) $i['venta_total']) ?>; la factura es la que se registra">≠ venta <?= formatoMoneda((float) $i['venta_total']) ?></span>
            <?php endif; ?>
        </td>
        <td class="tabla--centro"><div class="celda-estado"><span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
            <?php if ($i['estado'] === 'vigente') :
                $facturada = $i['estado_facturacion'] === 'facturada';
                echo $conmutador($i, 'facturacion_alterna', $facturada, 'Facturada', $facturada ? '¿Marcar como pendiente de facturar?' : '¿Marcar como facturada?',
                    $facturada ? 'Se quitan el folio y el UUID guardados de la factura.' : 'Si no hay un CFDI adjunto, queda como confirmación manual de que ya se emitió la factura.',
                    $facturada ? 'Sí, pendiente' : 'Sí, facturada');
            endif; ?></div></td>
        <td class="tabla--centro"><div class="celda-estado"><span class="badge <?= $cobro['clase'] ?>"><?= escaparValores($cobro['etiqueta']) ?></span>
            <?php if ($i['estado'] === 'vigente' && ($i['estado_cobro'] === 'cobrado' || (float) $i['saldo'] > 0)) :
                $pagada = $i['estado_cobro'] === 'cobrado';
                echo $conmutador($i, 'cobro_alterna', $pagada, 'Pagada', $pagada ? '¿Marcar como sin pagar?' : '¿Marcar como pagada?',
                    $pagada ? 'Se cancelan los cobros registrados y el saldo vuelve a ser el total.' : 'Se registra hoy un cobro por el saldo pendiente (' . formatoMoneda($i['saldo']) . ').',
                    $pagada ? 'Sí, sin pagar' : 'Sí, pagada');
            endif; ?></div></td>
        <td class="tabla--numerica cifra"><?= formatoMoneda($i['saldo']) ?></td>
        <td class="tabla--centro"><a href="<?= escaparValores(\App\Contabilidad::rutaDetalle($i, 'documentos')) ?>" aria-label="Ver los documentos de la venta <?= escaparValores($i['folio']) ?>"><?= (int) $i['documentos'] > 0 ? (int) $i['documentos'] . ' doc.' : '<span class="badge badge--neutro badge--sin-punto">Sin documentos</span>' ?></a></td>
        <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="<?= escaparValores(\App\Contabilidad::rutaDetalle($i)) ?>">Abrir</a></td>
    </tr>
    <?php return (string) ob_get_clean();
};
$encabezados = '<th scope="col">Fecha</th><th scope="col">Venta</th><th scope="col">Cliente</th><th scope="col">Alias</th><th scope="col">Negocio</th>'
    . '<th scope="col" class="tabla--numerica">Subtotal</th><th scope="col" class="tabla--numerica">IVA</th>'
    . '<th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--centro">Facturación</th>'
    . '<th scope="col" class="tabla--centro">Cobro</th><th scope="col" class="tabla--numerica">Saldo</th>'
    . '<th scope="col" class="tabla--centro">Documentos</th><th scope="col"><span class="solo-lectores">Acciones</span></th>';

template('header', ['titulo' => 'Ventas y cobros', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'ingresos', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/ingresos', 'periodo' => $periodo, 'empresas' => $empresas, 'conTodoElAnio' => true]);
template('resumenReporte', [
    'tarjetas' => [
        ['etiqueta' => 'Ventas sin IVA', 'valor' => formatoMoneda($tot['subtotal']), 'pie' => number_format($tot['n']) . ' ingresos', 'variacion' => $var('subtotal')],
        ['etiqueta' => 'IVA cobrado', 'valor' => formatoMoneda($tot['iva']), 'pie' => 'Incluido en el total', 'variacion' => $var('iva')],
        ['etiqueta' => 'Total vendido', 'valor' => formatoMoneda($tot['total']), 'pie' => 'Ya cobrado: ' . formatoMoneda($tot['cobrado']), 'variacion' => $var('total')],
        ['etiqueta' => 'Por cobrar', 'valor' => formatoMoneda($tot['saldo']), 'pie' => 'Saldo de estas ventas', 'variacion' => $var('saldo'), 'subeEsBueno' => false],
    ],
    'etiquetaComparativa' => $comparar ? 'vs. ' . nombreMes($mesAnt) . ' ' . $anioAnt : '',
    'exportar' => '/admin/contabilidad/ingresos/exportar' . ($parametros ? '?' . http_build_query($parametros) : ''),
]);
?>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/contabilidad/ingresos" method="get" role="search">
            <input type="hidden" name="anio" value="<?= $periodo['anio'] ?>">
            <input type="hidden" name="mes" value="<?= $periodo['mes'] ?>">
            <input type="hidden" name="empresa" value="<?= $periodo['empresa'] ?>">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar ingreso</label>
                <input class="control" type="search" id="q" name="q" maxlength="80" placeholder="Folio, cliente o UUID…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="facturacion">Facturación</label>
            <select class="control control--compacto" id="facturacion" name="facturacion">
                <option value="">Toda la facturación</option>
                <?php foreach (\App\Contabilidad::FACTURACION as $clave => $d) : ?>
                    <option value="<?= $clave ?>"<?= $filtros['facturacion'] === $clave ? ' selected' : '' ?>><?= escaparValores($d['etiqueta']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Vigentes</option>
                <option value="cancelado"<?= $filtros['estado'] === 'cancelado' ? ' selected' : '' ?>>Cancelados</option>
            </select>
            <button class="btn btn--secundario btn--sm" type="submit">Filtrar</button>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($totalCon) ?> <?= $totalCon === 1 ? 'venta' : 'ventas' ?></span>
    </div>

    <h2 class="titulo-5 mb-md">Ventas con factura o pendientes de facturar</h2>

    <?php if (!$ingresos) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">trending_up</span>
            <p class="vacio__titulo">Sin ventas de este tipo en este periodo</p>
            <p>Aquí aparecen las ventas facturadas y las que todavía faltan por facturar. Cada venta confirmada genera su ingreso automáticamente.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Ventas facturadas y pendientes de facturar</caption>
                <thead><tr><?= $encabezados ?></tr></thead>
                <tbody>
                    <?php foreach ($ingresos as $i) : ?><?= $filaTabla($i) ?><?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5"><strong>Total de los <?= number_format($totCon['n']) ?> ingresos filtrados</strong></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($totCon['subtotal']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($totCon['iva']) ?></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($totCon['total']) ?></strong></td><td colspan="2"></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($totCon['saldo']) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $totalCon]); ?>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="flex-entre fila--envuelto mb-md">
        <h2 class="titulo-5">Ventas indicadas como sin factura (no se facturarán)</h2>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($totalSin) ?> <?= $totalSin === 1 ? 'venta' : 'ventas' ?></span>
    </div>

    <?php if (!$ingresosSin) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">receipt_long</span>
            <p class="vacio__titulo">Ninguna venta marcada como «No facturada»</p>
            <p>Cuando en una venta digas que no se facturará, aparecerá aquí.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Ventas marcadas como sin factura</caption>
                <thead><tr><?= $encabezados ?></tr></thead>
                <tbody>
                    <?php foreach ($ingresosSin as $i) : ?><?= $filaTabla($i) ?><?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5"><strong>Total de las <?= number_format($totSin['n']) ?> ventas sin factura</strong></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($totSin['subtotal']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($totSin['iva']) ?></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($totSin['total']) ?></strong></td><td colspan="2"></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($totSin['saldo']) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        <p class="campo__ayuda">Estas ventas ya no se van a facturar: no cuentan como CFDI emitido. Si alguna vez sí se factura, usa el interruptor de la columna «Facturación» y pasa a la tabla de arriba.</p>
        <?php template('paginacion', ['pagina' => $paginaSin, 'rutaBase' => $rutaBase, 'total' => $totalSin, 'parametro' => 'pagina_sin']); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

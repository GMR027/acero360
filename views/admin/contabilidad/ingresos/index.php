<?php
/**
 * views/admin/contabilidad/ingresos/index.php — Ingresos por ventas del periodo.
 * Generados automáticamente desde las ventas confirmadas; solo lectura.
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
$total    = \App\Contabilidad::contarIngresos($filtros);
$pagina   = paginacion(15, $total);
$ingresos = \App\Contabilidad::listarIngresos($filtros, $pagina['limite'], $pagina['offset']);
$empresas = \App\Empresas::mostrarTodos(100, 0);

// Resumen del filtro y comparativa con el mes anterior (solo cuando se mira un mes concreto).
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

template('header', ['titulo' => 'Ingresos por ventas', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
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

<section class="tarjeta tarjeta--sin-relleno">
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
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'ingreso' : 'ingresos' ?></span>
    </div>

    <?php if (!$ingresos) : ?>
        <div class="vacio">
            <span class="material-symbols-outlined vacio__icono" aria-hidden="true">trending_up</span>
            <p class="vacio__titulo">Sin ingresos en este periodo</p>
            <p>Cada venta confirmada genera su ingreso automáticamente.</p>
        </div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Ingresos por ventas</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Venta</th><th scope="col">Cliente</th><th scope="col">Negocio</th>
                    <th scope="col" class="tabla--numerica">Subtotal</th><th scope="col" class="tabla--numerica">IVA</th>
                    <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--centro">Facturación</th>
                    <th scope="col" class="tabla--centro">Cobro</th><th scope="col" class="tabla--numerica">Saldo</th>
                    <th scope="col" class="tabla--centro">Documentos</th>
                    <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($ingresos as $i) :
                        $fact = \App\Contabilidad::FACTURACION[$i['estado_facturacion']];
                        $cobro = \App\Contabilidad::etiquetaCobro($i['estado_cobro']); ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($i['fecha'])) ?></td>
                            <td><strong><?= escaparValores($i['folio']) ?></strong></td>
                            <td><?= escaparValores($i['cliente_empresa'] ?: $i['cliente_nombre']) ?></td>
                            <td><?= escaparValores($i['empresa_nombre']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda((float) $i['subtotal'] - (float) $i['descuento']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($i['iva']) ?></td>
                            <td class="tabla--numerica cifra"><strong><?= formatoMoneda($i['total']) ?></strong></td>
                            <td class="tabla--centro"><span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span>
                                <?php if ($i['estado'] === 'vigente') :
                                    $facturada = $i['estado_facturacion'] === 'facturada';
                                    echo $conmutador($i, 'facturacion_alterna', $facturada, 'Facturada', $facturada ? '¿Marcar como pendiente de facturar?' : '¿Marcar como facturada?',
                                        $facturada ? 'Se quitan el folio y el UUID guardados de la factura.' : 'Si no hay un CFDI adjunto, queda como confirmación manual de que ya se emitió la factura.',
                                        $facturada ? 'Sí, pendiente' : 'Sí, facturada');
                                endif; ?></td>
                            <td class="tabla--centro"><span class="badge <?= $cobro['clase'] ?>"><?= escaparValores($cobro['etiqueta']) ?></span>
                                <?php if ($i['estado'] === 'vigente' && ($i['estado_cobro'] === 'cobrado' || (float) $i['saldo'] > 0)) :
                                    $pagada = $i['estado_cobro'] === 'cobrado';
                                    echo $conmutador($i, 'cobro_alterna', $pagada, 'Pagada', $pagada ? '¿Marcar como sin pagar?' : '¿Marcar como pagada?',
                                        $pagada ? 'Se cancelan los cobros registrados y el saldo vuelve a ser el total.' : 'Se registra hoy un cobro por el saldo pendiente (' . formatoMoneda($i['saldo']) . ').',
                                        $pagada ? 'Sí, sin pagar' : 'Sí, pagada');
                                endif; ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($i['saldo']) ?></td>
                            <td class="tabla--centro"><a href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $i['id'] ?>#documentos" aria-label="Ver los documentos de la venta <?= escaparValores($i['folio']) ?>"><?= (int) $i['documentos'] > 0 ? (int) $i['documentos'] . ' doc.' : '<span class="badge badge--neutro badge--sin-punto">Sin documentos</span>' ?></a></td>
                            <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $i['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4"><strong>Total de los <?= number_format($tot['n']) ?> ingresos filtrados</strong></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($tot['subtotal']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($tot['iva']) ?></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($tot['total']) ?></strong></td><td colspan="2"></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($tot['saldo']) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

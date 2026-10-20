<?php
/**
 * views/admin/contabilidad/gastos/index.php — Egresos y gastos del periodo.
 */

soloAdmin();

$periodo = periodoSeleccionado(false);
$filtros = $periodo + [
    'q' => trim($_GET['q'] ?? ''), 'pago' => (string) ($_GET['pago'] ?? ''),
    'sin_comprobante' => ($_GET['sin_comprobante'] ?? '') === '1' ? '1' : '',
    'estado' => ($_GET['estado'] ?? '') === 'cancelado' ? 'cancelado' : '',
    'desde' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['desde'] ?? '')) ? $_GET['desde'] : '',
    'hasta' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['hasta'] ?? '')) ? $_GET['hasta'] : '',
];
// Enlaces del panel principal: rango de fechas (o «todos», sin periodo) en lugar de año y mes.
if ($filtros['desde'] !== '' || $filtros['hasta'] !== '' || ($_GET['todos'] ?? '') === '1') {
    $filtros['anio'] = 0;
    $filtros['mes'] = 0;
}
$total    = \App\Gastos::contar($filtros);
$pagina   = paginacion(15, $total);
$gastos   = \App\Gastos::listar($filtros, $pagina['limite'], $pagina['offset']);
$empresas = \App\Empresas::mostrarTodos(100, 0);
$tot = \App\Gastos::totales($filtros);
$comparar = $filtros['anio'] > 0 && $filtros['mes'] > 0;
$ant = null;
if ($comparar) {
    [$anioAnt, $mesAnt] = \App\Contabilidad::mesAnterior($filtros['anio'], $filtros['mes']);
    $ant = \App\Gastos::totales(['anio' => $anioAnt, 'mes' => $mesAnt] + $filtros);
}
$var = static fn (string $k): ?float => $ant ? \App\Contabilidad::variacion($tot[$k], $ant[$k]) : null;
$parametros = array_filter($filtros + ['todos' => $filtros['anio'] ? '' : '1'], static fn ($v) => $v !== '' && $v !== 0);
$rutaBase = '/admin/contabilidad/gastos' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', [
    'titulo' => 'Egresos y gastos', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad',
    'acciones' => '<a class="btn btn--primario" href="/admin/contabilidad/gastos/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span><span>Nuevo gasto</span></a>',
]);
template('tabsContabilidad', ['tab' => 'gastos', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/gastos', 'periodo' => $periodo, 'empresas' => $empresas, 'conTodoElAnio' => true]);
template('resumenReporte', [
    'tarjetas' => [
        ['etiqueta' => 'Gastos sin IVA', 'valor' => formatoMoneda($tot['subtotal']), 'pie' => number_format($tot['n']) . ' gastos', 'variacion' => $var('subtotal'), 'subeEsBueno' => false],
        ['etiqueta' => 'IVA pagado', 'valor' => formatoMoneda($tot['iva']), 'pie' => 'Incluido en el total', 'variacion' => $var('iva'), 'subeEsBueno' => false],
        ['etiqueta' => 'Total gastado', 'valor' => formatoMoneda($tot['total']), 'pie' => 'Por pagar: ' . formatoMoneda($tot['por_pagar']), 'variacion' => $var('total'), 'subeEsBueno' => false],
        ['etiqueta' => 'Sin comprobante', 'valor' => number_format($tot['sin_comprobante']), 'pie' => 'No se restan de impuestos', 'variacion' => $var('sin_comprobante'), 'subeEsBueno' => false],
    ],
    'etiquetaComparativa' => $comparar ? 'vs. ' . nombreMes($mesAnt) . ' ' . $anioAnt : '',
    'exportar' => '/admin/contabilidad/gastos/exportar' . ($parametros ? '?' . http_build_query($parametros) : ''),
]);
?>

<section class="tarjeta tarjeta--sin-relleno">
    <div class="tabla-toolbar">
        <form class="buscador" action="/admin/contabilidad/gastos" method="get" role="search">
            <input type="hidden" name="anio" value="<?= $periodo['anio'] ?>"><input type="hidden" name="mes" value="<?= $periodo['mes'] ?>">
            <input type="hidden" name="empresa" value="<?= $periodo['empresa'] ?>">
            <div class="buscador__campo">
                <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
                <label class="solo-lectores" for="q">Buscar gasto</label>
                <input class="control" type="search" id="q" name="q" maxlength="80" placeholder="Proveedor, concepto o UUID…" value="<?= escaparValores($filtros['q']) ?>">
            </div>
            <label class="solo-lectores" for="pago">Pago</label>
            <select class="control control--compacto" id="pago" name="pago">
                <option value="">Pagados y pendientes</option>
                <option value="pendiente"<?= $filtros['pago'] === 'pendiente' ? ' selected' : '' ?>>Pendientes de pago</option>
                <option value="pagado"<?= $filtros['pago'] === 'pagado' ? ' selected' : '' ?>>Pagados</option>
            </select>
            <label class="solo-lectores" for="estado">Estado</label>
            <select class="control control--compacto" id="estado" name="estado">
                <option value="">Vigentes</option><option value="cancelado"<?= $filtros['estado'] === 'cancelado' ? ' selected' : '' ?>>Cancelados</option>
            </select>
            <label class="comprobacion" for="sin_comprobante"><input type="checkbox" id="sin_comprobante" name="sin_comprobante" value="1" <?= $filtros['sin_comprobante'] ? 'checked' : '' ?>><span>Sin comprobante</span></label>
            <button class="btn btn--secundario btn--sm" type="submit">Filtrar</button>
        </form>
        <span class="badge badge--neutro badge--sin-punto cifra"><?= number_format($total) ?> <?= $total === 1 ? 'gasto' : 'gastos' ?></span>
    </div>

    <?php if (!$gastos) : ?>
        <div class="vacio"><span class="material-symbols-outlined vacio__icono" aria-hidden="true">trending_down</span>
            <p class="vacio__titulo">Sin gastos en este periodo</p>
            <a class="btn btn--primario" href="/admin/contabilidad/gastos/crear">Registrar un gasto</a></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Gastos del periodo</caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Proveedor / concepto</th><th scope="col">Categoría</th><th scope="col">Negocio</th>
                    <th scope="col" class="tabla--numerica">Subtotal</th><th scope="col" class="tabla--numerica">IVA</th><th scope="col" class="tabla--numerica">Total</th>
                    <th scope="col" class="tabla--centro">Pago</th><th scope="col" class="tabla--centro">Comprobante</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($gastos as $g) : ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                            <td><strong><?= escaparValores($g['beneficiario']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores($g['concepto']) ?></span></td>
                            <td><?= escaparValores((string) $g['categoria_nombre']) ?: '—' ?></td>
                            <td><?= (int) $g['compartido'] === 1 ? '<span class="badge badge--info">Compartido</span>' : escaparValores((string) ($g['empresa_nombre'] ?? 'General')) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($g['subtotal']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($g['iva']) ?></td>
                            <td class="tabla--numerica cifra"><strong><?= formatoMoneda($g['total']) ?></strong></td>
                            <td class="tabla--centro"><?= $g['estado_pago'] === 'pagado' ? '<span class="badge badge--aprobado">Pagado</span>' : '<span class="badge badge--pendiente">Pendiente</span>' ?></td>
                            <td class="tabla--centro"><?= (int) $g['comprobante_fiscal'] === 1 ? '<span class="badge badge--aprobado">Sí</span>' : '<span class="badge badge--neutro">No</span>' ?>
                                <br><a class="texto-xs" href="/admin/contabilidad/gastos/detalle?id=<?= (int) $g['id'] ?>#documentos" aria-label="Ver los documentos del gasto"><?= (int) $g['documentos'] ?> doc.</a></td>
                            <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/contabilidad/gastos/detalle?id=<?= (int) $g['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4"><strong>Total de los <?= number_format($tot['n']) ?> gastos filtrados</strong></td>
                    <td class="tabla--numerica cifra"><?= formatoMoneda($tot['subtotal']) ?></td><td class="tabla--numerica cifra"><?= formatoMoneda($tot['iva']) ?></td>
                    <td class="tabla--numerica cifra"><strong><?= formatoMoneda($tot['total']) ?></strong></td><td colspan="3"></td></tr></tfoot>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

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
];
$total    = \App\Contabilidad::contarIngresos($filtros);
$pagina   = paginacion(15, $total);
$ingresos = \App\Contabilidad::listarIngresos($filtros, $pagina['limite'], $pagina['offset']);
$empresas = \App\Empresas::mostrarTodos(100, 0);

$parametros = array_filter($periodo + ['facturacion' => $filtros['facturacion'], 'q' => $filtros['q'], 'estado' => $filtros['estado']],
    static fn ($v) => $v !== '' && $v !== 0);
$rutaBase = '/admin/contabilidad/ingresos' . ($parametros ? '?' . http_build_query($parametros) : '');

template('header', ['titulo' => 'Ingresos por ventas', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
template('tabsContabilidad', ['tab' => 'ingresos', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/contabilidad/ingresos', 'periodo' => $periodo, 'empresas' => $empresas, 'conTodoElAnio' => true]);
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
                            <td class="tabla--centro"><span class="badge <?= $fact['clase'] ?>"><?= escaparValores($fact['etiqueta']) ?></span></td>
                            <td class="tabla--centro"><span class="badge <?= $cobro['clase'] ?>"><?= escaparValores($cobro['etiqueta']) ?></span></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($i['saldo']) ?></td>
                            <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/contabilidad/ingresos/detalle?id=<?= (int) $i['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php template('paginacion', ['pagina' => $pagina, 'rutaBase' => $rutaBase, 'total' => $total]); ?>
    <?php endif; ?>
</section>

<?php template('footer'); ?>

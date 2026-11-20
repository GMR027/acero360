<?php
/**
 * views/admin/fiscal/movimientos.php — Todas las ventas y todos los gastos de ambos negocios, con los impuestos
 * por pagar del periodo. Se puede exportar a PDF, Excel o CSV. Solo lectura.
 * Cada fila indica si ya se pagó («Pagada»/«Pagado»); sólo eso entra en la estimación de impuestos.
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$anio    = (int) ($_GET['anio'] ?? date('Y'));
$anio    = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$mes     = (int) ($_GET['mes'] ?? date('n'));
$mes     = ($mes >= 0 && $mes <= 12) ? $mes : (int) date('n');

// Filtros de la lista. «tipo_mov» se llama así para no chocar con el «tipo»
// del enlace de exportación (movimientos/impuestos/preliminar).
$filtrosMov = [
    'tipo'        => in_array($_GET['tipo_mov'] ?? '', ['Venta', 'Gasto'], true) ? (string) $_GET['tipo_mov'] : '',
    'estado'      => in_array($_GET['estado'] ?? '', ['pagado', 'pendiente'], true) ? (string) $_GET['estado'] : '',
    'facturacion' => in_array($_GET['facturacion'] ?? '', ['facturada', 'pendiente'], true) ? (string) $_GET['facturacion'] : '',
    'q'           => trim((string) ($_GET['q'] ?? '')),
];
$d   = \App\ResumenFiscal::movimientos($contrib, $anio, $mes, $filtrosMov);
$imp = \App\ResumenFiscal::impuestosDelPeriodo($contrib, $anio, $mes);
$m   = static fn (?float $v): string => $v === null ? '—' : ($v < 0 ? '−' : '') . formatoMoneda(abs($v));
$periodoTexto = ($mes > 0 ? nombreMes($mes) . ' ' : '') . $anio;
$consulta = http_build_query(array_filter([
    'anio' => $anio, 'mes' => $mes,
    'tipo_mov' => $filtrosMov['tipo'], 'estado' => $filtrosMov['estado'],
    'facturacion' => $filtrosMov['facturacion'], 'q' => $filtrosMov['q'],
], static fn ($v) => $v !== '' && $v !== 0));
$hayFiltrosMov = $filtrosMov['tipo'] !== '' || $filtrosMov['estado'] !== ''
    || $filtrosMov['facturacion'] !== '' || $filtrosMov['q'] !== '';

template('header', ['titulo' => 'Ventas y gastos', 'subtitulo' => 'Ambos negocios · ' . $periodoTexto, 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'movimientos', 'periodo' => ['anio' => $anio, 'mes' => max(1, $mes)]]);
?>

<form class="buscador mb-lg" action="/admin/fiscal/movimientos" method="get" aria-label="Elegir periodo">
    <label class="solo-lectores" for="sel-anio">Año</label>
    <select class="control control--compacto" id="sel-anio" name="anio">
        <?php foreach (\App\Contabilidad::aniosConMovimientos() as $a) : ?>
            <option value="<?= $a ?>"<?= $anio === $a ? ' selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
    </select>
    <label class="solo-lectores" for="sel-mes">Mes</label>
    <select class="control control--compacto" id="sel-mes" name="mes">
        <option value="0"<?= $mes === 0 ? ' selected' : '' ?>>Todo el año</option>
        <?php for ($i = 1; $i <= 12; $i++) : ?>
            <option value="<?= $i ?>"<?= $mes === $i ? ' selected' : '' ?>><?= nombreMes($i) ?></option>
        <?php endfor; ?>
    </select>
    <button class="btn btn--secundario btn--sm" type="submit">Ver</button>
</form>

<form class="buscador mb-lg" action="/admin/fiscal/movimientos" method="get" role="search" aria-label="Filtrar ventas y gastos">
    <input type="hidden" name="anio" value="<?= $anio ?>">
    <input type="hidden" name="mes" value="<?= $mes ?>">
    <div class="buscador__campo">
        <span class="material-symbols-outlined buscador__icono" aria-hidden="true">search</span>
        <label class="solo-lectores" for="mov-q">Buscar en ventas y gastos</label>
        <input class="control" type="search" id="mov-q" name="q" maxlength="80"
               placeholder="Folio, cliente, proveedor o concepto…" value="<?= escaparValores($filtrosMov['q']) ?>">
    </div>
    <label class="solo-lectores" for="mov-tipo">Tipo</label>
    <select class="control control--compacto" id="mov-tipo" name="tipo_mov">
        <option value="">Ventas y gastos</option>
        <option value="Venta"<?= $filtrosMov['tipo'] === 'Venta' ? ' selected' : '' ?>>Solo ventas</option>
        <option value="Gasto"<?= $filtrosMov['tipo'] === 'Gasto' ? ' selected' : '' ?>>Solo gastos</option>
    </select>
    <label class="solo-lectores" for="mov-estado">Pago</label>
    <select class="control control--compacto" id="mov-estado" name="estado">
        <option value="">Pagadas y pendientes</option>
        <option value="pagado"<?= $filtrosMov['estado'] === 'pagado' ? ' selected' : '' ?>>Pagadas</option>
        <option value="pendiente"<?= $filtrosMov['estado'] === 'pendiente' ? ' selected' : '' ?>>Pendientes</option>
    </select>
    <label class="solo-lectores" for="mov-facturacion">Facturación</label>
    <select class="control control--compacto" id="mov-facturacion" name="facturacion">
        <option value="">Toda la facturación</option>
        <option value="facturada"<?= $filtrosMov['facturacion'] === 'facturada' ? ' selected' : '' ?>>Facturadas</option>
        <option value="pendiente"<?= $filtrosMov['facturacion'] === 'pendiente' ? ' selected' : '' ?>>Pendientes de facturar</option>
    </select>
    <button class="btn btn--secundario btn--sm" type="submit">Filtrar</button>
    <?php if ($hayFiltrosMov) : ?>
        <a class="btn btn--texto btn--sm" href="/admin/fiscal/movimientos?anio=<?= $anio ?>&amp;mes=<?= $mes ?>">Limpiar</a>
    <?php endif; ?>
</form>

<section class="estadisticas-resumen" aria-label="Resumen del periodo">
    <div class="tarjeta"><span class="stat__etiqueta">Ventas</span>
        <span class="estadisticas-resumen__valor"><?= $m($d['totales']['ventas']) ?></span>
        <span class="stat__pie">Facturadas: <?= $m($d['totales']['ventas_facturadas']) ?> · ya cobradas: <?= $m($d['totales']['ventas_facturadas_pagadas']) ?></span></div>
    <div class="tarjeta"><span class="stat__etiqueta">Gastos</span>
        <span class="estadisticas-resumen__valor"><?= $m($d['totales']['gastos']) ?></span>
        <span class="stat__pie">Pagados: <?= $m($d['totales']['gastos_pagados']) ?> (sólo ellos descuentan)</span></div>
    <div class="tarjeta"><span class="stat__etiqueta"><?= $imp['impuestos'] >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?> · <?= escaparValores($periodoTexto) ?></span>
        <span class="estadisticas-resumen__valor"><?= $m(abs($imp['impuestos'])) ?></span>
        <span class="stat__pie">IVA <?= $m($imp['iva_por_pagar']) ?> + ISR <?= $m($imp['isr_por_pagar']) ?> (estimación con catálogos versionados)</span></div>
    <?php if (empty($imp['habilitado'])) : ?>
        <div class="tarjeta"><span class="stat__etiqueta">Cálculo deshabilitado</span>
            <span class="stat__pie">Sin catálogos activos ni configuración fiscal para este periodo: los importes NO se calculan (no se inventan cifras).
                <a href="/admin/fiscal/configuracion">Revisar</a></span></div>
    <?php endif; ?>
</section>

<?php
/* Lo que la estimación deja fuera del periodo: facturas sin pagar o a medias y gastos por pagar. */
$fuera = [];
if ((int) ($imp['facturas_sin_pagar_n'] ?? 0) > 0) {
    $fuera[] = number_format((int) $imp['facturas_sin_pagar_n']) . ' factura(s) sin pagar o en parcialidades por ' . $m($imp['facturas_sin_pagar']);
}
if ((int) ($imp['gastos_no_pagados_n'] ?? 0) > 0) {
    $fuera[] = number_format((int) $imp['gastos_no_pagados_n']) . ' gasto(s) por pagar por ' . $m($imp['gastos_no_pagados']);
}
?>
<?php if ($fuera) : ?>
    <p class="nota mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">schedule</span>
        <span>No entran en los impuestos de arriba: <?= implode(' y ', $fuera) ?>. Se cuentan cuando se confirme que ya se pagaron.</span></p>
<?php endif; ?>

<div class="formulario__acciones mb-lg" style="justify-content:flex-start">
    <?php foreach (['pdf' => ['PDF', 'picture_as_pdf'], 'xlsx' => ['Excel', 'table'], 'csv' => ['CSV', 'description']] as $formato => [$texto, $icono]) : ?>
        <a class="btn btn--secundario" href="/admin/fiscal/exportar?<?= $consulta ?>&amp;tipo=movimientos&amp;formato=<?= $formato ?>"><span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= $icono ?></span><span>Exportar <?= $texto ?></span></a>
    <?php endforeach; ?>
</div>

<section class="tarjeta tarjeta--sin-relleno mb-lg">
    <?php if (!$d['filas']) : ?>
        <div class="vacio"><p class="vacio__titulo">Sin ventas ni gastos en este periodo</p></div>
    <?php else : ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption class="solo-lectores">Ventas y gastos de <?= escaparValores($periodoTexto) ?></caption>
                <thead><tr><th scope="col">Fecha</th><th scope="col">Folio o concepto</th><th scope="col">Cliente o proveedor</th>
                    <th scope="col">Detalle</th><th scope="col" class="tabla--numerica">Subtotal</th><th scope="col" class="tabla--numerica">IVA</th><th scope="col" class="tabla--numerica">Total</th>
                    <th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($d['filas'] as $f) :
                        $esVenta = $f['tipo'] === 'Venta';
                        $rutaOperacion = $esVenta
                            ? (!empty($f['venta_id'])
                                ? '/admin/ventas/detalle?id=' . (int) $f['venta_id']
                                : '/admin/contabilidad/ingresos/detalle?id=' . (int) $f['ingreso_id'])
                            : '/admin/contabilidad/gastos/detalle?id=' . (int) $f['gasto_id'];
                        $rutaPago = $esVenta
                            ? $rutaOperacion . '#cobros'
                            : '/admin/contabilidad/gastos/pagar?id=' . (int) $f['gasto_id'];
                        $pagado = $esVenta ? $f['cobro'] === 'Pagada' : $f['pago'] === 'Pagado';
                        // Los gastos de una compra se pagan desde la compra.
                        $puedePagar = !$pagado && ($esVenta || (int) ($f['de_compra'] ?? 0) === 0);
                        ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($f['fecha'])) ?></td>
                            <td>
                                <strong><?= escaparValores($f['referencia']) ?></strong><br>
                                <span class="badge <?= $f['tipo'] === 'Venta' ? 'badge--exito' : 'badge--info' ?>"><?= $f['tipo'] ?></span>
                            </td>
                            <td>
                                <?= escaparValores($f['contraparte']) ?>
                                <span class="texto-xs texto-secundario"><br><?= escaparValores($f['negocio']) ?></span>
                            </td>
                            <td><?= escaparValores($f['detalle']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['subtotal']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['iva']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['total']) ?></td>
                            <td class="tabla__acciones" style="max-width:12rem">
                                <?php if ($puedePagar) : ?>
                                    <a class="btn btn--primario btn--sm" href="<?= escaparValores($rutaPago) ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">payments</span>
                                        <span><?= $esVenta ? 'Cobrar' : 'Pagar' ?></span>
                                    </a>
                                <?php endif; ?>
                                <a class="btn btn--texto btn--sm" href="<?= escaparValores($rutaOperacion) ?>">Abrir</a>
                                <a class="btn btn--texto btn--sm" href="<?= escaparValores($rutaOperacion) ?>#documentos">Documentos</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<p class="campo__ayuda">Los impuestos de arriba usan <strong>sólo</strong> las ventas facturadas y <strong>cobradas al 100 %</strong> y los gastos ya <strong>pagados</strong> (estimación). Aquí se listan todas las ventas y gastos del periodo con su estado: las que no se han pagado no descuentan ni gravan hasta confirmarse. El método del CFDI (PUE una sola exhibición, PPD parcialidades o por definir) es informativo y aparece en el detalle; los archivos adjuntos son sólo respaldo documental y no cambian los importes.</p>

<?php template('footer'); ?>

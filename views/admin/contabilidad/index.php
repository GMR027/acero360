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
// Las ventas sin factura no cuentan en «Lo que vendiste»: se muestran aparte en su propio cuadro.
$sinFactura = $r['facturacion']['no_facturada'];
$entra = $r['ventas']['total'] - $sinFactura['total'];
$operacionesFacturables = $r['ventas']['operaciones'] - $sinFactura['operaciones'];
$sale  = $gasto($g['total']);
$idContrib = \App\Contabilidad::contribuyentePrincipal();
$imp = \App\ResumenFiscal::impuestosDelPeriodo($idContrib, $periodo['anio'], $periodo['mes']);
$impAPagar = $imp['impuestos'];
$conSigno = static fn (float $v): string => ($v < 0 ? '−' : '') . formatoMoneda(abs($v));
[$anioAnt, $mesAnt] = \App\Contabilidad::mesAnterior($periodo['anio'], $periodo['mes']);
$ra = \App\Contabilidad::resumenPeriodo($anioAnt, $mesAnt, $periodo['empresa']);
$entraAnt = $ra['ventas']['total'] - $ra['facturacion']['no_facturada']['total'];
$saleAnt  = $gasto($ra['gastos']['total']);
$impAnt   = \App\ResumenFiscal::impuestosDelPeriodo($idContrib, $anioAnt, $mesAnt)['impuestos'];

// Gastos del mes: los que tienen factura (se restan de impuestos) y los que no (solo informativos).
$todosGastos = \App\Gastos::listar(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'empresa' => $periodo['empresa']], 200, 0);
$gastosConFactura = array_values(array_filter($todosGastos, static fn ($x) => (int) $x['comprobante_fiscal'] === 1));
$gastosSinFactura = array_values(array_filter($todosGastos, static fn ($x) => (int) $x['comprobante_fiscal'] === 0));
$sumar = static fn (array $lista): float => array_sum(array_map(static fn ($x) => (float) $x['total'], $lista));
$mesTexto = mb_strtolower(nombreMes($periodo['mes'])) . ' ' . $periodo['anio'];
$enlaceGastos = '/admin/contabilidad/gastos?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'empresa' => $periodo['empresa']]);
?>
<?php if ($cerrado) : ?>
    <p class="nota nota--advertencia mb-lg" role="note"><span class="material-symbols-outlined nota__icono" aria-hidden="true">lock</span>
        <span><strong>Este mes ya está cerrado.</strong> No se pueden cambiar sus ventas ni gastos.</span></p>
<?php endif; ?>

<?php
template('resumenReporte', [
    'tarjetas' => [
        ['etiqueta' => 'Lo que vendiste', 'valor' => formatoMoneda($entra), 'pie' => number_format($operacionesFacturables) . ' ventas con factura · ya cobraste ' . formatoMoneda($r['cobrado_en_periodo']),
            'variacion' => \App\Contabilidad::variacion($entra, $entraAnt)],
        ['etiqueta' => 'Lo que gastaste', 'valor' => formatoMoneda($sale), 'pie' => number_format($g['operaciones']) . ' gastos · ya pagaste ' . formatoMoneda($gasto($g['pagado'])),
            'variacion' => \App\Contabilidad::variacion($sale, $saleAnt), 'subeEsBueno' => false],
        ['etiqueta' => $impAPagar >= 0 ? 'Impuestos que debes pagar' : 'Impuestos a tu favor', 'valor' => formatoMoneda(abs($impAPagar)),
            'pie' => 'IVA ' . $conSigno($imp['iva_por_pagar']) . ' + ISR ' . $conSigno($imp['isr_por_pagar']) . ' (estimación)',
            'variacion' => \App\Contabilidad::variacion($impAPagar, $impAnt), 'subeEsBueno' => false],
    ],
    'etiquetaComparativa' => 'vs. ' . nombreMes($mesAnt) . ' ' . $anioAnt,
    'exportar' => '/admin/fiscal/exportar?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'tipo' => 'movimientos']),
    'accionesExtra' => [
        [
            'destino' => 'venta',
            'texto'   => 'Registro Venta Inteligente',
            'icono'   => 'receipt_long',
            'ayuda'   => 'Sube la factura (XML, PDF o foto) que emitiste y el formulario de venta se llena solo con el cliente, las fechas y los renglones que sí se reconocen',
        ],
        [
            'destino' => 'gasto',
            'texto'   => 'Registro compra inteligente',
            'icono'   => 'shopping_cart',
            'ayuda'   => 'Sube la factura (XML, PDF o foto) que te dieron y el formulario de gasto se llena solo con el proveedor, el total, el folio fiscal y los renglones',
        ],
    ],
    'ocrDisponible' => \App\LectorDocumental::ocrDisponible(),
]);
?>

<?php
/** Tabla de gastos del mes; $conIva = false oculta la columna de IVA (gastos sin factura no llevan IVA acreditable). */
$tablaGastos = static function (array $lista, string $pie, bool $conIva): void { ?>
    <div class="tabla-contenedor"><table class="tabla">
        <caption class="solo-lectores"><?= escaparValores($pie) ?></caption>
        <thead><tr><th scope="col">Fecha</th><th scope="col">En qué / a quién</th><th scope="col">Categoría</th>
            <?php if ($conIva) : ?><th scope="col" class="tabla--numerica">IVA</th><?php endif; ?>
            <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--centro">¿Ya lo pagaste?</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $x) : ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($x['fecha'])) ?></td>
                <td><strong><?= escaparValores($x['beneficiario']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores($x['concepto']) ?></span></td>
                <td><?= escaparValores((string) $x['categoria_nombre']) ?: '—' ?></td>
                <?php if ($conIva) : ?><td class="tabla--numerica cifra"><?= formatoMoneda($x['iva']) ?></td><?php endif; ?>
                <td class="tabla--numerica cifra"><strong><?= formatoMoneda($x['total']) ?></strong></td>
                <td class="tabla--centro"><?= $x['estado_pago'] === 'pagado' ? '<span class="badge badge--aprobado">Sí, pagado</span>' : '<span class="badge badge--pendiente">Falta pagar</span>' ?></td>
                <td class="tabla--numerica"><a class="btn btn--secundario btn--sm" href="/admin/contabilidad/gastos/detalle?id=<?= (int) $x['id'] ?>">Abrir</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="<?= $conIva ? 4 : 3 ?>"><strong><?= escaparValores($pie) ?></strong></td>
            <td class="tabla--numerica cifra"><strong><?= formatoMoneda(array_sum(array_map(static fn ($x) => (float) $x['total'], $lista))) ?></strong></td><td colspan="2"></td></tr></tfoot>
    </table></div>
<?php };
?>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="gastos-con-factura">
    <header class="flex-entre fila--envuelto" style="padding:1rem 1rem 0;gap:.5rem">
        <div>
            <h2 class="titulo-5" id="gastos-con-factura">Tus gastos de <?= escaparValores($mesTexto) ?></h2>
            <p class="texto-sm texto-secundario">Lo que gastaste y <strong>sí tiene factura</strong>. Estos gastos se restan de tus impuestos.</p>
        </div>
        <a class="btn btn--texto btn--sm" href="<?= escaparValores($enlaceGastos) ?>">Ver todos los gastos →</a>
    </header>
    <?php if (!$gastosConFactura) : ?>
        <p class="texto-secundario" style="padding:1rem">Este mes no hay gastos con factura. Usa «Anotar un gasto» para registrar uno.</p>
    <?php else : ?>
        <?php $tablaGastos($gastosConFactura, 'Total de gastos con factura', true); ?>
    <?php endif; ?>
</section>

<section class="tarjeta tarjeta--sin-relleno mb-lg" aria-labelledby="gastos-sin-factura">
    <header style="padding:1rem 1rem 0">
        <h2 class="titulo-5" id="gastos-sin-factura">Gastos sin factura <span class="badge badge--neutro badge--sin-punto">Solo informativo</span></h2>
        <p class="texto-sm texto-secundario">Gastos que no te dieron factura, por eso no llevan IVA y <strong>no se pueden restar de tus impuestos</strong>.
            Se muestran para que sepas cuánto dinero salió. Si después te dan la factura, ábrelo y adjúntala para que cuente.</p>
    </header>
    <?php if (!$gastosSinFactura) : ?>
        <p class="texto-secundario" style="padding:1rem">Este mes todos tus gastos tienen factura.</p>
    <?php else : ?>
        <?php $tablaGastos($gastosSinFactura, 'Total de gastos sin factura', false); ?>
    <?php endif; ?>
</section>

<?php
$enlaceSinFactura = '/admin/contabilidad/ingresos?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'empresa' => $periodo['empresa'], 'facturacion' => 'no_facturada']);
?>
<section class="tarjeta mb-lg" aria-label="Ventas sin factura">
    <div style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap">
        <div>
            <h2 class="titulo-5">Ventas sin factura</h2>
            <p class="campo__ayuda">Solo para tu información: no cuentan en «Lo que vendiste» ni en tus impuestos.</p>
        </div>
        <div style="text-align:right">
            <strong style="font-size:1.5rem"><?= formatoMoneda($sinFactura['total']) ?></strong><br>
            <span class="texto-secundario texto-xs"><?= number_format($sinFactura['operaciones']) ?> venta(s) en <?= nombreMes($periodo['mes']) ?> <?= $periodo['anio'] ?></span>
        </div>
    </div>
    <?php if ($sinFactura['operaciones'] > 0) : ?>
        <div class="mt-md"><a class="btn btn--secundario btn--sm" href="<?= escaparValores($enlaceSinFactura) ?>">Ver estas ventas</a></div>
    <?php endif; ?>
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
        <?php foreach (['facturada' => 'Ventas facturadas', 'no_facturada' => 'Ventas no facturadas', 'sin_clasificar' => 'Pendiente de facturar'] as $clave => $etiqueta) : ?>
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

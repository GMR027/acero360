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

$cierreUrl = '/admin/contabilidad/cierre?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]);
$accionesContabilidad = $cerrado
    ? '<span class="badge badge--neutro">Mes cerrado</span>'
    : '<a class="btn btn--primario" href="/admin/contabilidad/gastos/crear">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">add</span>'
        . '<span>Anotar un gasto</span></a>'
        . '<a class="btn btn--secundario" href="' . escaparValores($cierreUrl) . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">lock</span>'
        . '<span>Cerrar el mes</span></a>';

template('header', [
    'titulo'     => 'Contabilidad',
    'subtitulo'  => nombreMes($periodo['mes']) . ' ' . $periodo['anio'],
    'rutaActiva' => '/admin/contabilidad',
    'acciones'   => $accionesContabilidad,
]);
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
// Lo que la estimación deja fuera: facturas sin pagar o en parcialidades y gastos por pagar.
$fueraImp = [];
if ((int) ($imp['facturas_sin_pagar_n'] ?? 0) > 0) {
    $fueraImp[] = number_format((int) $imp['facturas_sin_pagar_n']) . ' factura(s) sin cobrar por completo';
}
if ((int) ($imp['gastos_no_pagados_n'] ?? 0) > 0) {
    $fueraImp[] = number_format((int) $imp['gastos_no_pagados_n']) . ' gasto(s) por pagar';
}
$pieImpuesto = 'IVA ' . $conSigno($imp['iva_por_pagar']) . ' + ISR ' . $conSigno($imp['isr_por_pagar']) . ' (estimación con catálogos versionados)'
    . ($fueraImp ? ' · fuera: ' . implode(' y ', $fueraImp) : '');
if (isset($imp['habilitado']) && !$imp['habilitado']) {
    $pieImpuesto = 'Cálculo fiscal deshabilitado para este periodo (sin catálogos activos ni configuración): los importes NO se calculan, no se inventan cifras.';
}
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
$enlaceIngresos = '/admin/contabilidad/ingresos?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'empresa' => $periodo['empresa']]);
$enlaceImpuestos = '/admin/fiscal?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]);
?>
<?php if ($cerrado) : ?>
    <p class="nota nota--advertencia mb-lg" role="note"><span class="material-symbols-outlined nota__icono" aria-hidden="true">lock</span>
        <span><strong>Este mes ya está cerrado.</strong> No se pueden cambiar sus ventas ni gastos.</span></p>
<?php endif; ?>

<?php /* FASE 9: compras con su factura XML adjunta pero SIN gasto en contabilidad
         (pendiente del diagnóstico de la FASE 1): su importe no aparece en gastos ni
         en impuestos hasta que se envíen a contabilidad. */
$comprasSinGasto = (int) $db->query(
    "SELECT COUNT(*) FROM ordenes_compra o
      WHERE o.estado <> 'cancelada' AND o.estado_cfdi = 'con_cfdi'
        AND YEAR(o.fecha_solicitud) = " . (int) $periodo['anio'] . "
        AND (o.gasto_id IS NULL OR NOT EXISTS (SELECT 1 FROM gastos g WHERE g.id = o.gasto_id AND g.estado = 'vigente'))"
)->fetch_row()[0]; ?>
<?php if ($comprasSinGasto > 0) : ?>
    <p class="nota nota--advertencia mb-lg" role="status">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">report</span>
        <span><strong><?= $comprasSinGasto ?> compra(s) de <?= (int) $periodo['anio'] ?> tienen su factura XML adjunta pero
            todavía no tienen gasto en contabilidad</strong>: su importe no aparece en los gastos ni en el cálculo de
            impuestos. Revísalas en
            <a href="/admin/compras?<?= http_build_query(['anio' => $periodo['anio']]) ?>">Compras</a> y ábreles su gasto.</span></p>
<?php endif; ?>

<?php
$tarjetasKpi = [
    [
        'etiqueta' => 'Lo que vendiste',
        'valor'    => formatoMoneda($entra),
        'pie'      => number_format($operacionesFacturables) . ' ventas con factura · ya cobraste ' . formatoMoneda($r['cobrado_en_periodo']),
        'variacion' => \App\Contabilidad::variacion($entra, $entraAnt),
        'subeEsBueno' => true,
        'enlace'   => $enlaceIngresos,
        'icono'    => 'trending_up',
    ],
    [
        'etiqueta' => 'Lo que gastaste',
        'valor'    => formatoMoneda($sale),
        'pie'      => number_format($g['operaciones']) . ' gastos · ya pagaste ' . formatoMoneda($gasto($g['pagado'])),
        'variacion' => \App\Contabilidad::variacion($sale, $saleAnt),
        'subeEsBueno' => false,
        'enlace'   => $enlaceGastos,
        'icono'    => 'receipt_long',
    ],
    [
        'etiqueta' => $impAPagar >= 0 ? 'Impuestos que debes pagar' : 'Impuestos a tu favor',
        'valor'    => formatoMoneda(abs($impAPagar)),
        'pie'      => $pieImpuesto,
        'variacion' => \App\Contabilidad::variacion($impAPagar, $impAnt),
        'subeEsBueno' => false,
        'enlace'   => $enlaceImpuestos,
        'icono'    => 'account_balance',
    ],
];
$etiquetaComparativa = 'vs. ' . nombreMes($mesAnt) . ' ' . $anioAnt;
$exportarContabilidad = '/admin/fiscal/exportar?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'tipo' => 'movimientos']);
$accionesInteligentes = [
    [
        'destino' => 'venta',
        'texto'   => 'Registro Venta Inteligente',
        'icono'   => 'receipt_long',
        'clase'   => 'btn--exito',
        'ayuda'   => 'Sube la factura (XML, PDF o foto) que emitiste y el formulario de venta se llena solo con el cliente, las fechas y los renglones que sí se reconocen',
    ],
    [
        'destino' => 'gasto',
        'texto'   => 'Registro Compra Inteligente',
        'icono'   => 'shopping_cart',
        'clase'   => 'btn--rojo',
        'ayuda'   => 'Sube la factura (XML, PDF o foto) que te dieron y el formulario de gasto se llena solo con el proveedor, el total, el folio fiscal y los renglones',
    ],
];
$ocrDisponible = \App\LectorDocumental::ocrDisponible();
?>

<?php
/** Tabla de gastos del mes; $conIva = false oculta la columna de IVA (gastos sin factura no llevan IVA acreditable). */
$tablaGastos = static function (array $lista, string $pie, bool $conIva): void { ?>
    <div class="tabla-contenedor"><table class="tabla tabla--gastos">
        <caption class="solo-lectores"><?= escaparValores($pie) ?></caption>
        <thead><tr><th scope="col">Fecha</th><th scope="col">En qué / a quién</th><th scope="col">Categoría</th>
            <?php if ($conIva) : ?><th scope="col" class="tabla--numerica">IVA</th><?php endif; ?>
            <th scope="col" class="tabla--numerica">Total</th><th scope="col" class="tabla--centro">¿Ya lo pagaste?</th><th scope="col"><span class="solo-lectores">Acciones</span></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $x) : ?>
            <tr>
                <td class="gasto-fecha"><?= date('d/m/Y', strtotime($x['fecha'])) ?></td>
                <td class="gasto-beneficiario"><strong><?= escaparValores($x['beneficiario']) ?></strong><span class="texto-xs texto-secundario"><br><?= escaparValores($x['concepto']) ?></span></td>
                <td class="gasto-categoria"><?= escaparValores((string) $x['categoria_nombre']) ?: '—' ?></td>
                <?php if ($conIva) : ?><td class="gasto-iva tabla--numerica cifra"><?= formatoMoneda($x['iva']) ?></td><?php endif; ?>
                <td class="gasto-total tabla--numerica cifra"><strong><?= formatoMoneda($x['total']) ?></strong></td>
                <td class="gasto-pago tabla--centro"><?php
                    $pagadoCentFila = (int) ($x['pagado_centavos'] ?? 0);
                    if ((string) $x['estado_pago'] === 'pagado') {
                        echo '<span class="badge badge--aprobado">Sí, pagado</span>';
                    } elseif ($pagadoCentFila > 0) {
                        echo '<span class="badge badge--pendiente">Pago parcial</span>';
                    } else {
                        echo '<span class="badge badge--pendiente">Falta pagar</span>';
                    }
                ?></td>
                <td class="gasto-acciones tabla__acciones">
                    <?php
                    $saldoX = (float) $x['total'] - ((int) ($x['pagado_centavos'] ?? 0) / 100);
                    $puedePagarX = (string) $x['estado_pago'] !== 'pagado' && $saldoX > 0 && (int) ($x['de_compra'] ?? 0) === 0;
                    ?>
                    <?php if ($puedePagarX) : ?>
                        <a class="btn btn--primario btn--sm" href="/admin/contabilidad/gastos/pagar?id=<?= (int) $x['id'] ?>">
                            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">payments</span>
                            <span>Pagar</span>
                        </a>
                    <?php endif; ?>
                    <a class="btn btn--texto btn--sm" href="/admin/contabilidad/gastos/detalle?id=<?= (int) $x['id'] ?>">Abrir</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="<?= $conIva ? 4 : 3 ?>"><strong><span class="oculto-en-movil"><?= escaparValores($pie) ?></span><span class="solo-movil">Total</span></strong></td>
            <td class="tabla--numerica cifra"><strong><?= formatoMoneda(array_sum(array_map(static fn ($x) => (float) $x['total'], $lista))) ?></strong></td><td colspan="2"></td></tr></tfoot>
    </table></div>
<?php };
?>

<?php
$enlaceSinFactura = '/admin/contabilidad/ingresos?' . http_build_query([
    'anio' => $periodo['anio'], 'mes' => $periodo['mes'], 'empresa' => $periodo['empresa'], 'facturacion' => 'no_facturada',
]);
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
    $avisos[] = ['attach_file', $pend['gastos_sin_factura'] . ' gasto(s) sin factura (informativo: la estimación resta los pagados; el CFDI es el respaldo para tu contador)', '/admin/contabilidad/gastos?anio=' . $periodo['anio'] . '&mes=' . $periodo['mes'], 'Ver gastos'];
}
?>

<section class="fin-kpis" aria-label="Resumen del periodo">
    <?php foreach ($tarjetasKpi as $t) :
        $v = $t['variacion'] ?? null;
        $bueno = $v === null ? null : (($v >= 0) === ($t['subeEsBueno'] ?? true));
        $enlace = trim((string) ($t['enlace'] ?? ''));
        $tag = $enlace !== '' ? 'a' : 'div'; ?>
        <<?= $tag ?> class="fin-kpi"<?= $enlace !== '' ? ' href="' . escaparValores($enlace) . '"' : '' ?>>
            <span class="fin-kpi__cabecera">
                <span class="fin-kpi__icono" aria-hidden="true">
                    <span class="material-symbols-outlined"><?= escaparValores((string) ($t['icono'] ?? 'insights')) ?></span>
                </span>
                <span class="fin-kpi__etiqueta"><?= escaparValores((string) $t['etiqueta']) ?></span>
            </span>
            <strong class="fin-kpi__valor cifra"><?= escaparValores((string) $t['valor']) ?></strong>
            <span class="fin-kpi__pie" title="<?= escaparValores((string) ($t['pie'] ?? '')) ?>"><?= escaparValores((string) ($t['pie'] ?? '')) ?></span>
            <?php if ($etiquetaComparativa !== '') : ?>
                <span class="fin-kpi__comparativa">
                    <?php if ($v === null) : ?>
                        <span class="texto-secundario">Sin comparación <?= escaparValores($etiquetaComparativa) ?></span>
                    <?php elseif ((float) $v === 0.0) : ?>
                        <span class="badge badge--neutro badge--sin-punto">Sin cambio</span>
                        <span class="texto-secundario"><?= escaparValores($etiquetaComparativa) ?></span>
                    <?php else : ?>
                        <span class="badge <?= $bueno ? 'badge--aprobado' : 'badge--pendiente' ?> badge--sin-punto cifra">
                            <?= $v > 0 ? '▲ +' : '▼ −' ?><?= number_format(abs((float) $v), 1) ?> %
                        </span>
                        <span class="texto-secundario"><?= escaparValores($etiquetaComparativa) ?></span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>

<div class="fin-acciones" data-ocr="<?= $ocrDisponible ? '1' : '0' ?>">
    <div class="fin-acciones__grupo">
        <?php foreach ($accionesInteligentes as $accion) : ?>
            <button class="btn <?= escaparValores((string) $accion['clase']) ?>" type="button"
                    data-inteligente="<?= escaparValores((string) $accion['destino']) ?>"
                    title="<?= escaparValores((string) $accion['ayuda']) ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem"><?= escaparValores((string) $accion['icono']) ?></span>
                <span><?= escaparValores((string) $accion['texto']) ?></span>
            </button>
        <?php endforeach; ?>
        <input type="hidden" data-inteligente-token value="<?= tokenFormulario() ?>">
    </div>

    <div class="fin-acciones__grupo fin-acciones__grupo--exportar">
        <span class="fin-acciones__etiqueta">Exportar</span>
        <?php foreach (['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'] as $formato => $texto) : ?>
            <a class="btn btn--secundario btn--sm" href="<?= escaparValores($exportarContabilidad . '&formato=' . $formato) ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">download</span>
                <span><?= escaparValores($texto) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="fin-grid">
    <div class="fin-columna">
        <section class="tarjeta fin-card fin-card--tabla" aria-labelledby="gastos-con-factura">
            <div class="fin-card__cabecera">
                <div class="fin-card__texto">
                    <h2 class="fin-card__titulo" id="gastos-con-factura">
                        <span class="fin-card__icono" aria-hidden="true"><span class="material-symbols-outlined">receipt_long</span></span>
                        Tus gastos de <?= escaparValores($mesTexto) ?>
                    </h2>
                    <p class="fin-card__sub">Lo que gastaste y <strong>sí tiene factura</strong>. Estos gastos se restan de tus impuestos.</p>
                </div>
                <a class="btn btn--texto btn--sm" href="<?= escaparValores($enlaceGastos) ?>">
                    <span>Ver todos los gastos</span>
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </a>
            </div>
            <?php if (!$gastosConFactura) : ?>
                <div class="fin-vacio fin-vacio--ok">
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <p>Este mes no hay gastos con factura.</p>
                    <a class="btn btn--secundario btn--sm" href="/admin/contabilidad/gastos/crear">Anotar un gasto</a>
                </div>
            <?php else : ?>
                <?php $tablaGastos($gastosConFactura, 'Total de gastos con factura', true); ?>
            <?php endif; ?>
        </section>

        <section class="tarjeta fin-card fin-card--tabla" aria-labelledby="gastos-sin-factura">
            <div class="fin-card__cabecera">
                <div class="fin-card__texto">
                    <h2 class="fin-card__titulo" id="gastos-sin-factura">
                        <span class="fin-card__icono" aria-hidden="true"><span class="material-symbols-outlined">attach_file</span></span>
                        Gastos sin factura
                        <span class="badge badge--neutro badge--sin-punto">Solo informativo</span>
                    </h2>
                    <p class="fin-card__sub">No llevan IVA y no se restan de tus impuestos. Si después te dan la factura, ábrelo y adjúntala.</p>
                </div>
            </div>
            <?php if (!$gastosSinFactura) : ?>
                <div class="fin-vacio fin-vacio--ok">
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <p>Este mes todos tus gastos tienen factura.</p>
                </div>
            <?php else : ?>
                <?php $tablaGastos($gastosSinFactura, 'Total de gastos sin factura', false); ?>
            <?php endif; ?>
        </section>
    </div>

    <aside class="fin-lateral">
        <section class="tarjeta fin-card" aria-labelledby="titulo-pendientes">
            <div class="fin-card__cabecera">
                <div class="fin-card__texto">
                    <h2 class="fin-card__titulo" id="titulo-pendientes">
                        <span class="fin-card__icono fin-card__icono--alerta" aria-hidden="true"><span class="material-symbols-outlined">task_alt</span></span>
                        Lo que falta hacer
                    </h2>
                    <p class="fin-card__sub">Lo que pide una acción este mes.</p>
                </div>
            </div>
            <?php if (!$avisos) : ?>
                <div class="fin-vacio fin-vacio--ok">
                    <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                    <p>Todo al día este mes.</p>
                </div>
            <?php else : ?>
                <ul class="fin-tareas">
                    <?php foreach ($avisos as [$icono, $texto, $enlace, $boton]) : ?>
                        <li class="fin-tarea">
                            <span class="fin-tarea__icono" aria-hidden="true"><span class="material-symbols-outlined"><?= escaparValores($icono) ?></span></span>
                            <span class="fin-tarea__texto"><?= escaparValores($texto) ?></span>
                            <a class="btn btn--secundario btn--sm" href="<?= escaparValores($enlace) ?>"><?= escaparValores($boton) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <div class="fin-tareas__acciones">
                <a class="btn btn--secundario btn--sm" href="/admin/ventas/crear">
                    <span class="material-symbols-outlined" aria-hidden="true">point_of_sale</span>
                    <span>Anotar una venta</span>
                </a>
                <a class="btn btn--texto btn--sm" href="<?= escaparValores($enlaceImpuestos) ?>">
                    <span class="material-symbols-outlined" aria-hidden="true">request_quote</span>
                    <span>¿Cuánto separo para impuestos?</span>
                </a>
            </div>
        </section>

        <section class="tarjeta fin-card fin-card--ventas" aria-label="Ventas sin factura">
            <div class="fin-card__cabecera">
                <div class="fin-card__texto">
                    <h2 class="fin-card__titulo">
                        <span class="fin-card__icono" aria-hidden="true"><span class="material-symbols-outlined">receipt</span></span>
                        Ventas sin factura
                    </h2>
                    <p class="fin-card__sub">Solo informativo: no cuentan en «Lo que vendiste» ni en tus impuestos.</p>
                </div>
            </div>
            <div class="fin-ventas-sin">
                <strong class="cifra"><?= formatoMoneda($sinFactura['total']) ?></strong>
                <span><?= number_format($sinFactura['operaciones']) ?> venta(s) en <?= escaparValores($mesTexto) ?></span>
            </div>
            <?php if ($sinFactura['operaciones'] > 0) : ?>
                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($enlaceSinFactura) ?>">Ver estas ventas</a>
            <?php endif; ?>
        </section>

        <section class="tarjeta fin-card" aria-label="Facturas del mes para tu contador">
            <div class="fin-card__cabecera">
                <div class="fin-card__texto">
                    <h2 class="fin-card__titulo">
                        <span class="fin-card__icono" aria-hidden="true"><span class="material-symbols-outlined">folder_zip</span></span>
                        Facturas del mes
                    </h2>
                    <p class="fin-card__sub">Descarga en ZIP todos los archivos que subiste este mes.</p>
                </div>
            </div>
            <div class="fin-zip">
                <a class="btn btn--secundario btn--sm" href="/admin/contabilidad/zip?tipo=ventas&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
                    <span class="material-symbols-outlined" aria-hidden="true">folder_zip</span>
                    <span>Documentos de ventas (ZIP)</span>
                </a>
                <a class="btn btn--secundario btn--sm" href="/admin/contabilidad/zip?tipo=gastos&anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">
                    <span class="material-symbols-outlined" aria-hidden="true">folder_zip</span>
                    <span>Documentos de gastos (ZIP)</span>
                </a>
            </div>
        </section>
    </aside>
</div>

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



<?php template('footer'); ?>

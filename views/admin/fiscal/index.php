<?php
/**
 * views/admin/fiscal/index.php — Resumen fiscal mensual del contribuyente (ambos negocios).
 *
 * Tres bloques que NO deben confundirse:
 *   1. Importes REGISTRADOS (ventas y gastos tal como se capturaron)
 *   2. ESTIMACIÓN fiscal (motor, flujo de efectivo, con las reglas activas)
 *   3. Pagos REALES declarados y pagados
 * Solo lectura (guardar un cálculo no cambia ninguna cifra).
 */

soloAdmin();

$periodo  = periodoSeleccionado();
$empresas = \App\Empresas::mostrarTodos(100, 0);
$contrib  = \App\Contabilidad::contribuyentePrincipal();
$cerrado  = \App\Contabilidad::periodoCerrado($contrib, sprintf('%04d-%02d-01', $periodo['anio'], $periodo['mes']));
$reg      = \App\Contabilidad::resumenPeriodo($periodo['anio'], $periodo['mes'], $periodo['empresa']);
$f        = \App\MotorFiscal::calcular($contrib, $periodo['anio'], $periodo['mes']);
$m        = static fn ($centavos): string => $centavos === null ? '—' : formatoMoneda($centavos / 100);
$gastos   = $reg['gastos'];

template('header', ['titulo' => 'Resumen fiscal', 'subtitulo' => nombreMes($periodo['mes']) . ' ' . $periodo['anio'], 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'panel', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/fiscal', 'periodo' => $periodo, 'empresas' => $empresas]);
?>

<p class="nota nota--advertencia mb-lg" role="note">
    <span class="material-symbols-outlined nota__icono" aria-hidden="true">gavel</span>
    <span><strong>Estimación administrativa.</strong> Los importes estimados se calculan con las reglas instaladas y deben validarse con tu contador antes de presentar declaraciones.
        El cálculo fiscal es del <strong>contribuyente</strong> (ambos negocios juntos); el filtro de negocio solo afecta el bloque de importes registrados.
        <?= $cerrado ? '<strong>Periodo cerrado.</strong>' : '' ?></span>
</p>

<?php foreach ($f['avisos'] as $aviso) : ?>
    <p class="nota nota--advertencia mb-md" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span><span><?= escaparValores($aviso) ?></span></p>
<?php endforeach; ?>

<h2 class="titulo-5 mb-md">1 · Importes registrados <span class="badge badge--neutro">tal como se capturaron</span></h2>
<section class="rejilla-stats mb-lg" aria-label="Importes registrados">
    <div class="stat"><span class="stat__etiqueta">Ventas del periodo</span><span class="stat__valor"><?= number_format($reg['ventas']['operaciones']) ?></span><span class="stat__pie">operaciones</span></div>
    <div class="stat"><span class="stat__etiqueta">Facturadas</span><span class="stat__valor"><?= formatoMoneda($reg['facturacion']['facturada']['total']) ?></span><span class="stat__pie"><?= (int) $reg['facturacion']['facturada']['operaciones'] ?> operaciones</span></div>
    <div class="stat"><span class="stat__etiqueta">Sin facturar</span>
        <span class="stat__valor"><?= formatoMoneda($reg['facturacion']['no_facturada']['total'] + $reg['facturacion']['sin_clasificar']['total']) ?></span>
        <span class="stat__pie">no facturadas + sin clasificar</span></div>
    <div class="stat"><span class="stat__etiqueta">Subtotal de operaciones</span><span class="stat__valor"><?= formatoMoneda($reg['ventas']['subtotal']) ?></span><span class="stat__pie">antes de IVA</span></div>
    <div class="stat"><span class="stat__etiqueta">IVA trasladado registrado</span><span class="stat__valor"><?= formatoMoneda($reg['ventas']['iva']) ?></span><span class="stat__pie">de las ventas del mes, cobradas o no</span></div>
    <div class="stat"><span class="stat__etiqueta">Gastos y compras</span><span class="stat__valor"><?= formatoMoneda($gastos['total'] / 100) ?></span><span class="stat__pie"><?= (int) $gastos['operaciones'] ?> gastos</span></div>
    <div class="stat"><span class="stat__etiqueta">IVA potencialmente acreditable</span><span class="stat__valor"><?= formatoMoneda($gastos['iva'] / 100) ?></span><span class="stat__pie">no todo será acreditable</span></div>
</section>

<h2 class="titulo-5 mb-md">2 · Estimación fiscal <span class="badge badge--info">flujo de efectivo</span></h2>
<?php if (!$f['habilitado']) : ?>
    <p class="nota nota--advertencia mb-lg"><span>El cálculo no está disponible. Revisa los avisos de arriba.</span></p>
<?php else : ?>
    <section class="tarjeta tarjeta--sin-relleno mb-lg">
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption>IVA del mes (pago definitivo mensual)</caption>
                <tbody>
                    <tr><th scope="row">IVA trasladado en lo efectivamente cobrado</th><td class="tabla--numerica cifra"><?= $m($f['iva']['trasladado_cobrado']) ?></td></tr>
                    <tr><th scope="row">− IVA acreditable de gastos pagados (que cumplen las reglas)</th><td class="tabla--numerica cifra"><?= $m($f['iva']['acreditable_considerado']) ?></td></tr>
                    <tr><th scope="row">− IVA retenido</th><td class="tabla--numerica cifra"><?= $m($f['iva']['retenciones']) ?></td></tr>
                    <tr><th scope="row">− Saldo a favor anterior y otros ajustes</th><td class="tabla--numerica cifra"><?= $m($f['iva']['saldo_favor_anterior'] + $f['iva']['otros_ajustes']) ?></td></tr>
                    <tr><th scope="row"><strong>IVA estimado a cargo</strong></th><td class="tabla--numerica cifra"><strong><?= $m($f['iva']['a_cargo']) ?></strong></td></tr>
                    <tr><th scope="row"><strong>Saldo a favor estimado</strong></th><td class="tabla--numerica cifra"><strong><?= $m($f['iva']['saldo_a_favor']) ?></strong></td></tr>
                    <tr><th scope="row" class="texto-secundario">Referencia: IVA de gastos pagados en el mes, aún con revisión pendiente</th><td class="tabla--numerica cifra texto-secundario"><?= $m($f['iva']['acreditable_potencial']) ?></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="tarjeta tarjeta--sin-relleno mb-lg">
        <div class="tabla-contenedor">
            <table class="tabla">
                <caption>ISR · pago provisional acumulado de enero a <?= escaparValores(nombreMes($periodo['mes'])) ?> (art. 106 LISR)</caption>
                <tbody>
                    <tr><th scope="row">Ingresos acumulados del ejercicio (cobrados, sin IVA)</th><td class="tabla--numerica cifra"><?= $m($f['isr']['ingresos_acumulados']) ?></td></tr>
                    <tr><th scope="row">− Deducciones registradas (pagadas, validadas y con comprobante)</th><td class="tabla--numerica cifra"><?= $m($f['isr']['deducciones_acumuladas']) ?></td></tr>
                    <tr><th scope="row">− PTU pagada y pérdidas por amortizar</th><td class="tabla--numerica cifra"><?= $m($f['isr']['ptu_pagada'] + $f['isr']['perdidas_amortizadas']) ?></td></tr>
                    <tr><th scope="row"><strong>Base gravable (utilidad fiscal del periodo)</strong></th><td class="tabla--numerica cifra"><strong><?= $m($f['isr']['utilidad_fiscal']) ?></strong></td></tr>
                    <tr><th scope="row">ISR determinado con la tarifa de <?= escaparValores(nombreMes($periodo['mes'])) ?></th><td class="tabla--numerica cifra"><?= $m($f['isr']['impuesto_determinado']) ?></td></tr>
                    <tr><th scope="row">− Retenciones de ISR</th><td class="tabla--numerica cifra"><?= $m($f['isr']['retenciones_acumuladas']) ?></td></tr>
                    <tr><th scope="row">− Pagos provisionales anteriores</th><td class="tabla--numerica cifra"><?= $m($f['isr']['pagos_provisionales_anteriores']) ?></td></tr>
                    <tr><th scope="row">− Otros acreditamientos</th><td class="tabla--numerica cifra"><?= $m($f['isr']['otros_acreditamientos']) ?></td></tr>
                    <tr><th scope="row"><strong>ISR provisional estimado a pagar</strong></th><td class="tabla--numerica cifra"><strong><?= $m($f['isr']['a_pagar']) ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <p class="campo__ayuda mb-lg">Reglas usadas:
        <?php foreach (['isr' => 'ISR', 'iva' => 'IVA', 'reglas' => 'Criterios'] as $k => $n) : ?>
            <?= $n ?> <?= $f['versiones'][$k] ? '<a href="/admin/fiscal/catalogo/detalle?id=' . (int) $f['versiones'][$k] . '">v#' . (int) $f['versiones'][$k] . '</a>' : '—' ?> ·
        <?php endforeach; ?>
        base: <?= escaparValores($f['configuracion']['base'] ?? '') ?> · régimen: <?= escaparValores($f['configuracion']['regimen'] ?? '') ?>.</p>
<?php endif; ?>

<h2 class="titulo-5 mb-md">3 · Pagos reales registrados <span class="badge badge--aprobado">declarado y pagado</span></h2>
<section class="rejilla-stats mb-lg" aria-label="Pagos reales">
    <?php foreach (['isr' => 'ISR', 'iva' => 'IVA'] as $k => $n) : $real = $f['pagos_reales'][$k] ?? null; ?>
        <div class="stat"><span class="stat__etiqueta"><?= $n ?> declarado</span>
            <span class="stat__valor"><?= $real ? $m($real['declarado'] - ($real['saldo_favor'] ?? 0)) : 'Sin registrar' ?></span>
            <span class="stat__pie"><?= $real ? 'Pagado ' . $m($real['pagado']) : 'Regístralo en «Retenciones, ajustes y pagos»' ?></span></div>
        <div class="stat"><span class="stat__etiqueta">Diferencia <?= $n ?> (estimado − declarado)</span>
            <span class="stat__valor"><?= isset($f['comparacion'][$k]) ? $m($f['comparacion'][$k]) : '—' ?></span>
            <span class="stat__pie">Pendiente de pago: <?= $m($f['pendiente_de_pago'][$k] ?? null) ?></span></div>
    <?php endforeach; ?>
</section>

<div class="encabezado-pagina__acciones">
    <form action="/admin/fiscal/guardar" method="post">
        <input type="hidden" name="anio" value="<?= $periodo['anio'] ?>"><input type="hidden" name="mes" value="<?= $periodo['mes'] ?>">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <button class="btn btn--secundario" type="submit"<?= $f['habilitado'] ? '' : ' disabled' ?>>Guardar este cálculo</button>
    </form>
    <a class="btn btn--primario" href="/admin/contabilidad/cierre?anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">Revisión y cierre del mes</a>
</div>

<?php template('footer'); ?>

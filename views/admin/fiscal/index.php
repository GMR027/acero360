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

template('header', ['titulo' => 'Impuestos del mes', 'subtitulo' => nombreMes($periodo['mes']) . ' ' . $periodo['anio'], 'rutaActiva' => '/admin/fiscal']);
template('tabsFiscal', ['tab' => 'panel', 'periodo' => $periodo]);
template('selectorPeriodo', ['accion' => '/admin/fiscal', 'periodo' => $periodo, 'empresas' => $empresas]);
?>

<?php
$ivaPagar = $f['habilitado'] ? (int) ($f['iva']['a_cargo'] ?? 0) : 0;
$isrPagar = $f['habilitado'] ? (int) ($f['isr']['a_pagar'] ?? 0) : 0;
?>
<?php if ($f['habilitado']) : ?>
    <section class="tarjeta mb-lg" aria-label="Cuánto separar">
        <p class="texto-secundario">Para <?= escaparValores(nombreMes($periodo['mes'])) ?> <?= (int) $periodo['anio'] ?>, aproximadamente, deberías separar</p>
        <p class="titulo-2" style="margin:.25rem 0"><?= $m($ivaPagar + $isrPagar) ?></p>
        <ul class="lista-simple mt-md">
            <li class="lista-simple__elemento"><strong>IVA:</strong> <?= $m($ivaPagar) ?>
                <span class="texto-secundario">— el IVA que cobraste menos el IVA de tus gastos con factura.</span></li>
            <li class="lista-simple__elemento"><strong>ISR (impuesto sobre tu ganancia):</strong> <?= $m($isrPagar) ?>
                <span class="texto-secundario">— pago provisional de lo que va del año.</span></li>
        </ul>
        <p class="campo__ayuda mt-md">Es un cálculo aproximado con lo que has anotado. Cuenta solo lo que <strong>ya te pagaron</strong> y los gastos <strong>ya pagados y con factura</strong>.
            Se paga a más tardar el día 17 del mes siguiente. Confírmalo con tu contador.<?= $cerrado ? ' <strong>Mes cerrado.</strong>' : '' ?></p>
        <?php if (!empty($f['pendiente_de_pago']) && ($f['pagos_reales']['isr'] ?? $f['pagos_reales']['iva'] ?? null)) : ?>
            <p class="campo__ayuda">Ya registraste pagos al SAT de este mes; abajo ves la comparación.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php foreach ($f['avisos'] as $aviso) : ?>
    <p class="nota nota--advertencia mb-md" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span><span><?= escaparValores($aviso) ?></span></p>
<?php endforeach; ?>

<?php if ($reg['facturacion']['sin_clasificar']['operaciones'] > 0 || $gastos['sin_comprobante'] > 0) : ?>
    <p class="nota nota--advertencia mb-lg" role="status"><span class="material-symbols-outlined nota__icono" aria-hidden="true">lightbulb</span>
        <span>Para que el cálculo sea más justo: <?= (int) $gastos['sin_comprobante'] ?> gasto(s) no tienen factura (no se restan) y
            <?= (int) $reg['facturacion']['sin_clasificar']['operaciones'] ?> venta(s) no indican si se facturaron.
            <a href="/admin/contabilidad">Revisar en Contabilidad</a>.</span></p>
<?php endif; ?>

<details class="mb-lg"><summary class="boton-enlace" style="cursor:pointer"><strong>Ver cómo se calculó</strong> (para tu contador)</summary>
<div class="mt-md">
<p class="nota nota--advertencia mb-lg" role="note">
    <span class="material-symbols-outlined nota__icono" aria-hidden="true">gavel</span>
    <span><strong>Estimación administrativa.</strong> El cálculo es del <strong>contribuyente</strong> (todos tus negocios juntos).</span>
</p>

<h2 class="titulo-5 mb-md">1 · Lo que has anotado</h2>
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

<h2 class="titulo-5 mb-md">2 · El cálculo <span class="badge badge--info">solo lo cobrado y pagado</span></h2>
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

<h2 class="titulo-5 mb-md">3 · Lo que ya declaraste y pagaste al SAT</h2>
<section class="rejilla-stats mb-lg" aria-label="Pagos reales">
    <?php foreach (['isr' => 'ISR', 'iva' => 'IVA'] as $k => $n) : $real = $f['pagos_reales'][$k] ?? null; ?>
        <div class="stat"><span class="stat__etiqueta"><?= $n ?> declarado</span>
            <span class="stat__valor"><?= $real ? $m($real['declarado'] - ($real['saldo_favor'] ?? 0)) : 'Sin registrar' ?></span>
            <span class="stat__pie"><?= $real ? 'Pagado ' . $m($real['pagado']) : 'Anótalo en «Pagos al SAT y ajustes»' ?></span></div>
        <div class="stat"><span class="stat__etiqueta">Diferencia <?= $n ?> (estimado − declarado)</span>
            <span class="stat__valor"><?= isset($f['comparacion'][$k]) ? $m($f['comparacion'][$k]) : '—' ?></span>
            <span class="stat__pie">Pendiente de pago: <?= $m($f['pendiente_de_pago'][$k] ?? null) ?></span></div>
    <?php endforeach; ?>
</section>

</div></details>

<div class="encabezado-pagina__acciones">
    <form action="/admin/fiscal/guardar" method="post">
        <input type="hidden" name="anio" value="<?= $periodo['anio'] ?>"><input type="hidden" name="mes" value="<?= $periodo['mes'] ?>">
        <input type="hidden" name="token" value="<?= tokenFormulario() ?>">
        <button class="btn btn--secundario" type="submit"<?= $f['habilitado'] ? '' : ' disabled' ?>>Guardar este cálculo del mes</button>
    </form>
    <a class="btn btn--primario" href="/admin/contabilidad/cierre?anio=<?= $periodo['anio'] ?>&mes=<?= $periodo['mes'] ?>">Revisar y cerrar el mes</a>
</div>

<?php template('footer'); ?>

<?php
/**
 * views/admin/fiscal/movimientos.php — Todas las ventas y todos los gastos de ambos negocios, con los impuestos
 * por pagar del periodo. Se puede exportar a PDF, Excel o CSV. Solo lectura.
 */

soloAdmin();

$contrib = \App\Contabilidad::contribuyentePrincipal();
$anio    = (int) ($_GET['anio'] ?? date('Y'));
$anio    = ($anio >= 2000 && $anio <= 2100) ? $anio : (int) date('Y');
$mes     = (int) ($_GET['mes'] ?? date('n'));
$mes     = ($mes >= 0 && $mes <= 12) ? $mes : (int) date('n');

$d   = \App\ResumenFiscal::movimientos($contrib, $anio, $mes);
$imp = \App\ResumenFiscal::impuestosDelPeriodo($contrib, $anio, $mes);
$m   = static fn (?float $v): string => $v === null ? '—' : ($v < 0 ? '−' : '') . formatoMoneda(abs($v));
$periodoTexto = ($mes > 0 ? nombreMes($mes) . ' ' : '') . $anio;
$consulta = http_build_query(['anio' => $anio, 'mes' => $mes]);

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

<section class="estadisticas-resumen" aria-label="Resumen del periodo">
    <div class="tarjeta"><span class="stat__etiqueta">Ventas</span>
        <span class="estadisticas-resumen__valor"><?= $m($d['totales']['ventas']) ?></span>
        <span class="stat__pie">Facturadas: <?= $m($d['totales']['ventas_facturadas']) ?></span></div>
    <div class="tarjeta"><span class="stat__etiqueta">Gastos</span>
        <span class="estadisticas-resumen__valor"><?= $m($d['totales']['gastos']) ?></span>
        <span class="stat__pie">Todos cuentan como deducibles</span></div>
    <div class="tarjeta"><span class="stat__etiqueta"><?= $imp['impuestos'] >= 0 ? 'Impuestos por pagar' : 'Saldo a tu favor' ?> · <?= escaparValores($periodoTexto) ?></span>
        <span class="estadisticas-resumen__valor"><?= $m(abs($imp['impuestos'])) ?></span>
        <span class="stat__pie">IVA <?= $m($imp['iva_por_pagar']) ?> + ISR <?= $m($imp['isr_por_pagar']) ?></span></div>
</section>

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
                <thead><tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col">Folio o concepto</th><th scope="col">Cliente o proveedor</th>
                    <th scope="col">Negocio</th><th scope="col">Detalle</th><th scope="col" class="tabla--numerica">Subtotal</th><th scope="col" class="tabla--numerica">IVA</th><th scope="col" class="tabla--numerica">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($d['filas'] as $f) : ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($f['fecha'])) ?></td>
                            <td><span class="badge <?= $f['tipo'] === 'Venta' ? 'badge--exito' : 'badge--info' ?>"><?= $f['tipo'] ?></span></td>
                            <td><?= escaparValores($f['referencia']) ?></td>
                            <td><?= escaparValores($f['contraparte']) ?></td>
                            <td><?= escaparValores($f['negocio']) ?></td>
                            <td><?= escaparValores($f['detalle']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['subtotal']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['iva']) ?></td>
                            <td class="tabla--numerica cifra"><?= formatoMoneda($f['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<p class="campo__ayuda">Los impuestos de arriba usan solo las ventas facturadas y todos los gastos (estimación). Aquí se listan todas las ventas, estén o no facturadas.</p>

<?php template('footer'); ?>

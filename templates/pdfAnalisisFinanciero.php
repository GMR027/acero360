<?php
/**
 * templates/pdfAnalisisFinanciero.php — PDF del balance financiero.
 *
 * Variables: $r (AnalisisFinanciero::resumen), $periodo, $negocios, $base,
 *            $modo, $restar_proveedores, $restar_gastos, $generado.
 *
 * Se maqueta con tablas simples porque Dompdf no interpreta grid/flex. La
 * aguja del medidor se pinta con GD en el modo simple y con el SVG vectorial
 * en el detallado. Las miniaturas se incrustan en base64 para que el PDF no
 * dependa de archivos externos.
 */

$modo = ($modo ?? 'simple') === 'detallado' ? 'detallado' : 'simple';
$mx = static fn ($v): string => '$' . number_format((float) $v, 2);
$baseTexto = $base === 'lista' ? 'Precio de lista (sin IVA)' : 'Precio de venta (con IVA)';
$baseCorta = $base === 'lista' ? 'sin IVA' : 'con IVA';
$estado = $r['balance']['estado'];
$negociosTexto = $negocios ? implode(' · ', $negocios) : 'Todos los negocios activos';
$medidor = $modo === 'detallado'
    ? \App\AnalisisFinanciero::medidor2DataUri((float) $r['balance']['angulo'])
    : \App\AnalisisFinanciero::medidorDataUri((float) $r['balance']['angulo']);
$numInventario = $modo === 'detallado' ? 3 : 1;
$numProveedores = $numInventario + 1;
$numGastos = $numProveedores + 1;
$numCategorias = $numGastos + 1;
$numNegocios = $numCategorias + 1;
$fecha = static fn ($v): string => $v ? date('d/m/Y', strtotime((string) $v)) : '';

/** Ruta absoluta de una imagen de producto, o vacío si no existe. */
$archivoAbsoluto = static function (?string $archivo): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '' || strpos($archivo, '..') !== false) {
        return '';
    }
    $ruta = dirname(__DIR__) . '/public/image/' . $archivo;

    return is_file($ruta) ? $ruta : '';
};

/** Miniatura en base64; prefiere el archivo *_mini para no inflar el PDF. */
$imagenBase64 = static function (?string $archivo) use ($archivoAbsoluto): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '') {
        return '';
    }
    $mini = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $archivo) ?? '';
    $ruta = $mini !== '' ? $archivoAbsoluto($mini) : '';
    if ($ruta === '') {
        $ruta = $archivoAbsoluto($archivo);
    }
    if ($ruta === '') {
        return '';
    }
    $datos = @file_get_contents($ruta);
    if ($datos === false) {
        return '';
    }
    $tipo = function_exists('mime_content_type') ? mime_content_type($ruta) : false;
    if (!is_string($tipo) || strpos($tipo, 'image/') !== 0) {
        $tipo = 'image/jpeg';
    }

    return 'data:' . $tipo . ';base64,' . base64_encode($datos);
};

$imagenProducto = static function (array $fila) use ($imagenBase64): string {
    $img = $imagenBase64($fila['imagen'] ?? '');

    return $img !== ''
        ? '<img class="miniatura" src="' . $img . '" alt="">'
        : '<span class="sin-foto">—</span>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Análisis Financiero</title>
    <style>
        @page { margin: 1.1cm 1cm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #4d4c53; }
        h1 { margin: 0 0 .15rem; font-size: 18px; color: #2f5f8c; }
        h2 { margin: 1rem 0 .2rem; padding: .32rem .5rem; font-size: 12px; color: #fff; background: #4679a6; }
        h2.prov { background: #8a5a00; }
        h2.gastos { background: #5c4a8a; }
        p { margin: 0 0 .35rem; }
        .meta { color: #6b6b70; font-size: 8.5px; }
        .guia { color: #6b6b70; font-size: 8px; margin: .1rem 0 .4rem; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .25rem; background: #eaf1f8; border: 1px solid #c7dcef; color: #2f5f8c; font-size: 8px; text-transform: uppercase; text-align: left; }
        td { padding: .22rem; border: 1px solid #e4e6ea; vertical-align: middle; }
        tbody tr:nth-child(even) td { background: #fafbfc; }
        tbody tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        tfoot td { font-weight: 700; background: #f0f3f7; border-top: 2px solid #c7dcef; }
        .num { text-align: right; }
        .centro { text-align: center; }
        .vacio { text-align: center; color: #6b6b70; }
        .resumen td { border: 1px solid #d9dde3; background: #f4f7fa; padding: .45rem .55rem; width: 25%; vertical-align: top; }
        .resumen small { display: block; color: #6b6b70; font-size: 8px; }
        .resumen strong { display: block; margin: .12rem 0; font-size: 14px; color: #2f5f8c; }
        .resumen .balance { background: #eaf1f8; border-color: #b9d3ea; }
        .medidor { margin: .2rem 0 .7rem; }
        .medidor td { border: 0; background: #fff !important; padding: 0 .4rem 0 0; vertical-align: middle; }
        .medidor .grafico { width: 44%; }
        .medidor img { display: block; width: 100%; max-width: 300px; }
        .medidor .texto { width: 56%; padding-left: .6rem; }
        .puntaje { margin: 0; font-size: 30px; font-weight: 700; color: #2f5f8c; line-height: 1; }
        .puntaje small { font-size: 10px; font-weight: 400; color: #6b6b70; }
        .estado { display: inline-block; margin-top: .3rem; padding: .12rem .45rem; border-radius: 999px; background: #e8f5e9; color: #1c6b3c; font-size: 8px; font-weight: 700; text-transform: uppercase; }
        .saldo { margin: .35rem 0 0; font-size: 11px; color: #4d4c53; }
        .saldo strong { font-size: 17px; color: #2f5f8c; }
        .miniatura { width: 24px; height: 24px; object-fit: cover; vertical-align: middle; margin-right: 4px; }
        .sin-foto { display: inline-block; width: 24px; text-align: center; color: #9aa1a9; }
        .producto { vertical-align: middle; }
        .stock-min { display: block; color: #6b6b70; font-size: 7px; }
        .stock-bajo, .stock-bajo .stock-min { color: #b3261e; font-weight: 700; }
        .resultado-positivo { color: #1c6b3c; }
        .resultado-negativo { color: #b3261e; }
        .variacion-bien { color: #1c6b3c; font-weight: 700; }
        .variacion-mal { color: #b3261e; font-weight: 700; }
        .diagnostico { margin: .3rem 0 .7rem; padding: .45rem .55rem; background: #fff7e0; border: 1px solid #f0d58a; }
        .diagnostico strong { display: block; margin-bottom: .2rem; color: #8a5a00; }
        .diagnostico ul { margin: 0; padding-left: 1rem; }
        .diagnostico li { margin: .12rem 0; }
        .diagnostico li.error { color: #b3261e; }
        .aviso { margin-top: .7rem; padding: .4rem; background: #fff7e0; border: 1px solid #f0d58a; font-size: 8px; }
        .pie { margin-top: .7rem; padding-top: .35rem; border-top: 1px solid #d9dde3; color: #6b6b70; font-size: 7.5px; }
    </style>
</head>
<body>
    <h1>Análisis Financiero<?= $modo === 'detallado' ? ' · Detallado' : '' ?></h1>
    <p class="meta">
        <?= escaparValores(nombreMes((int) $periodo['mes']) . ' ' . (int) $periodo['anio']) ?>
        · <?= escaparValores($negociosTexto) ?>
        · <?= escaparValores($baseTexto) ?>
        · Modo <?= escaparValores($modo === 'detallado' ? 'detallado' : 'simple') ?>
        · Generado <?= escaparValores($generado) ?>
    </p>

    <?php if ($modo === 'detallado') : ?>
        <table class="resumen">
            <tr>
                <td>
                    <small>Inventario (a favor)</small>
                    <strong><?= escaparValores($mx($r['inventario']['base'])) ?></strong>
                    <small><?= number_format((int) $r['inventario']['piezas']) ?> piezas · <?= escaparValores($baseCorta) ?></small>
                </td>
                <td>
                    <small>Ventas del mes</small>
                    <strong><?= escaparValores($mx($r['ventas']['base'])) ?></strong>
                    <small><?= number_format((int) $r['ventas']['operaciones']) ?> venta(s) · incluye <?= escaparValores($mx($r['ventas']['sin_factura_base'])) ?> sin facturar</small>
                    <small>Cobrado <?= escaparValores($mx($r['cobros']['cobrado'])) ?> · por cobrar <?= escaparValores($mx($r['cobros']['por_cobrar'])) ?> (<?= number_format((float) $r['cobros']['pct_cobrado'], 1) ?> %)</small>
                </td>
                <td>
                    <small>Gastos pagados</small>
                    <strong><?= escaparValores($mx($r['gastos_pagados']['base'])) ?></strong>
                    <small><?= number_format(count($r['gastos_pagados']['filas'])) ?> gasto(s) pagado(s) · <?= escaparValores($baseCorta) ?></small>
                </td>
                <td class="balance">
                    <small>Resultado del periodo</small>
                    <strong class="<?= (float) $r['balance']['resultado'] < 0 ? 'resultado-negativo' : 'resultado-positivo' ?>"><?= escaparValores($mx($r['balance']['resultado'])) ?></strong>
                    <small>Ventas - gastos pagados<?= $restar_gastos ? ' - gastos pendientes' : '' ?></small>
                </td>
            </tr>
            <tr>
                <td>
                    <small>Gastos operativos pendientes</small>
                    <strong><?= $restar_gastos ? escaparValores($mx($r['gastos']['base'])) : 'No incluidos' ?></strong>
                    <small><?= $restar_gastos ? number_format(count($r['gastos']['filas'])) . ' gasto(s) pendiente(s)' : 'Check desactivado' ?></small>
                </td>
                <td>
                    <small>Deuda con proveedores</small>
                    <strong><?= $restar_proveedores ? escaparValores($mx($r['proveedores']['base'])) : 'No incluida' ?></strong>
                    <small><?= number_format(count($r['proveedores']['filas'])) ?> pedido(s) pendiente(s) · todos los meses</small>
                </td>
                <td class="balance" colspan="2">
                    <small>Posición financiera</small>
                    <strong><?= escaparValores($mx($r['balance']['balance'])) ?></strong>
                    <small>Inventario + ventas - gastos y deudas seleccionadas</small>
                </td>
            </tr>
        </table>
    <?php else : ?>
        <table class="resumen"><tr>
            <td>
                <small>Inventario (a favor)</small>
                <strong><?= escaparValores($mx($r['inventario']['base'])) ?></strong>
                <small><?= number_format((int) $r['inventario']['piezas']) ?> piezas · <?= escaparValores($baseCorta) ?></small>
            </td>
            <td>
                <small>Deuda con proveedores</small>
                <strong><?= escaparValores($mx($r['proveedores']['base'])) ?></strong>
                <small><?= number_format(count($r['proveedores']['filas'])) ?> pedido(s) pendiente(s) · <?= escaparValores($baseCorta) ?></small>
            </td>
            <td>
                <small>Gastos operativos del mes</small>
                <strong><?= $restar_gastos ? escaparValores($mx($r['gastos']['base'])) : 'No incluidos' ?></strong>
                <small><?= $restar_gastos ? number_format(count($r['gastos']['filas'])) . ' gasto(s) pendiente(s)' : 'Check desactivado en la pantalla' ?></small>
            </td>
            <td class="balance">
                <small>Balance</small>
                <strong><?= escaparValores($mx($r['balance']['balance'])) ?></strong>
                <small>Inventario menos las deudas seleccionadas</small>
            </td>
        </tr></table>
    <?php endif; ?>

    <table class="medidor"><tr>
        <td class="grafico">
            <?php if ($medidor !== '') : ?>
                <img src="<?= $medidor ?>" alt="Medidor del estado financiero">
            <?php endif; ?>
        </td>
        <td class="texto">
            <p class="puntaje"><?= number_format((float) $r['balance']['solidez'], 1) ?><small>/ 100</small></p>
            <span class="estado"><?= escaparValores($estado['etiqueta']) ?></span>
            <?php if ($modo === 'detallado') : ?>
                <p class="saldo">Posición financiera: <strong><?= escaparValores($mx($r['balance']['balance'])) ?></strong></p>
                <p class="saldo">Resultado del periodo:
                    <strong class="<?= (float) $r['balance']['resultado'] < 0 ? 'resultado-negativo' : 'resultado-positivo' ?>">
                        <?= escaparValores($mx($r['balance']['resultado'])) ?>
                    </strong>
                </p>
                <p class="guia">
                    Entradas = inventario + ventas del mes. Salidas = gastos pagados<?= $restar_gastos ? ' + gastos operativos pendientes' : '' ?><?= $restar_proveedores ? ' + deuda con proveedores' : '' ?>.
                    La misma base (<?= escaparValores($baseCorta) ?>) se usa en todos los conceptos.
                </p>
            <?php else : ?>
                <p class="saldo">Balance: <strong><?= escaparValores($mx($r['balance']['balance'])) ?></strong></p>
                <p class="guia">
                    Solidez = inventario / (inventario + deudas seleccionadas). La misma base (<?= escaparValores($baseCorta) ?>)
                    se usa para el inventario, los proveedores y los gastos.
                    <?php if ($restar_proveedores) : ?>Se resta la deuda con proveedores.<?php endif; ?>
                    <?php if ($restar_gastos) : ?>Se restan los gastos operativos pendientes de <?= escaparValores(nombreMes((int) $periodo['mes'])) ?>.<?php endif; ?>
                    <?php if (!$restar_proveedores && !$restar_gastos) : ?>No se está restando ninguna deuda.<?php endif; ?>
                </p>
            <?php endif; ?>
        </td>
    </tr></table>

    <?php if ($modo === 'detallado' && $r['alertas']) : ?>
        <div class="diagnostico">
            <strong>Diagnóstico</strong>
            <ul>
                <?php foreach ($r['alertas'] as $alerta) : ?>
                    <li class="<?= escaparValores($alerta['tipo']) ?>"><?= escaparValores($alerta['texto']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($modo === 'detallado' && $r['comparativo']) : ?>
        <h2 class="comparativo">Comparado con <?= escaparValores($r['comparativo']['periodo']['etiqueta']) ?></h2>
        <table class="comparativo">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th class="num">Actual</th>
                    <th class="num">Mes anterior</th>
                    <th class="num">Variación</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['comparativo']['items'] as $item) : $v = $item['variacion']; $bueno = $v !== null && (($v >= 0) === in_array($item['clave'], ['ventas', 'resultado', 'posicion'], true)); ?>
                    <tr>
                        <td><?= escaparValores($item['etiqueta']) ?></td>
                        <td class="num"><?= escaparValores($mx($item['actual'])) ?></td>
                        <td class="num"><?= escaparValores($mx($item['anterior'])) ?></td>
                        <td class="num <?= $v === null ? '' : ($bueno ? 'variacion-bien' : 'variacion-mal') ?>">
                            <?php if ($v === null) : ?>
                                Sin base
                            <?php else : ?>
                                <?= $v > 0 ? '+' : '' ?><?= number_format($v, 1) ?> %
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($modo === 'detallado') : ?>
        <h2>1. Ventas del mes</h2>
        <p class="guia">
            Todas las ventas vigentes del periodo, incluidas las que aún no tienen factura.
            Sin facturar: <?= number_format((int) $r['ventas']['sin_factura_operaciones']) ?> venta(s) por
            <?= escaparValores($mx($r['ventas']['sin_factura_con_iva'])) ?> con IVA.
        </p>
        <table>
            <thead>
                <tr>
                    <th style="width:11%">Folio</th>
                    <th style="width:9%">Fecha</th>
                    <th>Cliente</th>
                    <th style="width:13%">Negocio</th>
                    <th style="width:12%">Facturación</th>
                    <th class="num" style="width:11%">Sin IVA</th>
                    <th class="num" style="width:9%">IVA</th>
                    <th class="num" style="width:11%">Con IVA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['ventas']['filas'] as $fila) : ?>
                    <tr>
                        <td><strong><?= escaparValores($fila['folio']) ?></strong></td>
                        <td><?= escaparValores($fecha($fila['fecha'])) ?></td>
                        <td><?= escaparValores($fila['cliente']) ?></td>
                        <td><?= escaparValores($fila['negocio']) ?></td>
                        <td><?= escaparValores($fila['facturacion']) ?></td>
                        <td class="num"><?= escaparValores($mx($fila['sin_iva'])) ?></td>
                        <td class="num"><?= escaparValores($mx($fila['iva'])) ?></td>
                        <td class="num"><strong><?= escaparValores($mx($fila['con_iva'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$r['ventas']['filas']) : ?>
                    <tr><td colspan="8" class="vacio">No hay ventas registradas en el mes seleccionado.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">TOTAL DE VENTAS</td>
                    <td class="num"><?= escaparValores($mx($r['ventas']['sin_iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['ventas']['iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['ventas']['con_iva'])) ?></td>
                </tr>
            </tfoot>
        </table>

        <h2 class="gastos">2. Gastos pagados del mes</h2>
        <p class="guia">Salidas reales del periodo: gastos operativos y de proveedores ya pagados.</p>
        <table>
            <thead>
                <tr>
                    <th style="width:10%">Fecha</th>
                    <th>Gasto</th>
                    <th style="width:27%">Beneficiario / categoría</th>
                    <th class="num" style="width:14%">Importe sin IVA</th>
                    <th class="num" style="width:10%">IVA</th>
                    <th class="num" style="width:14%">Importe con IVA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['gastos_pagados']['filas'] as $fila) : ?>
                    <tr>
                        <td><?= escaparValores($fecha($fila['fecha'])) ?></td>
                        <td><strong><?= escaparValores($fila['concepto']) ?></strong></td>
                        <td>
                            <?= escaparValores($fila['beneficiario'] ?: '—') ?>
                            <?= $fila['categoria'] !== '' ? ' · ' . escaparValores($fila['categoria']) : '' ?>
                        </td>
                        <td class="num"><?= escaparValores($mx($fila['sin_iva'])) ?></td>
                        <td class="num"><?= escaparValores($mx($fila['iva'])) ?></td>
                        <td class="num"><strong><?= escaparValores($mx($fila['con_iva'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$r['gastos_pagados']['filas']) : ?>
                    <tr><td colspan="6" class="vacio">No hay gastos pagados en el mes seleccionado.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">TOTAL DE GASTOS PAGADOS</td>
                    <td class="num"><?= escaparValores($mx($r['gastos_pagados']['sin_iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['gastos_pagados']['iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['gastos_pagados']['con_iva'])) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <h2><?= (int) $numInventario ?>. Inventario (ingresos)</h2>
    <p class="guia">Valuado con el precio del catálogo. Los productos compartidos entre negocios se cuentan una sola vez. Las existencias negativas no suman.</p>
    <table>
        <thead>
            <tr>
                <th style="width:14%">Código</th>
                <th>Producto</th>
                <th class="num" style="width:8%">Stock</th>
                <th class="num" style="width:16%">Importe sin IVA</th>
                <th class="num" style="width:16%">Importe con IVA</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($r['inventario']['filas'] as $fila) : $bajo = !empty($fila['bajo_minimo']); ?>
                <tr>
                    <td><strong><?= escaparValores($fila['sku'] !== '' ? $fila['sku'] : '—') ?></strong></td>
                    <td class="producto">
                        <?= $imagenProducto($fila) ?>
                        <strong><?= escaparValores($fila['nombre']) ?></strong>
                        <br>
                        <span class="guia">
                            <?= $fila['negocios'] !== '' ? escaparValores($fila['negocios']) : 'Sin negocio' ?>
                        </span>
                    </td>
                    <td class="num<?= $bajo ? ' stock-bajo' : '' ?>">
                        <strong><?= number_format((int) $fila['stock']) ?></strong>
                        <?php if ($fila['stock_minimo'] !== null) : ?>
                            <span class="stock-min">mín. <?= number_format((int) $fila['stock_minimo']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= escaparValores($mx($fila['sin_iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($fila['con_iva'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$r['inventario']['filas']) : ?>
                <tr><td colspan="5" class="vacio">No hay productos con existencia para los negocios seleccionados.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">TOTAL DEL INVENTARIO</td>
                <td class="num"><?= escaparValores($mx($r['inventario']['sin_iva'])) ?></td>
                <td class="num"><?= escaparValores($mx($r['inventario']['con_iva'])) ?></td>
            </tr>
        </tfoot>
    </table>

    <h2 class="prov"><?= (int) $numProveedores ?>. Proveedores</h2>
    <p class="guia">Solo pedidos con saldo pendiente, sin importar el mes en que se hicieron. El saldo es lo que se debe de cada pedido después de sus pagos.</p>
    <table>
        <thead>
            <tr>
                <th style="width:15%">Código de pedido</th>
                <th style="width:10%">Fecha</th>
                <th>Proveedor</th>
                <th class="num" style="width:12%">Saldo sin IVA</th>
                <th class="num" style="width:10%">IVA pendiente</th>
                <th class="num" style="width:12%">Saldo con IVA</th>
                <th class="num" style="width:10%">Pagado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($r['proveedores']['filas'] as $fila) : ?>
                <tr>
                    <td><strong><?= escaparValores($fila['folio']) ?></strong></td>
                    <td><?= escaparValores($fecha($fila['fecha'])) ?></td>
                    <td><?= escaparValores($fila['proveedor']) ?></td>
                    <td class="num"><?= escaparValores($mx($fila['sin_iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($fila['iva'])) ?></td>
                    <td class="num"><strong><?= escaparValores($mx($fila['con_iva'])) ?></strong></td>
                    <td class="num"><?= escaparValores($mx($fila['pagado'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$r['proveedores']['filas']) : ?>
                <tr><td colspan="7" class="vacio">No hay pedidos pendientes de pago.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">TOTAL PENDIENTE</td>
                <td class="num"><?= escaparValores($mx($r['proveedores']['sin_iva'])) ?></td>
                <td class="num"><?= escaparValores($mx($r['proveedores']['iva'])) ?></td>
                <td class="num"><?= escaparValores($mx($r['proveedores']['con_iva'])) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($restar_gastos) : ?>
    <h3>Antigüedad de la deuda con proveedores</h3>
    <table class="resumen">
        <tr>
            <?php foreach ($r['antiguedad']['buckets'] as $bucket) : ?>
                <td>
                    <small><?= escaparValores($bucket['etiqueta']) ?></small>
                    <strong><?= escaparValores($mx($bucket['monto'])) ?></strong>
                    <small><?= number_format((int) $bucket['pedidos']) ?> pedido(s)</small>
                </td>
            <?php endforeach; ?>
        </tr>
    </table>

        <h2 class="gastos"><?= (int) $numGastos ?>. Gastos operativos de <?= escaparValores(nombreMes((int) $periodo['mes']) . ' ' . (int) $periodo['anio']) ?></h2>
        <p class="guia">Solo gastos vigentes y pendientes de pago. Los gastos ligados a un proveedor o a un pedido no se muestran aquí para no duplicar la deuda.</p>
        <table>
            <thead>
                <tr>
                    <th>Gasto</th>
                    <th style="width:28%">Beneficiario / categoría</th>
                    <th class="num" style="width:16%">Importe sin IVA</th>
                    <th class="num" style="width:16%">Importe con IVA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['gastos']['filas'] as $fila) : ?>
                    <tr>
                        <td>
                            <strong><?= escaparValores($fila['concepto']) ?></strong>
                            <span class="guia"><?= escaparValores($fecha($fila['fecha'])) ?></span>
                        </td>
                        <td>
                            <?= escaparValores($fila['beneficiario'] ?: '—') ?>
                            <?= $fila['categoria'] !== '' ? ' · ' . escaparValores($fila['categoria']) : '' ?>
                        </td>
                        <td class="num"><?= escaparValores($mx($fila['sin_iva'])) ?></td>
                        <td class="num"><strong><?= escaparValores($mx($fila['con_iva'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$r['gastos']['filas']) : ?>
                    <tr><td colspan="4" class="vacio">No hay gastos operativos pendientes en ese mes.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">TOTAL DE GASTOS PENDIENTES</td>
                    <td class="num"><?= escaparValores($mx($r['gastos']['sin_iva'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['gastos']['con_iva'])) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if ($modo === 'detallado' && $r['gastos_categoria']['filas']) : ?>
        <h2 class="gastos"><?= (int) $numCategorias ?>. Gastos por categoría</h2>
        <table>
            <thead>
                <tr>
                    <th>Categoría</th>
                    <th class="num" style="width:18%">Pagado</th>
                    <th class="num" style="width:18%">Pendiente</th>
                    <th class="num" style="width:18%">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['gastos_categoria']['filas'] as $fila) : ?>
                    <tr>
                        <td><strong><?= escaparValores($fila['categoria']) ?></strong></td>
                        <td class="num"><?= escaparValores($mx($fila['pagado'])) ?></td>
                        <td class="num"><?= escaparValores($mx($fila['pendiente'])) ?></td>
                        <td class="num"><strong><?= escaparValores($mx($fila['total'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL DE GASTOS</td>
                    <td class="num"><?= escaparValores($mx($r['gastos_categoria']['pagado'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['gastos_categoria']['pendiente'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['gastos_categoria']['total'])) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if ($modo === 'detallado' && count($negocios) > 1 && $r['ventas_negocio']['filas']) : ?>
        <h2><?= (int) $numNegocios ?>. Ventas por negocio</h2>
        <table>
            <thead>
                <tr>
                    <th>Negocio</th>
                    <th class="num" style="width:18%">Operaciones</th>
                    <th class="num" style="width:20%">Ventas</th>
                    <th class="num" style="width:20%">Sin facturar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['ventas_negocio']['filas'] as $fila) : ?>
                    <tr>
                        <td><strong><?= escaparValores($fila['negocio']) ?></strong></td>
                        <td class="num"><?= number_format((int) $fila['operaciones']) ?></td>
                        <td class="num"><strong><?= escaparValores($mx($fila['ventas'])) ?></strong></td>
                        <td class="num"><?= escaparValores($mx($fila['sin_factura'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL</td>
                    <td></td>
                    <td class="num"><?= escaparValores($mx($r['ventas_negocio']['total'])) ?></td>
                    <td class="num"><?= escaparValores($mx($r['ventas']['sin_factura_base'])) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if ($r['inventario']['negativos']) : ?>
        <p class="aviso">
            <strong>Existencias negativas (no suman al inventario):</strong>
            <?= escaparValores(implode('; ', array_map(
                static fn ($n) => ($n['sku'] !== '' ? $n['sku'] . ' ' : '') . $n['nombre'] . ' (' . $n['stock'] . ')',
                $r['inventario']['negativos']
            ))) ?>.
        </p>
    <?php endif; ?>

    <p class="pie">
        Reporte administrativo de solo lectura. El inventario se valúa con el precio del catálogo.
        La deuda con proveedores incluye todos los pedidos con saldo pendiente. Los gastos operativos
        son únicamente los del mes seleccionado que siguen sin pagarse y que no están ligados a un proveedor.
        <?php if ($modo === 'detallado') : ?>
            En modo detallado, las ventas y los gastos pagados son del mes seleccionado; las ventas incluyen
            facturadas, no facturadas y sin clasificar. Posición financiera = inventario + ventas - gastos pagados
            - gastos pendientes - deuda con proveedores.
        <?php endif; ?>
    </p>
</body>
</html>

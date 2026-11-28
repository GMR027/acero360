<?php
/**
 * templates/pdfComprasCliente.php — PDF de las compras de un cliente.
 *
 * Variables:
 *   $cliente       — App\Clientes
 *   $reporte       — resumen, productos, documentos, serie
 *   $periodoTexto  — etiqueta legible del periodo
 *   $empresaNombre — negocio filtrado ('' = todos)
 *   $fecha         — fecha de emisión
 */

$cliente = $cliente ?? null;
$reporte = $reporte ?? [];
$resumen = $reporte['resumen'] ?? [];
$productos = $reporte['productos'] ?? [];
$documentos = $reporte['documentos'] ?? [];
$serie = $reporte['serie'] ?? [];
$moneda = static fn ($monto): string => formatoMoneda((float) $monto);
$totalProductos = array_sum(array_column($productos, 'importe'));
$clienteNombre = $cliente ? ($cliente->nombre_empresa ?: $cliente->nombre) : '';
$nombreMes = static function (int $numero): string {
    $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return $meses[$numero] ?? '';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Compras de <?= escaparValores($clienteNombre) ?></title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.4rem;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #4d4c53;
        }

        h1 { margin: 0; font-size: 17px; font-weight: 700; color: #4679a6; }
        h2 { margin: 0 0 0.35rem; font-size: 12px; color: #4679a6; }
        p  { margin: 0; }

        .cabecera { width: 100%; border-collapse: collapse; margin-bottom: 0.8rem; }
        .cabecera td { vertical-align: bottom; padding: 0; }
        .cabecera .derecha { text-align: right; font-size: 9px; color: #6b6b70; }
        .marca { font-size: 17px; font-weight: 700; color: #4679a6; }
        .subtitulo { font-size: 10px; color: #6b6b70; }

        .ficha { width: 100%; border-collapse: collapse; margin-bottom: 0.8rem; }
        .ficha td { padding: 0.2rem 0.4rem; border: 1px solid #d9e2ec; vertical-align: top; }
        .ficha .etiqueta { width: 18%; background: #f3f7fb; font-weight: 700; color: #38648c; }
        .ficha .dato { width: 32%; }

        .resumen { width: 100%; border-collapse: separate; border-spacing: 0.35rem 0; margin: 0 -0.35rem 0.8rem; }
        .resumen td {
            width: 25%;
            padding: 0.45rem 0.5rem;
            background: #f3f7fb;
            border: 1px solid #d9e2ec;
            border-radius: 3px;
            vertical-align: top;
        }
        .resumen .etiqueta { display: block; font-size: 8px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b6b70; }
        .resumen .valor { display: block; margin-top: 0.15rem; font-size: 13px; font-weight: 700; color: #38648c; }
        .resumen .pie { display: block; margin-top: 0.1rem; font-size: 8px; color: #6b6b70; }

        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 0.9rem; }
        table.datos th {
            padding: 0.28rem 0.35rem;
            background: #38648c;
            color: #fff;
            font-size: 8.5px;
            text-align: left;
            border: 1px solid #38648c;
        }
        table.datos td {
            padding: 0.25rem 0.35rem;
            border: 1px solid #d9e2ec;
            vertical-align: top;
        }
        table.datos tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; white-space: nowrap; }
        .centro { text-align: center; }
        .chico { font-size: 8.5px; color: #6b6b70; }
        .nota { margin-top: 0.6rem; padding: 0.45rem 0.55rem; background: #fff8e8; border: 1px solid #e8c46a; font-size: 8.5px; color: #6b5a2a; }
        .pie { margin-top: 0.8rem; padding-top: 0.4rem; border-top: 1px solid #d9e2ec; font-size: 8px; color: #6b6b70; text-align: right; }
    </style>
</head>
<body>
    <table class="cabecera">
        <tr>
            <td>
                <div class="marca">Acero 360</div>
                <div class="subtitulo">Compras del cliente</div>
            </td>
            <td class="derecha">
                <strong><?= escaparValores($periodoTexto) ?></strong><br>
                Emitido <?= escaparValores($fecha) ?><br>
                <?= $empresaNombre !== '' ? 'Negocio: ' . escaparValores($empresaNombre) : 'Todos los negocios' ?>
            </td>
        </tr>
    </table>

    <table class="ficha">
        <tr>
            <td class="etiqueta">Cliente</td>
            <td class="dato"><?= escaparValores($clienteNombre) ?></td>
            <td class="etiqueta">Contacto</td>
            <td class="dato"><?= escaparValores($cliente->nombre ?? '') ?></td>
        </tr>
        <tr>
            <td class="etiqueta">RFC</td>
            <td class="dato"><?= escaparValores($cliente->rfc ?? '—') ?></td>
            <td class="etiqueta">Teléfono</td>
            <td class="dato"><?= escaparValores($cliente->telefono ?? '—') ?></td>
        </tr>
        <tr>
            <td class="etiqueta">Periodo</td>
            <td class="dato" colspan="3"><?= escaparValores($periodoTexto) ?></td>
        </tr>
    </table>

    <table class="resumen">
        <tr>
            <td><span class="etiqueta">Ventas</span><span class="valor"><?= number_format((int) ($resumen['ventas'] ?? 0)) ?></span><span class="pie">documentos</span></td>
            <td><span class="etiqueta">Piezas</span><span class="valor"><?= number_format((int) ($resumen['piezas'] ?? 0)) ?></span><span class="pie">unidades</span></td>
            <td><span class="etiqueta">Total vendido</span><span class="valor"><?= $moneda($resumen['total'] ?? 0) ?></span><span class="pie">IVA incluido</span></td>
            <td><span class="etiqueta">Ticket promedio</span><span class="valor"><?= $moneda($resumen['ticket_promedio'] ?? 0) ?></span><span class="pie">por venta</span></td>
        </tr>
    </table>

    <table class="ficha">
        <tr>
            <td class="etiqueta">Subtotal</td>
            <td class="dato"><?= $moneda($resumen['subtotal'] ?? 0) ?></td>
            <td class="etiqueta">Descuentos</td>
            <td class="dato">-<?= $moneda($resumen['descuento'] ?? 0) ?></td>
        </tr>
        <tr>
            <td class="etiqueta">Base sin IVA</td>
            <td class="dato"><?= $moneda($resumen['base'] ?? 0) ?></td>
            <td class="etiqueta">IVA</td>
            <td class="dato"><?= $moneda($resumen['iva'] ?? 0) ?></td>
        </tr>
        <tr>
            <td class="etiqueta">Última compra</td>
            <td class="dato" colspan="3"><?= !empty($resumen['ultima']) ? date('d/m/Y', strtotime($resumen['ultima'])) : 'Sin compras en el periodo' ?></td>
        </tr>
    </table>

    <h2>Productos comprados</h2>
    <?php if (!$productos) : ?>
        <p class="chico">Sin productos en el periodo.</p>
    <?php else : ?>
        <table class="datos">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>SKU</th>
                    <th class="num">Piezas</th>
                    <th class="num">Ventas</th>
                    <th class="num">Importe sin IVA</th>
                    <th class="num">%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $producto) :
                    $pct = $totalProductos > 0 ? $producto['importe'] / $totalProductos * 100 : 0;
                    ?>
                    <tr>
                        <td><?= escaparValores($producto['nombre']) ?></td>
                        <td><?= escaparValores($producto['sku'] ?: '—') ?></td>
                        <td class="num"><?= number_format((int) $producto['piezas']) ?> <?= escaparValores($producto['unidad']) ?></td>
                        <td class="num"><?= number_format((int) $producto['ventas']) ?></td>
                        <td class="num"><?= $moneda($producto['importe']) ?></td>
                        <td class="num"><?= number_format($pct, 1) ?> %</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($serie) : ?>
        <h2>Compras por mes · <?= (int) ($reporte['anio'] ?? 0) ?></h2>
        <table class="datos">
            <thead>
                <tr><th>Mes</th><th class="num">Ventas</th><th class="num">Piezas</th><th class="num">Total</th></tr>
            </thead>
            <tbody>
                <?php foreach ($serie as $fila) : ?>
                    <tr>
                        <td><?= escaparValores($nombreMes((int) $fila['mes'])) ?></td>
                        <td class="num"><?= number_format((int) $fila['ventas']) ?></td>
                        <td class="num"><?= number_format((int) $fila['piezas']) ?></td>
                        <td class="num"><?= $moneda($fila['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Ventas del periodo</h2>
    <?php if (!$documentos) : ?>
        <p class="chico">Sin ventas registradas en el periodo.</p>
    <?php else : ?>
        <table class="datos">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Fecha</th>
                    <th>Negocio</th>
                    <th class="num">Piezas</th>
                    <th class="num">Total</th>
                    <th>Entrega</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documentos as $documento) :
                    $entrega = [
                        'pendiente' => 'Por entregar',
                        'entregado' => 'Entregado',
                        'entregado_con_incidencias' => 'Con incidencias',
                        'entregado_incidencias_resueltas' => 'Incidencias resueltas',
                    ][$documento['entrega_estado'] ?? ''] ?? '—';
                    ?>
                    <tr>
                        <td><?= escaparValores($documento['folio']) ?><?= !empty($documento['cotizacion_id']) ? ' · Cotización' : '' ?></td>
                        <td><?= date('d/m/Y', strtotime($documento['fecha'])) ?></td>
                        <td><?= escaparValores($documento['empresa_nombre']) ?></td>
                        <td class="num"><?= number_format((int) $documento['piezas']) ?></td>
                        <td class="num"><?= $moneda($documento['total']) ?></td>
                        <td><?= escaparValores($entrega) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="nota">
        No incluye ventas canceladas. Las cotizaciones que aún no se venden no cuentan como venta.
        Los importes de productos son subtotales de partida sin IVA; el descuento global se muestra en el desglose del periodo.
    </div>

    <div class="pie">Acero 360 · Reporte de compras del cliente · <?= escaparValores($fecha) ?></div>
</body>
</html>

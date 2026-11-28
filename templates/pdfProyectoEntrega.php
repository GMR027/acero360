<?php
/**
 * templates/pdfProyectoEntrega.php — Estado de un proyecto de entrega.
 *
 * Variables:
 *   $proyecto — App\ProyectosEntrega
 *   $progreso — salida de ProyectosEntrega::progreso()
 *   $fecha    — fecha de emisión
 */

$proyecto = $proyecto ?? null;
$progreso = $progreso ?? ['partidas' => [], 'extras' => [], 'totales' => [], 'ventas' => [], 'entregas' => []];
$totales  = $progreso['totales'];
$unidad   = $totales['unidad_comun'] ?? 'unidades';
$moneda   = static fn ($monto): string => formatoMoneda((float) $monto);
$clienteNombre = $proyecto ? ($proyecto->cliente_empresa ?: $proyecto->cliente_nombre) : '';
$estado = $proyecto ? \App\ProyectosEntrega::datosEstado((string) $proyecto->estado) : ['etiqueta' => '—'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= escaparValores($proyecto->folio ?? 'Proyecto') ?> · <?= escaparValores($proyecto->nombre ?? '') ?></title>
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
        p { margin: 0; }
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
            width: 20%;
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
        table.datos td { padding: 0.25rem 0.35rem; border: 1px solid #d9e2ec; vertical-align: top; }
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
                <div class="subtitulo">Estado del proyecto de entrega</div>
            </td>
            <td class="derecha">
                <strong><?= escaparValores($proyecto->folio ?? '') ?></strong><br>
                Emitido <?= escaparValores($fecha) ?><br>
                <?= escaparValores($estado['etiqueta'] ?? '') ?>
            </td>
        </tr>
    </table>

    <h1><?= escaparValores($proyecto->nombre ?? '') ?></h1>

    <table class="ficha">
        <tr>
            <td class="etiqueta">Cliente</td>
            <td class="dato"><?= escaparValores($clienteNombre) ?></td>
            <td class="etiqueta">Contacto</td>
            <td class="dato"><?= escaparValores($proyecto->cliente_nombre ?? '') ?></td>
        </tr>
        <tr>
            <td class="etiqueta">Negocio</td>
            <td class="dato"><?= escaparValores($proyecto->empresa_nombre ?? '') ?></td>
            <td class="etiqueta">RFC</td>
            <td class="dato"><?= escaparValores($proyecto->cliente_rfc ?? '—') ?></td>
        </tr>
        <tr>
            <td class="etiqueta">Inicio</td>
            <td class="dato"><?= $proyecto->fecha_inicio ? date('d/m/Y', strtotime($proyecto->fecha_inicio)) : '—' ?></td>
            <td class="etiqueta">Fecha compromiso</td>
            <td class="dato"><?= $proyecto->fecha_compromiso ? date('d/m/Y', strtotime($proyecto->fecha_compromiso)) : '—' ?></td>
        </tr>
    </table>

    <table class="resumen">
        <tr>
            <td><span class="etiqueta">Comprometido</span><span class="valor"><?= number_format((int) $totales['comprometido']) ?></span><span class="pie"><?= escaparValores($unidad) ?></span></td>
            <td><span class="etiqueta">Vendido</span><span class="valor"><?= number_format((int) $totales['vendido']) ?></span><span class="pie"><?= count($progreso['ventas']) ?> venta(s)</span></td>
            <td><span class="etiqueta">Entregado</span><span class="valor"><?= number_format((int) $totales['entregado']) ?></span><span class="pie"><?= number_format((float) $totales['avance'], 1) ?> %</span></td>
            <td><span class="etiqueta">Por entregar</span><span class="valor"><?= number_format((int) $totales['por_entregar']) ?></span><span class="pie">faltan del proyecto</span></td>
            <td><span class="etiqueta">Por vender</span><span class="valor"><?= number_format((int) $totales['por_vender']) ?></span><span class="pie">sin venta</span></td>
        </tr>
    </table>

    <h2>Partidas del proyecto</h2>
    <?php if (!$progreso['partidas']) : ?>
        <p class="chico">Sin partidas.</p>
    <?php else : ?>
        <table class="datos">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>SKU</th>
                    <th class="num">Comprometido</th>
                    <th class="num">Vendido</th>
                    <th class="num">Entregado</th>
                    <th class="num">Por entregar</th>
                    <th class="num">Por vender</th>
                    <th class="num">Avance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($progreso['partidas'] as $partida) : ?>
                    <tr>
                        <td><?= escaparValores($partida['nombre']) ?></td>
                        <td><?= escaparValores($partida['sku'] ?: '—') ?></td>
                        <td class="num"><?= number_format((int) $partida['comprometido']) ?> <?= escaparValores($partida['unidad']) ?></td>
                        <td class="num"><?= number_format((int) $partida['vendido']) ?></td>
                        <td class="num"><?= number_format((int) $partida['entregado']) ?></td>
                        <td class="num"><?= number_format((int) $partida['por_entregar']) ?></td>
                        <td class="num"><?= number_format((int) $partida['por_vender']) ?></td>
                        <td class="num"><?= number_format((float) $partida['avance'], 1) ?> %</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($progreso['extras']) : ?>
        <h2>Productos vendidos fuera de las partidas</h2>
        <table class="datos">
            <thead>
                <tr><th>Producto</th><th class="num">Vendido</th><th class="num">Entregado</th><th class="num">Devuelto</th></tr>
            </thead>
            <tbody>
                <?php foreach ($progreso['extras'] as $extra) : ?>
                    <tr>
                        <td><?= escaparValores($extra['nombre']) ?></td>
                        <td class="num"><?= number_format((int) $extra['vendido']) ?></td>
                        <td class="num"><?= number_format((int) $extra['entregado']) ?></td>
                        <td class="num"><?= number_format((int) $extra['devuelto']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Ventas vinculadas</h2>
    <?php if (!$progreso['ventas']) : ?>
        <p class="chico">Sin ventas vinculadas.</p>
    <?php else : ?>
        <table class="datos">
            <thead>
                <tr><th>Folio</th><th>Fecha</th><th class="num">Piezas</th><th class="num">Entregadas</th><th class="num">Pendientes</th><th class="num">Total</th></tr>
            </thead>
            <tbody>
                <?php foreach ($progreso['ventas'] as $venta) : ?>
                    <tr>
                        <td><?= escaparValores($venta['folio']) ?></td>
                        <td><?= date('d/m/Y', strtotime($venta['fecha'])) ?></td>
                        <td class="num"><?= number_format((int) $venta['piezas']) ?></td>
                        <td class="num"><?= number_format((int) $venta['entregadas']) ?></td>
                        <td class="num"><?= number_format(max(0, (int) $venta['piezas'] - (int) $venta['entregadas'])) ?></td>
                        <td class="num"><?= $moneda($venta['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Entregas registradas</h2>
    <?php if (!$progreso['entregas']) : ?>
        <p class="chico">Sin entregas registradas.</p>
    <?php else : ?>
        <table class="datos">
            <thead>
                <tr><th>Fecha</th><th>Venta</th><th class="num">Piezas</th><th>Recibió</th><th>Origen</th></tr>
            </thead>
            <tbody>
                <?php foreach ($progreso['entregas'] as $entrega) : ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($entrega['fecha'])) ?></td>
                        <td><?= escaparValores($entrega['folio']) ?></td>
                        <td class="num"><?= number_format((int) $entrega['piezas']) ?></td>
                        <td><?= escaparValores($entrega['recibio_nombre'] ?: '—') ?></td>
                        <td><?= ($entrega['origen'] ?? 'web') === 'app' ? 'App' : 'Web' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (trim((string) ($proyecto->descripcion ?? '')) !== '') : ?>
        <div class="nota"><?= nl2br(escaparValores($proyecto->descripcion)) ?></div>
    <?php endif; ?>

    <div class="pie">Acero 360 · Proyecto <?= escaparValores($proyecto->folio ?? '') ?> · <?= escaparValores($fecha) ?></div>
</body>
</html>

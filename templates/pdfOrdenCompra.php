<?php
/**
 * templates/pdfOrdenCompra.php — PDF de una orden de compra.
 *
 * $tipo 'orden'  → lo solicitado (se manda al proveedor).
 * $tipo 'cierre' → solicitado vs. recibido, costos finales y diferencias
 *                  (revisión interna antes de pagar).
 *
 * Variables: $orden, $partidas, $totales, $recepciones, $tipo
 */
$cierre = $tipo === 'cierre';
$mx = static fn ($v) => '$' . number_format((float) $v, 2);
$estado = \App\OrdenesCompra::ESTADOS[$orden['estado']]['etiqueta'] ?? $orden['estado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= $cierre ? 'Cierre de' : 'Orden de' ?> compra <?= escaparValores($orden['folio']) ?></title>
    <style>
        body { margin: 0; padding: 1.5rem; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 17px; color: #4679a6; }
        h2 { margin: 1rem 0 .3rem; font-size: 12px; color: #4679a6; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .3rem; background: #eaf1f8; border: 1px solid #c7dcef; font-size: 8.5px; text-transform: uppercase; color: #4679a6; }
        td { padding: .3rem; border: 1px solid #e4e6ea; font-size: 10px; }
        .num { text-align: right; } .centro { text-align: center; }
        .falta { color: #b3261e; font-weight: 700; } .sobra { color: #b26a00; font-weight: 700; }
        .total td { font-weight: 700; background: #f5f7fa; }
        .aviso { margin-top: 1rem; padding: .5rem; background: #fff7e0; border: 1px solid #f0d58a; font-size: 9.5px; }
        .sin-borde td { border: 0; padding: .1rem 0; }
    </style>
</head>
<body>
    <h1><?= $cierre ? 'Reporte de cierre de orden de compra' : 'Orden de compra' ?> · <?= escaparValores($orden['folio']) ?></h1>
    <table class="sin-borde">
        <tr><td>Proveedor: <strong><?= escaparValores($orden['proveedor_nombre']) ?></strong>
            <?= $orden['proveedor_rfc'] ? ' · RFC ' . escaparValores($orden['proveedor_rfc']) : '' ?></td>
            <td class="num">Fecha de solicitud: <?= escaparValores(date('d/m/Y', strtotime($orden['fecha_solicitud']))) ?></td></tr>
        <tr><td>Estado: <?= escaparValores($estado) ?></td>
            <td class="num">Emitido: <?= date('d/m/Y H:i') ?></td></tr>
        <?php if ($cierre && $orden['cerrada_en']) : ?>
            <tr><td colspan="2">Cerrada el <?= escaparValores(date('d/m/Y H:i', strtotime($orden['cerrada_en']))) ?>
                <?= $orden['motivo_cierre'] ? ' · ' . escaparValores($orden['motivo_cierre']) : '' ?></td></tr>
        <?php endif; ?>
    </table>

    <h2>Productos</h2>
    <table>
        <thead>
            <tr>
                <th>Código</th><th>Producto</th><th>Solicitado</th>
                <?php if ($cierre) : ?><th>Recibido</th><th>Faltante</th><?php endif; ?>
                <th>Costo esperado</th>
                <?php if ($cierre) : ?><th>Costo final</th><th>Diferencia</th><?php endif; ?>
                <th><?= $cierre ? 'Importe recibido' : 'Importe' ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($partidas as $p) : ?>
                <tr>
                    <td class="centro"><?= escaparValores($p['producto_sku'] ?? '') ?></td>
                    <td><?= escaparValores($p['producto_nombre']) ?>
                        <?= $p['descripcion'] ? '<br><span style="color:#6b6b70">' . escaparValores($p['descripcion']) . '</span>' : '' ?></td>
                    <td class="num"><?= (int) $p['solicitada'] ?></td>
                    <?php if ($cierre) : ?>
                        <td class="num"><?= (int) $p['recibida'] ?></td>
                        <td class="num <?= $p['pendiente'] > 0 ? 'falta' : ($p['pendiente'] < 0 ? 'sobra' : '') ?>">
                            <?= $p['pendiente'] > 0 ? (int) $p['pendiente'] : ($p['pendiente'] < 0 ? '+' . abs((int) $p['pendiente']) . ' excedente' : '0') ?>
                        </td>
                    <?php endif; ?>
                    <td class="num"><?= $mx($p['costo_esperado']) ?></td>
                    <?php if ($cierre) : ?>
                        <td class="num"><?= $p['costo_final'] !== null ? $mx($p['costo_final']) : '—' ?></td>
                        <td class="num"><?= $mx($p['diferencia']) ?></td>
                    <?php endif; ?>
                    <td class="num"><?= $mx($cierre ? $p['importe_recibido'] : $p['importe_esperado']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="2" class="num">Totales</td>
                <td class="num"><?= (int) $totales['piezas_solicitadas'] ?></td>
                <?php if ($cierre) : ?>
                    <td class="num"><?= (int) $totales['piezas_recibidas'] ?></td>
                    <td class="num"><?= (int) $totales['piezas_pendientes'] ?></td>
                <?php endif; ?>
                <td class="num"><?= $mx($totales['esperado']) ?></td>
                <?php if ($cierre) : ?><td></td><td class="num"><?= $mx($totales['diferencia']) ?></td><?php endif; ?>
                <td class="num"><?= $mx($cierre ? $totales['recibido'] : $totales['esperado']) ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($cierre && $recepciones) : ?>
        <h2>Historial de recepciones</h2>
        <table>
            <thead><tr><th>Fecha</th><th>Producto</th><th>Cantidad</th><th>Costo unitario</th><th>Registró</th></tr></thead>
            <tbody>
                <?php foreach ($recepciones as $r) : foreach ($r['renglones'] as $linea) : ?>
                    <tr>
                        <td class="centro"><?= escaparValores(date('d/m/Y', strtotime($r['fecha']))) ?></td>
                        <td><?= escaparValores($linea['producto_nombre']) ?></td>
                        <td class="num"><?= (int) $linea['cantidad_recibida'] ?></td>
                        <td class="num"><?= $mx($linea['costo_unitario']) ?></td>
                        <td><?= escaparValores($r['usuario_nombre'] ?? '') ?></td>
                    </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($orden['observaciones']) : ?>
        <h2>Observaciones</h2>
        <p><?= nl2br(escaparValores($orden['observaciones'])) ?></p>
    <?php endif; ?>

    <?php if ($cierre) : ?>
        <p class="aviso">Documento de revisión interna previo al pago. Las diferencias se calculan sobre lo realmente recibido.</p>
    <?php endif; ?>
</body>
</html>

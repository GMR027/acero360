<?php
/**
 * templates/pdfOrdenCompra.php — PDF de una orden de compra.
 *
 * $tipo 'orden'  → lo solicitado (se manda al proveedor).
 * $tipo 'cierre' → solicitado vs. recibido, costos finales y diferencias
 *                  (revisión interna antes de pagar).
 *
 * Variables: $orden, $partidas, $totales, $resumen, $recepciones, $tipo
 */
$cierre = $tipo === 'cierre';
$mx = static fn ($v) => '$' . number_format((float) $v, 2);
$estado = \App\OrdenesCompra::ESTADOS[$orden['estado']]['etiqueta'] ?? $orden['estado'];
$tipoTexto = $orden['tipo'] === 'insumo' ? 'Material, insumo o consumible' : 'Producto semiterminado';
$cfdiTexto = \App\CompraContable::ESTADOS_CFDI[$orden['estado_cfdi']]['etiqueta'] ?? $orden['estado_cfdi'];
$num = static fn ($v) => \App\OrdenesCompra::num($v);

/** Miniatura (o imagen completa) de public/image en base64, o '' si no existe. */
$imagenBase64 = static function (?string $archivo): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '' || strpos($archivo, '..') !== false) {
        return '';
    }
    $mini = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $archivo);
    foreach ([$mini, $archivo] as $nombre) {
        $ruta = dirname(__DIR__) . '/public/image/' . $nombre;
        if (is_file($ruta)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($ruta) : 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($ruta));
        }
    }
    return '';
};
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
        img.foto { width: 40px; height: 40px; }
        .sin-borde td { border: 0; padding: .1rem 0; }
    </style>
</head>
<body>
    <h1><?= $cierre ? 'Reporte de cierre de orden de compra' : 'Orden de compra' ?> · <?= escaparValores($orden['folio']) ?></h1>
    <table class="sin-borde">
        <tr><td>Proveedor: <strong><?= escaparValores($orden['proveedor_nombre']) ?></strong>
            <?= $orden['proveedor_rfc'] ? ' · RFC ' . escaparValores($orden['proveedor_rfc']) : '' ?></td>
            <td class="num">Fecha de solicitud: <?= escaparValores(date('d/m/Y', strtotime($orden['fecha_solicitud']))) ?></td></tr>
        <tr><td>Tipo: <?= escaparValores($tipoTexto) ?><?= $orden['referencia'] ? ' · Pedido/folio del proveedor: ' . escaparValores($orden['referencia']) : '' ?></td>
            <td class="num">Factura: <?= escaparValores($cfdiTexto) ?></td></tr>
        <tr><td>Estado: <?= escaparValores($estado) ?></td>
            <td class="num">Emitido: <?= date('d/m/Y H:i') ?></td></tr>
        <?php if ($cierre && $orden['cerrada_en']) : ?>
            <tr><td colspan="2">Cerrada el <?= escaparValores(date('d/m/Y H:i', strtotime($orden['cerrada_en']))) ?>
                <?= $orden['motivo_cierre'] ? ' · ' . escaparValores($orden['motivo_cierre']) : '' ?></td></tr>
        <?php endif; ?>
    </table>

    <h2>Partidas</h2>
    <table>
        <thead>
            <tr>
                <?php if ($cierre) : ?><th>Imagen</th><?php endif; ?><th>Código</th><th>Artículo</th><th>Solicitado</th>
                <?php if ($cierre) : ?><th>Recibido</th><th>Faltante</th><?php endif; ?>
                <th>Costo esperado</th>
                <?php if ($cierre) : ?><th>Costo final</th><th>Diferencia</th><?php endif; ?>
                <th><?= $cierre ? 'Importe recibido' : 'Importe' ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($partidas as $p) : ?>
                <tr>
                    <?php if ($cierre) : $img = $imagenBase64($p['catalogo_imagen'] ?: ($p['producto_imagen'] ?? '')); ?>
                        <td class="centro"><?php if ($img !== '') : ?><img class="foto" src="<?= $img ?>" alt=""><?php endif; ?></td>
                    <?php endif; ?>
                    <td class="centro"><?= escaparValores($p['producto_sku'] ?? '') ?></td>
                    <td><?= escaparValores($p['descripcion'] ?: $p['nombre_mostrado']) ?><?= $p['codigo'] ? ' · ' . escaparValores($p['codigo']) : '' ?>
                        <?= $p['descripcion'] && $p['producto_nombre'] ? '<br><span style="color:#6b6b70">Catálogo: ' . escaparValores($p['producto_nombre']) . '</span>' : '' ?>
                        <?= $p['material_nombre'] ? '<br><span style="color:#6b6b70">Material: ' . escaparValores($p['material_nombre']) . '</span>' : '' ?></td>
                    <td class="num"><?= $num($p['solicitada']) ?> <?= escaparValores($p['unidad_mostrada']) ?></td>
                    <?php if ($cierre) : ?>
                        <td class="num"><?= (int) $p['controla'] ? $num($p['recibida']) : '—' ?></td>
                        <td class="num <?= $p['pendiente'] > 0 ? 'falta' : ($p['pendiente'] < 0 ? 'sobra' : '') ?>">
                            <?= !(int) $p['controla'] ? '—' : ($p['pendiente'] > 0 ? $num($p['pendiente']) : ($p['pendiente'] < 0 ? '+' . $num(abs($p['pendiente'])) . ' excedente' : '0')) ?>
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
                <td colspan="<?= $cierre ? 3 : 2 ?>" class="num">Totales</td>
                <td class="num"><?= $num($totales['piezas_solicitadas']) ?></td>
                <?php if ($cierre) : ?>
                    <td class="num"><?= $num($totales['piezas_recibidas']) ?></td>
                    <td class="num"><?= $num($totales['piezas_pendientes']) ?></td>
                <?php endif; ?>
                <td class="num"><?= $mx($totales['esperado']) ?></td>
                <?php if ($cierre) : ?><td></td><td class="num"><?= $mx($totales['diferencia']) ?></td><?php endif; ?>
                <td class="num"><?= $mx($cierre ? $totales['recibido'] : $totales['esperado']) ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($resumen['iva'] !== null || $orden['iva_tasa'] !== null) : ?>
        <table style="width:45%;margin:.6rem 0 0 auto">
            <tr><td>Subtotal de la compra</td><td class="num"><?= $mx($resumen['subtotal']) ?></td></tr>
            <tr><td>IVA<?= $orden['iva_tasa'] !== null ? ' (' . rtrim(rtrim(number_format((float) $orden['iva_tasa'], 2, '.', ''), '0'), '.') . ' %)' : '' ?></td>
                <td class="num"><?= $resumen['iva'] !== null ? $mx($resumen['iva']) : 'No especificado' ?></td></tr>
            <tr class="total"><td>Total de la compra</td><td class="num"><?= $mx($resumen['total']) ?></td></tr>
        </table>
    <?php endif; ?>

    <?php if ($cierre && $recepciones) : ?>
        <h2>Historial de recepciones</h2>
        <table>
            <thead><tr><th>Fecha</th><th>Artículo</th><th>Cantidad</th><th>Costo unitario</th><th>Registró</th></tr></thead>
            <tbody>
                <?php foreach ($recepciones as $r) : foreach ($r['renglones'] as $linea) : ?>
                    <tr>
                        <td class="centro"><?= escaparValores(date('d/m/Y', strtotime($r['fecha']))) ?></td>
                        <td><?= escaparValores($linea['producto_nombre'] ?? $linea['material_nombre'] ?? '—') ?></td>
                        <td class="num"><?= $num($linea['cantidad_recibida']) ?></td>
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

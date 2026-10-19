<?php
/**
 * templates/pdfEntregas.php — PDF de la pantalla de entrega.
 *
 * $tipo 'preparacion' → lista para alistar: productos AGRUPADOS con foto,
 *                       código, cantidad total y ventas de origen.
 * $tipo 'entrega'     → una sección por cliente/destino con sus productos
 *                       pendientes y línea de firma de recibido.
 *
 * Variables: $prep, $ventas, $grupos, $tipo
 */
$entrega = $tipo === 'entrega';

$foto = static function (?string $archivo): string {
    $archivo = trim((string) $archivo);
    if ($archivo === '' || strpos($archivo, '..') !== false) {
        return '';
    }
    $mini = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $archivo);
    $ruta = dirname(__DIR__) . '/public/image/' . $mini;
    if (!is_file($ruta)) {
        return '';
    }
    $tipoMime = function_exists('mime_content_type') ? mime_content_type($ruta) : 'image/jpeg';
    return 'data:' . $tipoMime . ';base64,' . base64_encode((string) file_get_contents($ruta));
};

/** Productos pendientes sumados por cliente. */
$porCliente = [];
foreach ($ventas as $v) {
    $c = (int) $v['cliente_id'];
    $porCliente[$c] ??= ['nombre' => $v['cliente_empresa'] ?: $v['cliente_nombre'], 'contacto' => $v['cliente_nombre'],
        'ubicacion' => $v['cliente_ubicacion'], 'telefono' => $v['cliente_telefono'], 'ventas' => [], 'productos' => []];
    $porCliente[$c]['ventas'][] = $v['folio'];
    foreach ($v['lineas'] as $l) {
        if ($l['pendiente'] <= 0) { continue; }
        $p = (int) $l['producto_id'];
        $porCliente[$c]['productos'][$p] ??= ['sku' => $l['sku'], 'nombre' => $l['nombre'], 'total' => 0];
        $porCliente[$c]['productos'][$p]['total'] += $l['pendiente'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= $entrega ? 'Entrega' : 'Lista de preparación' ?> <?= escaparValores($prep['folio']) ?></title>
    <style>
        body { margin: 0; padding: 1.5rem; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #4d4c53; }
        h1 { margin: 0 0 .2rem; font-size: 17px; color: #4679a6; }
        h2 { margin: 1.2rem 0 .3rem; font-size: 13px; color: #4679a6; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: .3rem; background: #eaf1f8; border: 1px solid #c7dcef; font-size: 8.5px; text-transform: uppercase; color: #4679a6; }
        td { padding: .3rem; border: 1px solid #e4e6ea; font-size: 10.5px; vertical-align: middle; }
        .num { text-align: right; } .centro { text-align: center; }
        .cant { font-size: 15px; font-weight: 700; text-align: center; }
        img.foto { width: 46px; height: 46px; }
        .caja { display: inline-block; width: 13px; height: 13px; border: 1px solid #4d4c53; }
        .firma { margin-top: 2.2rem; width: 55%; border-top: 1px solid #4d4c53; padding-top: 3px; font-size: 9.5px; }
        .cliente { page-break-inside: avoid; }
        .chico { color: #6b6b70; font-size: 9.5px; }
    </style>
</head>
<body>
    <h1><?= $entrega ? 'Entrega a clientes' : 'Lista de preparación' ?> · <?= escaparValores($prep['folio']) ?></h1>
    <p class="chico">Estado: <?= escaparValores(\App\Entregas::ESTADOS[$prep['estado']]['etiqueta']) ?> · Emitido <?= date('d/m/Y H:i') ?>
        <?= $prep['notas'] ? ' · ' . escaparValores($prep['notas']) : '' ?></p>

    <?php if (!$entrega) : ?>
        <h2>Productos agrupados</h2>
        <table>
            <thead><tr><th></th><th>Foto</th><th>Código</th><th>Producto</th><th>Cantidad</th><th>Ventas / clientes de origen</th></tr></thead>
            <tbody>
                <?php foreach ($grupos as $g) : $img = $foto($g['imagen']); ?>
                    <tr>
                        <td class="centro"><span class="caja"></span></td>
                        <td class="centro"><?php if ($img !== '') : ?><img class="foto" src="<?= $img ?>" alt=""><?php endif; ?></td>
                        <td class="centro"><strong><?= escaparValores($g['sku']) ?></strong></td>
                        <td><?= escaparValores($g['nombre']) ?></td>
                        <td class="cant"><?= (int) $g['total'] ?></td>
                        <td class="chico"><?php foreach ($g['origenes'] as $o) : ?>
                            <?= (int) $o['cantidad'] ?> · <?= escaparValores($o['venta']) ?> · <?= escaparValores($o['cliente']) ?><br>
                        <?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$grupos) : ?><tr><td colspan="6" class="centro">Nada pendiente por preparar.</td></tr><?php endif; ?>
            </tbody>
        </table>
    <?php else : ?>
        <?php foreach ($porCliente as $c) : ?>
            <div class="cliente">
                <h2><?= escaparValores($c['nombre']) ?></h2>
                <p class="chico"><?= escaparValores((string) $c['ubicacion']) ?>
                    <?= $c['telefono'] ? ' · Tel. ' . escaparValores($c['telefono']) : '' ?>
                    · Ventas: <?= escaparValores(implode(', ', $c['ventas'])) ?></p>
                <table>
                    <thead><tr><th></th><th>Código</th><th>Producto</th><th>Cantidad a entregar</th></tr></thead>
                    <tbody>
                        <?php foreach ($c['productos'] as $p) : ?>
                            <tr><td class="centro"><span class="caja"></span></td>
                                <td class="centro"><strong><?= escaparValores($p['sku']) ?></strong></td>
                                <td><?= escaparValores($p['nombre']) ?></td><td class="cant"><?= (int) $p['total'] ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$c['productos']) : ?><tr><td colspan="4" class="centro">Entrega completa.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <p class="firma">Recibí de conformidad (nombre y firma)</p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>

<?php
/**
 * templates/pdfEntregas.php — Hoja de la pantalla de entrega (PDF e impresión).
 *
 * $tipo 'preparacion' → consolidado de productos (cantidades sumadas, con foto y ventas de origen)
 *                       y detalle por cliente y pedido.
 * $tipo 'entrega'     → una sección por cliente con el detalle de cada pedido (vendido, entregado,
 *                       pendiente), datos de entrega y línea de firma.
 *
 * Solo lectura: nunca registra entregas ni toca existencias.
 *
 * Variables: $prep (null si la selección no está guardada), $ventas, $grupos, $tipo, $imprimir
 */
$entrega  = $tipo === 'entrega';
$imprimir = !empty($imprimir);

$archivo = static function (?string $nombre, bool $mini = false): string {
    $nombre = trim((string) $nombre);
    if ($nombre === '' || strpos($nombre, '..') !== false) {
        return '';
    }
    if ($mini) {
        $nombre = preg_replace('/\.([a-z0-9]+)$/i', '_mini.$1', $nombre);
    }
    $ruta = dirname(__DIR__) . '/public/image/' . $nombre;
    if (!is_file($ruta)) {
        return '';
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($ruta) : 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($ruta));
};

$estadoVenta = static function (array $v): array {
    if ($v['estado'] === 'cancelada') {
        return ['Cancelada', 'mal'];
    }
    if ((int) $v['pendientes'] <= 0) {
        return ['Entrega completa', 'ok'];
    }
    return (int) $v['entregadas'] > 0 ? ['Entrega parcial', 'medio'] : ['Pendiente', 'neutro'];
};

/** Negocios emisores (logotipo y nombre), sin repetir. */
$negocios = [];
foreach ($ventas as $v) {
    $negocios[(int) $v['empresa_id']] ??= ['nombre' => $v['empresa_nombre'], 'logo' => \App\Imagenes::logoPdfHtml($v['empresa_logo'], (string) $v['empresa_nombre'], 0.8)];
}

/** Pedidos por cliente. */
$porCliente = [];
foreach ($ventas as $v) {
    $c = (int) $v['cliente_id'];
    $porCliente[$c] ??= ['nombre' => $v['cliente_empresa'] ?: $v['cliente_nombre'], 'contacto' => $v['cliente_nombre'],
        'ubicacion' => $v['cliente_ubicacion'], 'telefono' => $v['cliente_telefono'], 'ventas' => []];
    $porCliente[$c]['ventas'][] = $v;
}

$referencia = $prep ? $prep['folio'] : 'Selección ' . date('d/m/Y H:i');
$piezas     = array_sum(array_column($grupos, 'total'));
$titulo     = $entrega ? 'Hoja de entrega' : 'Lista de preparación';
$pie        = static function (array $ventasCliente): string {
    $partes = [];
    foreach ($ventasCliente as $v) {
        $partes[] = trim((string) $v['condiciones_envio']);
    }
    return trim(implode(' ', array_unique(array_filter($partes))));
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= escaparValores($titulo) ?> · <?= escaparValores($referencia) ?></title>
    <style>
        @page { margin: 1.4cm 1.2cm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #33343a; }
        h1 { margin: 0; font-size: 18px; color: #2f5f8c; }
        h2 { margin: 1.1rem 0 .35rem; font-size: 13px; color: #2f5f8c; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .encabezado td { border: 0; padding: 0; vertical-align: top; }
        .encabezado { border-bottom: 2px solid #2f5f8c; margin-bottom: .6rem; padding-bottom: .4rem; }
        .logo { margin-right: 10px; }
        .negocio { font-size: 10px; color: #6b6b70; }
        .meta { text-align: right; font-size: 10px; line-height: 1.5; }
        .resumen td { border: 1px solid #d9dde3; background: #f4f7fa; padding: .35rem .5rem; font-size: 10px; }
        .resumen strong { font-size: 14px; display: block; color: #2f5f8c; }
        th { padding: .3rem; background: #eaf1f8; border: 1px solid #c7dcef; font-size: 8.5px; text-transform: uppercase; color: #2f5f8c; text-align: left; }
        td { padding: .3rem; border: 1px solid #e0e3e8; font-size: 10.5px; vertical-align: middle; }
        .num { text-align: right; } .centro { text-align: center; }
        .cant { font-size: 15px; font-weight: 700; text-align: center; }
        img.foto { width: 40px; height: 40px; }
        .caja { display: inline-block; width: 12px; height: 12px; border: 1px solid #33343a; }
        .chico { color: #6b6b70; font-size: 9.5px; }
        .cliente { page-break-inside: avoid; margin-bottom: .6rem; }
        .pedido { margin: .5rem 0 .15rem; font-size: 11px; }
        .estado { font-size: 9px; font-weight: 700; padding: 1px 6px; border: 1px solid #aaa; }
        .estado--ok { color: #1c6b3c; border-color: #1c6b3c; } .estado--medio { color: #8a5a00; border-color: #8a5a00; }
        .estado--mal { color: #a12a2a; border-color: #a12a2a; } .estado--neutro { color: #555; }
        .firma { margin-top: 1.8rem; width: 55%; border-top: 1px solid #33343a; padding-top: 3px; font-size: 9.5px; }
        .nota { margin-top: .5rem; padding: .35rem .5rem; background: #fffbea; border: 1px solid #ecd98a; font-size: 9.5px; }
        .pie { margin-top: 1rem; font-size: 8.5px; color: #8a8a90; text-align: center; }
        .barra { padding: .6rem 1rem; background: #eaf1f8; border-bottom: 1px solid #c7dcef; font-family: Helvetica, Arial, sans-serif; }
        .barra button { padding: .45rem 1.1rem; font-size: 13px; cursor: pointer; }
        .barra a { margin-left: 1rem; font-size: 12px; }
        <?php if ($imprimir) : ?>
        .pagina { padding: 1.2rem 1.5rem; max-width: 980px; margin: 0 auto; }
        @media print { .barra { display: none; } .pagina { padding: 0; max-width: none; } @page { margin: 1.2cm; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }
        <?php endif; ?>
    </style>
</head>
<body>
<?php if ($imprimir) : ?>
    <div class="barra">
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="javascript:window.close()" onclick="if(history.length>1){history.back();return false;}">Cerrar</a>
        <span class="chico">Imprimir no cambia existencias ni estados de las ventas.</span>
    </div>
    <div class="pagina">
<?php endif; ?>

    <table class="encabezado">
        <tr>
            <td>
                <?php foreach ($negocios as $n) : ?>
                    <?= $n['logo'] ?>
                <?php endforeach; ?>
                <h1><?= escaparValores($titulo) ?></h1>
                <p class="negocio"><?= escaparValores(implode(' · ', array_column($negocios, 'nombre'))) ?></p>
            </td>
            <td class="meta">
                <strong style="font-size:13px"><?= escaparValores($referencia) ?></strong><br>
                Preparación: <?= date('d/m/Y H:i') ?><br>
                <?php if ($prep) : ?>
                    Guardada: <?= date('d/m/Y', strtotime($prep['creado_en'])) ?><br>
                    Estado: <?= escaparValores(\App\Entregas::ESTADOS[$prep['estado']]['etiqueta']) ?>
                <?php else : ?>
                    Selección sin guardar
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php if ($prep && $prep['notas']) : ?><p class="chico" style="margin-bottom:.4rem">Nota: <?= escaparValores($prep['notas']) ?></p><?php endif; ?>

    <table class="resumen"><tr>
        <td><strong><?= count($ventas) ?></strong>pedido(s)</td>
        <td><strong><?= count($porCliente) ?></strong>cliente(s)</td>
        <td><strong><?= count($grupos) ?></strong>producto(s) distintos</td>
        <td><strong><?= (int) $piezas ?></strong>pieza(s) por entregar</td>
    </tr></table>

    <?php if (!$entrega) : ?>
        <h2>Productos consolidados</h2>
        <table>
            <thead><tr><th style="width:18px"></th><th style="width:44px">Foto</th><th>Código</th><th>Producto</th><th class="centro">Cantidad total</th><th>Origen (cantidad · pedido · cliente)</th></tr></thead>
            <tbody>
                <?php foreach ($grupos as $g) : $img = $archivo($g['imagen'], true); ?>
                    <tr>
                        <td class="centro"><span class="caja"></span></td>
                        <td class="centro"><?php if ($img !== '') : ?><img class="foto" src="<?= $img ?>" alt=""><?php endif; ?></td>
                        <td><strong><?= escaparValores($g['sku'] ?: '—') ?></strong></td>
                        <td><?= escaparValores($g['nombre']) ?></td>
                        <td class="cant"><?= (int) $g['total'] ?> <span class="chico"><?= escaparValores((string) $g['unidad']) ?></span></td>
                        <td class="chico"><?php foreach ($g['origenes'] as $o) : ?>
                            <?= (int) $o['cantidad'] ?> · <?= escaparValores($o['venta']) ?> · <?= escaparValores($o['cliente']) ?><br>
                        <?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$grupos) : ?><tr><td colspan="6" class="centro">Nada pendiente por preparar.</td></tr><?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?= $entrega ? 'Entrega por cliente y pedido' : 'Detalle por cliente y pedido' ?></h2>
    <?php foreach ($porCliente as $c) : ?>
        <div class="cliente">
            <p><strong><?= escaparValores($c['nombre']) ?></strong>
                <?= $c['contacto'] && $c['contacto'] !== $c['nombre'] ? ' · Contacto: ' . escaparValores($c['contacto']) : '' ?></p>
            <p class="chico"><?= escaparValores((string) $c['ubicacion']) ?: 'Sin dirección registrada' ?>
                <?= $c['telefono'] ? ' · Tel. ' . escaparValores($c['telefono']) : '' ?></p>
            <?php foreach ($c['ventas'] as $v) : [$etq, $clase] = $estadoVenta($v); ?>
                <p class="pedido"><strong><?= escaparValores($v['folio']) ?></strong>
                    <span class="chico">· <?= date('d/m/Y', strtotime($v['fecha'])) ?> · <?= escaparValores($v['empresa_nombre']) ?></span>
                    <span class="estado estado--<?= $clase ?>"><?= $etq ?></span></p>
                <table>
                    <thead><tr><th style="width:18px"></th><th>Código</th><th>Producto</th>
                        <th class="num">Pedido</th><th class="num">Entregado</th><th class="num">Por entregar</th></tr></thead>
                    <tbody>
                        <?php foreach ($v['lineas'] as $l) : ?>
                            <tr>
                                <td class="centro"><?php if ($l['pendiente'] > 0) : ?><span class="caja"></span><?php endif; ?></td>
                                <td><strong><?= escaparValores($l['sku'] ?: '—') ?></strong></td>
                                <td><?= escaparValores($l['nombre']) ?></td>
                                <td class="num"><?= (int) $l['vendido'] ?></td>
                                <td class="num"><?= (int) $l['entregado'] ?></td>
                                <td class="num"><strong><?= (int) $l['pendiente'] ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (trim((string) $v['comentarios']) !== '') : ?><p class="chico">Comentarios: <?= escaparValores($v['comentarios']) ?></p><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($pie($c['ventas']) !== '') : ?><p class="nota"><strong>Condiciones de envío:</strong> <?= nl2br(escaparValores($pie($c['ventas']))) ?></p><?php endif; ?>
            <?php if ($entrega) : ?><p class="firma">Recibí de conformidad (nombre, firma y fecha)</p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <p class="pie">Documento informativo · no modifica existencias ni registra entregas · Acero 360</p>
<?php if ($imprimir) : ?></div><?php endif; ?>
</body>
</html>

<?php
/**
 * templates/pdfEntregaDosCopias.php — Hoja de entrega en dos copias.
 *
 * Se imprime en una hoja carta HORIZONTAL. La hoja se divide con un corte
 * vertical al centro: la copia izquierda es para el cliente y la derecha para
 * el archivo. Cada copia ocupa media hoja (5.5 × 8.5 pulgadas).
 *
 * Si la venta tiene más productos de los que caben en media hoja, se generan
 * hojas de continuación: cada hoja conserva las dos copias y su línea de corte.
 *
 * Variables: $venta (App\Ventas), $lineas (Entregas::pendientesDeVentas),
 *            $fecha (texto ya formateado).
 */
$venta  = $venta ?? null;
$lineas = is_array($lineas ?? null) ? $lineas : [];
$fecha  = (string) ($fecha ?? date('d/m/Y H:i'));

if (!$venta) {
    return;
}

$logo = \App\Imagenes::logoPdfHtml($venta->empresa_logo, (string) $venta->empresa_nombre, 0.55);
$cliente = trim((string) ($venta->cliente_empresa ?: $venta->cliente_nombre));
$contacto = trim((string) $venta->cliente_nombre);
$direccion = trim((string) $venta->cliente_ubicacion);
$telefono = trim((string) $venta->cliente_telefono);
$condiciones = mb_strimwidth(trim((string) $venta->condiciones_envio), 0, 420, '…');
$comentarios = mb_strimwidth(trim((string) $venta->comentarios), 0, 240, '…');
$piezas = array_sum(array_map(static fn (array $l): int => (int) ($l['pendiente'] ?? 0), $lineas));

/** Renglones por copia para que quepan en media hoja. */
$porPagina = 12;
$chunks = array_chunk($lineas, $porPagina);
if (!$chunks) {
    $chunks = [[]];
}
$totalPaginas = count($chunks);

$dibujarCopia = static function (array $renglones, string $etiqueta, int $pagina) use (
    $venta, $logo, $cliente, $contacto, $direccion, $telefono, $condiciones, $comentarios, $fecha, $piezas, $totalPaginas
): void {
    ?>
    <div class="copia">
        <div class="copia__etiqueta"><?= escaparValores($etiqueta) ?></div>
        <table class="encabezado">
            <tr>
                <td class="encabezado__logo"><?= $logo ?></td>
                <td class="encabezado__titulo">
                    <h1>Hoja de entrega</h1>
                    <p class="negocio"><?= escaparValores((string) $venta->empresa_nombre) ?></p>
                    <p class="folio"><?= escaparValores((string) $venta->folio) ?></p>
                </td>
            </tr>
        </table>

        <table class="datos">
            <tr>
                <td class="datos__etiqueta">Cliente</td>
                <td><?= escaparValores($cliente) ?></td>
            </tr>
            <?php if ($contacto !== '' && $contacto !== $cliente) : ?>
                <tr><td class="datos__etiqueta">Contacto</td><td><?= escaparValores($contacto) ?></td></tr>
            <?php endif; ?>
            <tr>
                <td class="datos__etiqueta">Dirección</td>
                <td><?= escaparValores($direccion !== '' ? $direccion : 'Sin dirección registrada') ?></td>
            </tr>
            <?php if ($telefono !== '') : ?>
                <tr><td class="datos__etiqueta">Teléfono</td><td><?= escaparValores($telefono) ?></td></tr>
            <?php endif; ?>
            <tr>
                <td class="datos__etiqueta">Fecha</td>
                <td><?= escaparValores($fecha) ?> · <?= (int) $piezas ?> pieza(s)</td>
            </tr>
            <?php if (!empty($venta->entrega_fecha)) : ?>
                <tr><td class="datos__etiqueta">Entrega acordada</td><td><?= date('d/m/Y', strtotime((string) $venta->entrega_fecha)) ?></td></tr>
            <?php endif; ?>
        </table>

        <table class="productos">
            <thead>
                <tr>
                    <th class="chk"></th>
                    <th class="codigo">Código</th>
                    <th>Producto</th>
                    <th class="cantidad">Cantidad</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($renglones as $l) : ?>
                    <tr>
                        <td class="chk"><span class="caja"></span></td>
                        <td class="codigo"><?= escaparValores((string) ($l['sku'] ?: '—')) ?></td>
                        <td><?= escaparValores((string) $l['nombre']) ?></td>
                        <td class="cantidad"><strong><?= (int) $l['pendiente'] ?></strong> <?= escaparValores((string) $l['unidad']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$renglones) : ?>
                    <tr><td colspan="4" class="vacio">Sin productos pendientes.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($condiciones !== '') : ?>
            <p class="nota"><strong>Condiciones de envío:</strong> <?= nl2br(escaparValores($condiciones)) ?></p>
        <?php endif; ?>
        <?php if ($comentarios !== '') : ?>
            <p class="nota"><strong>Comentarios:</strong> <?= nl2br(escaparValores($comentarios)) ?></p>
        <?php endif; ?>

        <table class="firmas">
            <tr>
                <td>Entregó: ______________________________</td>
                <td>Recibió: ______________________________</td>
            </tr>
            <tr>
                <td colspan="2" class="firma-nota">Nombre, firma y fecha de quien recibe</td>
            </tr>
        </table>

        <p class="pie">
            Documento informativo · no modifica existencias ni registra entregas · Acero 360
            <?php if ($totalPaginas > 1) : ?> · Hoja <?= (int) $pagina ?> de <?= (int) $totalPaginas ?><?php endif; ?>
        </p>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Hoja de entrega · <?= escaparValores((string) $venta->folio) ?></title>
    <style>
        @page { size: letter landscape; margin: 0.30cm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #33343a; }
        .hoja { page-break-after: always; height: 100%; }
        .hoja:last-child { page-break-after: auto; }
        table.copias { width: 100%; height: 100%; border-collapse: collapse; }
        table.copias > tbody > tr { height: 100%; }
        table.copias > tbody > tr > td { width: 50%; height: 100%; vertical-align: top; padding: 0; }
        td.segunda { border-left: 1px dashed #6b6b70; }
        .corte { text-align: center; font-size: 6.5pt; color: #6b6b70; letter-spacing: .5px; padding: 0 0 2pt; }
        .copia { padding: 0 8pt 0 0; }
        td.segunda .copia { padding: 0 0 0 8pt; }
        .copia__etiqueta { font-size: 7pt; font-weight: 700; letter-spacing: .8px; color: #2f5f8c; text-transform: uppercase; margin-bottom: 1pt; }
        table.encabezado { width: 100%; border-collapse: collapse; border-bottom: 1.5px solid #2f5f8c; padding-bottom: 2pt; }
        table.encabezado td { border: 0; padding: 0; vertical-align: middle; }
        .encabezado__logo { width: 42pt; }
        .encabezado__logo img { max-width: 38pt; max-height: 30pt; }
        .encabezado__titulo { text-align: right; }
        h1 { margin: 0; font-size: 13pt; color: #2f5f8c; }
        .negocio { margin: 0; font-size: 7pt; color: #6b6b70; }
        .folio { margin: 0; font-size: 10pt; font-weight: 700; color: #33343a; }
        table.datos { width: 100%; border-collapse: collapse; margin-top: 3pt; }
        table.datos td { border: 0; padding: .7pt 0; vertical-align: top; font-size: 7.5pt; }
        .datos__etiqueta { width: 48pt; color: #6b6b70; font-weight: 700; }
        table.productos { width: 100%; border-collapse: collapse; margin-top: 3pt; }
        table.productos th { background: #eaf1f8; border: .5pt solid #c7dcef; padding: 1.5pt 2pt; font-size: 6.5pt; text-transform: uppercase; color: #2f5f8c; text-align: left; }
        table.productos td { border: .5pt solid #e0e3e8; padding: 1.5pt 2pt; font-size: 7.5pt; vertical-align: top; }
        .chk { width: 12pt; text-align: center; }
        .caja { display: inline-block; width: 7pt; height: 7pt; border: .7pt solid #33343a; }
        .codigo { width: 46pt; }
        .cantidad { width: 38pt; text-align: right; white-space: nowrap; }
        .vacio { text-align: center; color: #6b6b70; }
        .nota { margin: 2.5pt 0 0; font-size: 6.8pt; line-height: 1.25; color: #555; }
        table.firmas { width: 100%; border-collapse: collapse; margin-top: 10pt; }
        table.firmas td { border: 0; padding: 0 6pt 0 0; font-size: 7.2pt; vertical-align: bottom; }
        .firma-nota { text-align: center; font-size: 6.3pt; color: #6b6b70; padding-top: 1pt !important; }
        .pie { margin-top: 5pt; font-size: 5.8pt; color: #8a8a90; text-align: center; }
    </style>
</head>
<body>
<?php foreach ($chunks as $indice => $chunk) : ?>
    <div class="hoja">
        <table class="copias">
            <tr>
                <td>
                    <?php $dibujarCopia($chunk, 'Original — Cliente', $indice + 1); ?>
                </td>
                <td class="segunda">
                    <div class="corte">— Cortar aquí —</div>
                    <?php $dibujarCopia($chunk, 'Copia — Archivo', $indice + 1); ?>
                </td>
            </tr>
        </table>
    </div>
<?php endforeach; ?>
</body>
</html>

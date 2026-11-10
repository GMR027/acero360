<?php
/**
 * templates/pdfActaEntrega.php — Acta de entrega de una venta (PDF).
 *
 * Se genera desde /admin/entregas-seguimiento/venta, para todas las entregas
 * de la venta o para una sola. Incluye fecha, negocio emisor, datos del
 * cliente, productos y cantidades entregadas, estado de las incidencias,
 * comentarios, fotos y ubicación de cada entrega.
 *
 * Las imágenes se incrustan en base64: dompdf se configura con
 * isRemoteEnabled = false y nunca sale a Internet.
 *
 * Variables: $venta (App\Ventas), $entregas, $incidencias, $fecha.
 */
$venta       = $venta ?? null;
$entregas    = is_array($entregas ?? null) ? $entregas : [];
$incidencias = is_array($incidencias ?? null) ? $incidencias : [];
$fecha       = (string) ($fecha ?? date('d/m/Y H:i'));

$logo = $venta ? \App\Imagenes::logoPdfHtml($venta->empresa_logo, (string) $venta->empresa_nombre, 0.8) : '';
$cliente = $venta ? ((string) $venta->cliente_empresa !== '' ? $venta->cliente_empresa : $venta->cliente_nombre) : '';
$totalFotos = 0;
foreach ($entregas as $entrega) {
    $totalFotos += count($entrega['fotos'] ?? []);
}
$abiertas = count(array_filter($incidencias, static fn (array $i): bool => ($i['estado'] ?? '') === 'abierta'));

/** Imagen incrustada como data URI ('' si el archivo ya no existe). */
$fotoData = static function (string $archivo): string {
    $archivo = basename(trim($archivo));
    if ($archivo === '') {
        return '';
    }
    $ruta = \App\Imagenes::CARPETA . '/' . $archivo;
    if (!is_file($ruta)) {
        return '';
    }
    $mime = function_exists('mime_content_type') ? (string) mime_content_type($ruta) : 'image/jpeg';
    return 'data:' . ($mime !== '' ? $mime : 'image/jpeg') . ';base64,' . base64_encode((string) file_get_contents($ruta));
};

/** Fecha de la toma o, si la foto no trae EXIF, la de registro de la entrega. */
$fechaFoto = static function (array $foto, array $entrega): string {
    $tomada = trim((string) ($foto['tomada_en'] ?? ''));
    if ($tomada !== '' && strtotime($tomada)) {
        return 'Foto tomada el ' . date('d/m/Y H:i', strtotime($tomada));
    }
    foreach (['creado_en', 'fecha'] as $campo) {
        $valor = trim((string) ($entrega[$campo] ?? ''));
        if ($valor !== '' && strtotime($valor)) {
            return 'Fecha de registro de la entrega: ' . date('d/m/Y H:i', strtotime($valor));
        }
    }
    return 'Sin fecha de toma en la foto.';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acta de entrega · <?= escaparValores((string) ($venta->folio ?? '')) ?></title>
    <style>
        @page { margin: 1.3cm 1.1cm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10.5px; color: #33343a; }
        h1 { margin: 0; font-size: 18px; color: #2f5f8c; }
        h2 { margin: 1rem 0 .3rem; font-size: 14px; color: #2f5f8c; }
        h3 { margin: .8rem 0 .2rem; font-size: 11.5px; color: #2f5f8c; }
        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .encabezado td { border: 0; padding: 0; vertical-align: top; }
        .encabezado { border-bottom: 2px solid #2f5f8c; margin-bottom: .5rem; padding-bottom: .4rem; }
        .negocio { font-size: 9.5px; color: #6b6b70; }
        .meta { text-align: right; font-size: 9.5px; line-height: 1.5; }
        .bloque { border: 1px solid #d9dde3; background: #f7f9fb; padding: .45rem .6rem; margin-top: .5rem; }
        .bloque strong { color: #2f5f8c; }
        .chico { color: #6b6b70; font-size: 9px; }
        .entrega { page-break-inside: avoid; margin-top: .7rem; border-top: 1px solid #c7dcef; padding-top: .4rem; }
        th { padding: .25rem .35rem; background: #eaf1f8; border: 1px solid #c7dcef; font-size: 8.5px; text-transform: uppercase; color: #2f5f8c; text-align: left; }
        td { padding: .25rem .35rem; border: 1px solid #e0e3e8; font-size: 10px; vertical-align: top; }
        .num { text-align: right; }
        .estado { font-size: 8.5px; font-weight: 700; padding: 1px 6px; border: 1px solid #aaa; }
        .estado--abierta { color: #a12a2a; border-color: #a12a2a; background: #fdf0f0; }
        .estado--resuelta { color: #1c6b3c; border-color: #1c6b3c; background: #f0faf3; }
        .estado--cancelada { color: #555; background: #f3f3f3; }
        .incidencia { margin-top: .35rem; padding: .35rem .5rem; border-left: 3px solid #c7a24b; background: #fffbea; }
        .fotos { width: 100%; margin-top: .4rem; }
        .fotos td { border: 0; padding: 0 .3rem .45rem 0; width: 50%; vertical-align: top; }
        .foto { width: 100%; max-height: 240px; }
        .foto-pie { font-size: 8.5px; color: #555; margin-top: 2px; }
        .firma { margin-top: 1.6rem; width: 55%; border-top: 1px solid #33343a; padding-top: 3px; font-size: 9px; }
        .pie { margin-top: .9rem; font-size: 8px; color: #8a8a90; text-align: center; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td>
                <?= $logo ?>
                <h1>Acta de entrega</h1>
                <p class="negocio">
                    <?= escaparValores((string) $venta->empresa_nombre) ?>
                    <?php if (!empty($venta->empresa_rfc)) : ?> · RFC <?= escaparValores((string) $venta->empresa_rfc) ?><?php endif; ?>
                    <?php if (!empty($venta->empresa_direccion)) : ?><br><?= escaparValores((string) $venta->empresa_direccion) ?><?php endif; ?>
                    <?php if (!empty($venta->empresa_telefono) || !empty($venta->empresa_email)) : ?>
                        <br><?= escaparValores(trim((string) $venta->empresa_telefono . ' · ' . (string) $venta->empresa_email, ' ·')) ?>
                    <?php endif; ?>
                </p>
            </td>
            <td class="meta">
                <strong style="font-size:13px"><?= escaparValores((string) $venta->folio) ?></strong><br>
                Venta: <?= $venta->fecha ? date('d/m/Y', strtotime((string) $venta->fecha)) : '—' ?><br>
                Acta generada: <?= escaparValores($fecha) ?><br>
                <?= count($entregas) ?> entrega<?= count($entregas) === 1 ? '' : 's' ?> · <?= $totalFotos ?> foto<?= $totalFotos === 1 ? '' : 's' ?>
            </td>
        </tr>
    </table>

    <div class="bloque">
        <strong>Cliente:</strong> <?= escaparValores((string) $cliente) ?>
        <?php if (!empty($venta->cliente_nombre) && (string) $venta->cliente_nombre !== $cliente) : ?>
            · contacto: <?= escaparValores((string) $venta->cliente_nombre) ?>
        <?php endif; ?>
        <?php if (!empty($venta->cliente_rfc)) : ?> · RFC <?= escaparValores((string) $venta->cliente_rfc) ?><?php endif; ?>
        <br>
        <span class="chico">
            <?php if (!empty($venta->cliente_telefono)) : ?>Tel. <?= escaparValores((string) $venta->cliente_telefono) ?> · <?php endif; ?>
            <?php if (!empty($venta->cliente_email)) : ?><?= escaparValores((string) $venta->cliente_email) ?> · <?php endif; ?>
            <?= escaparValores((string) ($venta->cliente_ubicacion !== '' ? $venta->cliente_ubicacion : 'Sin dirección registrada')) ?>
        </span>
    </div>

    <div class="bloque">
        <strong>Estado de las incidencias:</strong>
        <?= $incidencias ? count($incidencias) . ' reportada' . (count($incidencias) === 1 ? '' : 's') : 'Sin incidencias' ?>
        <?php if ($abiertas > 0) : ?>
            · <span class="estado estado--abierta"><?= $abiertas ?> abierta<?= $abiertas === 1 ? '' : 's' ?></span>
        <?php endif; ?>
        <br>
        <span class="chico">
            <?php if (!empty($venta->entrega_fecha)) : ?>Fecha de entrega acordada: <?= date('d/m/Y', strtotime((string) $venta->entrega_fecha)) ?>. <?php endif; ?>
            <?= escaparValores((string) ($venta->entrega_observaciones !== '' ? $venta->entrega_observaciones : 'Sin observaciones generales de entrega.')) ?>
        </span>
    </div>

    <?php if (!$entregas) : ?>
        <p class="chico" style="margin-top:.8rem">Esta venta no tiene entregas registradas.</p>
    <?php endif; ?>

    <?php foreach ($entregas as $entrega) :
        $fechaEntrega = !empty($entrega['fecha']) && strtotime((string) $entrega['fecha'])
            ? date('d/m/Y', strtotime((string) $entrega['fecha']))
            : 'sin fecha';
        $fotos = is_array($entrega['fotos'] ?? null) ? $entrega['fotos'] : [];
        $renglones = is_array($entrega['renglones'] ?? null) ? $entrega['renglones'] : [];
        $manual = (string) ($entrega['ubicacion_maps'] ?? '');
        $manualLat = $manual !== '' && ($entrega['ubicacion_lat'] ?? null) !== null ? (float) $entrega['ubicacion_lat'] : null;
        $manualLon = $manual !== '' && ($entrega['ubicacion_lon'] ?? null) !== null ? (float) $entrega['ubicacion_lon'] : null;
        $primeraFoto = $fotos[0] ?? [];
        $ubicacion = \App\FotosEntrega::descripcionUbicacion(
            $primeraFoto,
            (string) $venta->cliente_ubicacion,
            $manual,
            $manualLat,
            $manualLon
        );

        $propias = array_values(array_filter(
            $incidencias,
            static fn (array $i): bool => (int) ($i['entrega_id'] ?? 0) === (int) $entrega['id']
        ));
    ?>
        <div class="entrega">
            <h2>Entrega del <?= escaparValores($fechaEntrega) ?></h2>
            <p class="chico">
                Registró <?= escaparValores((string) ($entrega['usuario_nombre'] ?? '—')) ?>
                <?php if (!empty($entrega['creado_en'])) : ?> · <?= date('d/m/Y H:i', strtotime((string) $entrega['creado_en'])) ?><?php endif; ?>
                · <?= count($renglones) ?> producto<?= count($renglones) === 1 ? '' : 's' ?> · <?= count($fotos) ?> foto<?= count($fotos) === 1 ? '' : 's' ?>
            </p>

            <?php if ($renglones) : ?>
                <table style="margin-top:.3rem">
                    <thead><tr><th>Producto</th><th>Código</th><th class="num">Cantidad entregada</th></tr></thead>
                    <tbody>
                        <?php foreach ($renglones as $renglon) : ?>
                            <tr>
                                <td><?= escaparValores((string) ($renglon['producto_nombre'] ?? '')) ?></td>
                                <td><?= escaparValores((string) ($renglon['sku'] ?? '—')) ?></td>
                                <td class="num"><?= (int) ($renglon['cantidad'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="chico">Sin renglones de productos en esta entrega.</p>
            <?php endif; ?>

            <p style="margin-top:.35rem"><strong>Ubicación:</strong> <?= escaparValores($ubicacion['texto']) ?></p>
            <?php if ($manual !== '') : ?>
                <p class="chico">Ubicación capturada al registrar la entrega: <?= escaparValores($manual) ?></p>
            <?php endif; ?>
            <p><strong>Comentarios:</strong> <?= escaparValores((string) (($entrega['notas'] ?? '') !== '' ? $entrega['notas'] : 'Sin comentarios.')) ?></p>

            <?php if ($propias) : ?>
                <h3>Incidencias de esta entrega</h3>
                <?php foreach ($propias as $incidencia) :
                    $motivo = \App\IncidenciasEntrega::MOTIVOS[$incidencia['motivo']] ?? null;
                    $estado = \App\IncidenciasEntrega::ESTADOS[$incidencia['estado']] ?? null;
                ?>
                    <div class="incidencia">
                        <strong><?= escaparValores((string) ($motivo['etiqueta'] ?? $incidencia['motivo'])) ?></strong>
                        <span class="estado estado--<?= escaparValores((string) $incidencia['estado']) ?>"><?= escaparValores((string) ($estado['etiqueta'] ?? $incidencia['estado'])) ?></span>
                        <?php if (!empty($incidencia['notas'])) : ?><br><?= nl2br(escaparValores((string) $incidencia['notas'])) ?><?php endif; ?>
                        <?php if (!empty($incidencia['resolucion'])) : ?><br><span class="chico">Resolución: <?= escaparValores((string) $incidencia['resolucion']) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="chico">Sin incidencias reportadas para esta entrega.</p>
            <?php endif; ?>

            <?php if ($fotos) : ?>
                <h3>Fotos del acta</h3>
                <table class="fotos">
                    <?php foreach (array_chunk($fotos, 2) as $par) : ?>
                        <tr>
                            <?php foreach ($par as $foto) :
                                $src = $fotoData((string) ($foto['archivo'] ?? ''));
                                $detalle = \App\FotosEntrega::descripcionUbicacion(
                                    $foto,
                                    (string) $venta->cliente_ubicacion,
                                    $manual,
                                    $manualLat,
                                    $manualLon
                                );
                            ?>
                                <td>
                                    <?php if ($src !== '') : ?>
                                        <img class="foto" src="<?= $src ?>" alt="Foto de la entrega del <?= escaparValores($fechaEntrega) ?>">
                                    <?php else : ?>
                                        <span class="chico">La foto ya no está disponible.</span>
                                    <?php endif; ?>
                                    <p class="foto-pie"><?= escaparValores($fechaFoto($foto, $entrega)) ?><br>
                                        <?= escaparValores($detalle['texto']) ?></p>
                                </td>
                            <?php endforeach; ?>
                            <?php if (count($par) === 1) : ?><td></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php
    // Incidencias sin entrega asociada (sólo se muestran si se exporta la venta completa).
    $sueltas = array_values(array_filter(
        $incidencias,
        static fn (array $i): bool => (int) ($i['entrega_id'] ?? 0) === 0
    ));
    ?>
    <?php if ($sueltas) : ?>
        <div class="entrega">
            <h2>Otras incidencias de la venta</h2>
            <?php foreach ($sueltas as $incidencia) :
                $motivo = \App\IncidenciasEntrega::MOTIVOS[$incidencia['motivo']] ?? null;
                $estado = \App\IncidenciasEntrega::ESTADOS[$incidencia['estado']] ?? null;
            ?>
                <div class="incidencia">
                    <strong><?= escaparValores((string) ($motivo['etiqueta'] ?? $incidencia['motivo'])) ?></strong>
                    <span class="estado estado--<?= escaparValores((string) $incidencia['estado']) ?>"><?= escaparValores((string) ($estado['etiqueta'] ?? $incidencia['estado'])) ?></span>
                    <?php if (!empty($incidencia['notas'])) : ?><br><?= nl2br(escaparValores((string) $incidencia['notas'])) ?><?php endif; ?>
                    <?php if (!empty($incidencia['resolucion'])) : ?><br><span class="chico">Resolución: <?= escaparValores((string) $incidencia['resolucion']) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="firma">Recibió (nombre, firma y fecha)</div>

    <p class="pie">
        Acta generada desde el seguimiento de entregas de <?= escaparValores((string) $venta->empresa_nombre) ?>.
        Las fechas de las fotos sin EXIF corresponden al registro de la entrega; no se inventan fechas de toma.
    </p>
</body>
</html>

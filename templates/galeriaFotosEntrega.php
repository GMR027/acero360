<?php
/**
 * templates/galeriaFotosEntrega.php — Fotos del acta de UNA entrega.
 *
 * Compartido por el detalle de la venta y por el seguimiento de la entrega
 * para que las dos páginas muestren la misma información: fecha de la toma (o
 * de registro de la entrega cuando la foto no trae EXIF), ubicación de cada
 * foto y sus botones de Google Maps y Apple Maps.
 *
 * Variables:
 *   $entrega          (array) Fila de Entregas::entregasDeVenta() con
 *                              «fotos», «fecha», «creado_en», «ubicacion_maps»,
 *                              «ubicacion_lat», «ubicacion_lon» y
 *                              «usuario_nombre».
 *   $direccionCliente (string, opcional) Dirección registrada del cliente.
 *   $conDestacado     (bool, opcional) Muestra la cabecera con la fecha y el
 *                              usuario que registró la entrega.
 */
$entrega          = $entrega ?? [];
$direccionCliente = trim((string) ($direccionCliente ?? ''));
$conDestacado     = (bool) ($conDestacado ?? true);

$fotos = is_array($entrega['fotos'] ?? null) ? $entrega['fotos'] : [];
$fechaEntrega = !empty($entrega['fecha']) && strtotime((string) $entrega['fecha'])
    ? date('d/m/Y', strtotime((string) $entrega['fecha']))
    : '';
$registroEntrega = '';
foreach (['creado_en', 'fecha'] as $campo) {
    $valor = trim((string) ($entrega[$campo] ?? ''));
    if ($valor !== '' && strtotime($valor)) {
        $registroEntrega = date('d/m/Y H:i', strtotime($valor));
        break;
    }
}

$manual    = (string) ($entrega['ubicacion_maps'] ?? '');
$manualLat = $manual !== '' && ($entrega['ubicacion_lat'] ?? null) !== null ? (float) $entrega['ubicacion_lat'] : null;
$manualLon = $manual !== '' && ($entrega['ubicacion_lon'] ?? null) !== null ? (float) $entrega['ubicacion_lon'] : null;
?>
<div class="seguimiento-fotos">
    <?php if ($conDestacado) : ?>
        <p class="texto-sm mb-0">
            <strong>Entrega<?= $fechaEntrega !== '' ? ' del ' . $fechaEntrega : '' ?></strong>
            <span class="texto-secundario">
                · <?= count($fotos) ?> foto<?= count($fotos) === 1 ? '' : 's' ?>
                · registró <?= escaparValores($entrega['usuario_nombre'] ?? '—') ?>
            </span>
        </p>
    <?php endif; ?>

    <?php if ($fotos) : ?>
        <ul class="seguimiento-fotos__lista">
            <?php foreach ($fotos as $indice => $foto) :
                $rutaFoto    = \App\Imagenes::url($foto['archivo']);
                $rutaMarcada = \App\FotosEntrega::url($foto['archivo']);
                $ubicacion   = \App\FotosEntrega::descripcionUbicacion(
                    $foto,
                    $direccionCliente,
                    $manual,
                    $manualLat,
                    $manualLon
                );
                $tituloFoto = 'Foto ' . ($indice + 1) . ($fechaEntrega !== '' ? ' de la entrega del ' . $fechaEntrega : '');

                // La fecha EXIF es la de la toma. Si no existe, se muestra la
                // fecha de registro de la entrega, nunca una fecha inventada.
                $tomadaEn = trim((string) ($foto['tomada_en'] ?? ''));
                if ($tomadaEn !== '' && strtotime($tomadaEn)) {
                    $fechaTexto = 'Foto tomada el ' . date('d/m/Y H:i', strtotime($tomadaEn));
                } elseif ($registroEntrega !== '') {
                    $fechaTexto = 'Fecha de registro de la entrega: ' . $registroEntrega;
                } else {
                    $fechaTexto = 'Sin fecha de toma en la foto.';
                }
            ?>
                <li class="seguimiento-fotos__item">
                    <button type="button" class="seguimiento-fotos__enlace"
                            data-ampliar-imagen="<?= escaparValores($rutaMarcada) ?>"
                            data-ampliar-sobre="Fotos de la entrega"
                            data-ampliar-titulo="<?= escaparValores($tituloFoto) ?>"
                            title="Ver la <?= escaparValores($tituloFoto) ?>"
                            aria-label="Ampliar la <?= escaparValores($tituloFoto) ?>">
                        <img class="seguimiento-fotos__imagen" src="<?= escaparValores($rutaFoto) ?>"
                             alt="<?= escaparValores($tituloFoto) ?>"
                             loading="lazy">
                    </button>

                    <p class="seguimiento-fotos__meta texto-xs mb-0">
                        <span class="seguimiento-fotos__fecha"><?= escaparValores($fechaTexto) ?></span><br>
                        <span class="texto-secundario"><?= escaparValores($ubicacion['texto']) ?></span>
                    </p>

                    <?php if (isset($ubicacion['google']) || isset($ubicacion['apple'])) : ?>
                        <div class="seguimiento-fotos__mapas">
                            <?php if (isset($ubicacion['google'])) : ?>
                                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($ubicacion['google']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="Abrir en Google Maps la ubicación de la <?= escaparValores($tituloFoto) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">location_on</span>
                                    <span>Google Maps</span>
                                </a>
                            <?php endif; ?>
                            <?php if (isset($ubicacion['apple'])) : ?>
                                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($ubicacion['apple']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="Abrir en Apple Maps la ubicación de la <?= escaparValores($tituloFoto) ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem">map</span>
                                    <span>Apple Maps</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="texto-sm texto-secundario">Sin fotos adjuntas en esta entrega.</p>
    <?php endif; ?>

    <?php
    $mapasEntrega = \App\FotosEntrega::urlsMapa(
        $fotos[0] ?? [],
        $direccionCliente,
        $manual,
        $manualLat,
        $manualLon
    );
    ?>
    <?php if ($manual !== '' || ($manualLat !== null && $manualLon !== null)) : ?>
        <p class="texto-xs texto-secundario mb-0">
            Ubicación de la entrega: <?= escaparValores($manual !== '' ? $manual : number_format($manualLat, 6, '.', '') . ', ' . number_format($manualLon, 6, '.', '')) ?>
        </p>
    <?php endif; ?>
    <?php if ($mapasEntrega) : ?>
        <div class="seguimiento-fotos__acciones">
            <?php if (isset($mapasEntrega['google'])) : ?>
                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($mapasEntrega['google']) ?>"
                   target="_blank" rel="noopener noreferrer"
                   aria-label="Abrir la ubicación de la entrega en Google Maps">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">location_on</span>
                    <span>Google Maps</span>
                </a>
            <?php endif; ?>
            <?php if (isset($mapasEntrega['apple'])) : ?>
                <a class="btn btn--secundario btn--sm" href="<?= escaparValores($mapasEntrega['apple']) ?>"
                   target="_blank" rel="noopener noreferrer"
                   aria-label="Abrir la ubicación de la entrega en Apple Maps">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">map</span>
                    <span>Apple Maps</span>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

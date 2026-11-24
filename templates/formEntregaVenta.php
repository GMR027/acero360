<?php
/**
 * templates/formEntregaVenta.php — Formulario de registro de entrega de UNA venta.
 *
 * Compartido por la página suelta /admin/entregas/entregar y por el modal del
 * hub «Operación Comercial» (seguimiento de la venta): una sola marca de
 * campos en los dos contextos.
 *
 * NO trae botones de acción por defecto: cada contexto pone los suyos (la
 * página suelta, Cancelar + Registrar; el modal, el pie del propio diálogo).
 * Con $conAcciones = true y $hrefCancelar, dibuja el pie de la página suelta.
 *
 * Variables: $ventaId (int), $lineas (pendientes de Entregas::pendientesDeVentas),
 *            $token (string), $volver (string: 'seguimiento' o ''),
 *            $prepId (int, 0 = sin preparación),
 *            $conAcciones (bool, opcional), $hrefCancelar (string, opcional).
 *
 * Es multipart/form-data porque admite una o varias fotos del acta (campo
 * «fotos[]», opcional): con JS el modal envía el mismo formulario con su pie.
 */
$lineas   = $lineas ?? [];
$volver   = $volver ?? '';
$prepId   = (int) ($prepId ?? 0);
$conAcciones = (bool) ($conAcciones ?? false);
$hrefCancelar = (string) ($hrefCancelar ?? '');
$etiqueta = 'entregar-' . (int) $ventaId;

// Ayuda de las fotos: los números salen de las constantes para que el texto no
// se quede viejo si cambian los topes (15 MB por archivo, 8 fotos y 120 MB en total).
$megasFoto   = (int) ceil(\App\Imagenes::TAMANO_MAXIMO_ENTREGA / 1048576);
$maximoFotos = \App\Entregas::MAX_FOTOS;
$megasTotal  = \App\Entregas::MAX_FOTOS_TOTAL_MB;
?>
<form class="formulario" action="/admin/entregas/entregar" method="post" enctype="multipart/form-data" novalidate data-comprimir-fotos>
    <input type="hidden" name="venta" value="<?= (int) $ventaId ?>">
    <input type="hidden" name="prep" value="<?= $prepId ?>">
    <?php if ($volver !== '') : ?>
        <input type="hidden" name="volver" value="<?= escaparValores($volver) ?>">
    <?php endif; ?>
    <input type="hidden" name="token" value="<?= escaparValores($token) ?>">

    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha-<?= $etiqueta ?>">Fecha de entrega</label>
        <input class="control" type="date" id="fecha-<?= $etiqueta ?>" name="fecha" required value="<?= date('Y-m-d') ?>">
    </div>

    <p class="campo__ayuda mb-md">Captura lo que realmente se entregó. Lo que quede en 0 sigue pendiente.</p>
    <div class="linea-form" role="group" aria-label="Productos pendientes de entregar">
        <?php foreach ($lineas as $l) : ?>
            <div class="linea-form__item">
                <div class="linea-form__producto">
                    <strong><?= escaparValores($l['nombre']) ?></strong>
                    <small><?= escaparValores((string) $l['sku']) ?> · <?= escaparValores((string) ($l['unidad'] ?? 'pza')) ?></small>
                </div>
                <div class="linea-form__datos">
                    <span class="linea-form__dato"><small>Vendido</small><strong><?= (int) $l['vendido'] ?></strong></span>
                    <span class="linea-form__dato"><small>Entregado</small><strong><?= (int) $l['entregado'] ?></strong></span>
                    <span class="linea-form__dato"><small>Pendiente</small><strong><?= (int) $l['pendiente'] ?></strong></span>
                </div>
                <div class="linea-form__campos">
                    <label class="linea-form__campo">
                        <span>Entregar ahora</span>
                        <input class="control" type="number" min="0" max="<?= (int) $l['pendiente'] ?>" step="1" inputmode="numeric"
                               name="cantidad[<?= (int) $l['producto_id'] ?>]" value="<?= (int) $l['pendiente'] ?>"
                               <?= (int) $l['pendiente'] === 0 ? 'disabled' : '' ?>
                               aria-label="Piezas entregadas de <?= escaparValores($l['nombre']) ?>">
                    </label>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="campo campo--completo mt-lg">
        <label class="campo__etiqueta" for="notas-<?= $etiqueta ?>">Notas de la entrega</label>
        <input class="control" type="text" id="notas-<?= $etiqueta ?>" name="notas" maxlength="255" placeholder="Quién recibió, observaciones…">
    </div>

    <div class="campo campo--completo mt-lg">
        <label class="campo__etiqueta" for="fotos-<?= $etiqueta ?>">Fotos de la entrega (opcional)</label>
        <input class="control" type="file" id="fotos-<?= $etiqueta ?>" name="fotos[]"
               data-max-bytes="<?= (int) \App\Imagenes::TAMANO_MAXIMO_ENTREGA ?>"
               multiple accept="image/jpeg,image/png,image/webp" aria-describedby="ayuda-fotos-<?= $etiqueta ?>">
        <span class="campo__ayuda" id="ayuda-fotos-<?= $etiqueta ?>">
            Una o varias fotografías del acta (quién recibe, el material, el estado en que llegó):
            JPG, PNG o WebP de hasta <?= $megasFoto ?> MB cada una, un máximo de <?= $maximoFotos ?> fotos
            y <?= $megasTotal ?> MB en total. Se comprimen antes de enviarlas cuando el navegador puede;
            la proporción se conserva sin recortar y el GPS/fecha de la foto se lee antes de comprimir.
        </span>
        <span class="campo__ayuda" data-estado-fotos hidden></span>
    </div>

    <div class="campo campo--completo mt-lg">
        <label class="campo__etiqueta" for="ubicacion-<?= $etiqueta ?>">Ubicación de la entrega (Google Maps)</label>
        <input class="control" type="text" id="ubicacion-<?= $etiqueta ?>" name="ubicacion" maxlength="500"
               placeholder="Pega el enlace de Google Maps, las coordenadas o la dirección">
        <button class="btn btn--secundario btn--sm mt-sm" type="button" data-usar-ubicacion="#ubicacion-<?= $etiqueta ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">my_location</span>
            <span>Usar mi ubicación actual</span>
        </button>
        <span class="campo__ayuda">
            Se usa cuando las fotos no traen GPS. Acepta un enlace de Google Maps, coordenadas
            (por ejemplo 24.1426667, -110.3127500) o una dirección. El GPS de la foto siempre tiene prioridad.
        </span>
        <span class="campo__ayuda" data-estado-ubicacion hidden></span>
    </div>

    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="<?= escaparValores($hrefCancelar) ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-un-envio>Confirmar entrega</button>
        </div>
    <?php endif; ?>
</form>

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
// se quede viejo si cambian los topes (6 MB por archivo, 8 fotos y 20 MB en total).
$megasFoto   = (int) ceil(\App\Imagenes::TAMANO_MAXIMO / 1048576);
$maximoFotos = \App\Entregas::MAX_FOTOS;
$megasTotal  = \App\Entregas::MAX_FOTOS_TOTAL_MB;
?>
<form class="formulario" action="/admin/entregas/entregar" method="post" enctype="multipart/form-data" novalidate>
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
    <div class="tabla-contenedor">
        <table class="tabla">
            <thead><tr><th scope="col">Producto</th><th scope="col" class="tabla--numerica">Vendido</th>
                <th scope="col" class="tabla--numerica">Ya entregado</th><th scope="col" class="tabla--numerica">Pendiente</th>
                <th scope="col" class="tabla--numerica">Entregar ahora</th></tr></thead>
            <tbody>
                <?php foreach ($lineas as $l) : ?>
                    <tr>
                        <td><strong><?= escaparValores($l['nombre']) ?></strong>
                            <span class="texto-xs texto-secundario"><br><?= escaparValores((string) $l['sku']) ?></span></td>
                        <td class="tabla--numerica cifra"><?= (int) $l['vendido'] ?></td>
                        <td class="tabla--numerica cifra"><?= (int) $l['entregado'] ?></td>
                        <td class="tabla--numerica cifra"><?= (int) $l['pendiente'] ?></td>
                        <td><input class="control" type="number" min="0" max="<?= (int) $l['pendiente'] ?>" step="1" inputmode="numeric"
                                   name="cantidad[<?= (int) $l['producto_id'] ?>]" value="<?= (int) $l['pendiente'] ?>"
                                   <?= (int) $l['pendiente'] === 0 ? 'disabled' : '' ?>
                                   aria-label="Piezas entregadas de <?= escaparValores($l['nombre']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="campo campo--completo mt-lg">
        <label class="campo__etiqueta" for="notas-<?= $etiqueta ?>">Notas de la entrega</label>
        <input class="control" type="text" id="notas-<?= $etiqueta ?>" name="notas" maxlength="255" placeholder="Quién recibió, observaciones…">
    </div>

    <div class="campo campo--completo mt-lg">
        <label class="campo__etiqueta" for="fotos-<?= $etiqueta ?>">Fotos de la entrega (opcional)</label>
        <input class="control" type="file" id="fotos-<?= $etiqueta ?>" name="fotos[]"
               multiple accept="image/jpeg,image/png,image/webp" aria-describedby="ayuda-fotos-<?= $etiqueta ?>">
        <span class="campo__ayuda" id="ayuda-fotos-<?= $etiqueta ?>">
            Una o varias fotografías del acta (quién recibe, el material, el estado en que llegó):
            JPG, PNG o WebP de hasta <?= $megasFoto ?> MB cada una, un máximo de <?= $maximoFotos ?> fotos
            y <?= $megasTotal ?> MB en total. Se guardan con la entrega y se ven en el seguimiento de la venta
            («Fotos de la entrega»), donde se abren a tamaño completo; la proporción se conserva sin recortar.
        </span>
    </div>

    <?php if ($conAcciones) : ?>
        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="<?= escaparValores($hrefCancelar) ?>">Cancelar</a>
            <button class="btn btn--primario" type="submit" data-un-envio>Confirmar entrega</button>
        </div>
    <?php endif; ?>
</form>

<?php
/**
 * templates/formularioProveedorEntradas.php — Alta y edición de una entrada
 * de material por parte de un proveedor.
 *
 * Es el documento que se archiva como "reporte fotográfico": el encabezado
 * (proveedor + tipo), la descripción, la fotografía de la entrega y las
 * notas adicionales.
 *
 * Variables esperadas:
 *   $entrada     — instancia de App\ProveedorEntradas (nueva o existente)
 *   $proveedores — array para el <select> (App\Proveedores::paraSelector())
 *   $errores     — array de mensajes de validación del servidor
 */

$entrada     = $entrada ?? new \App\ProveedorEntradas();
$proveedores = $proveedores ?? [];
$errores     = $errores ?? [];

/** Primer mensaje de error que menciona un campo concreto. */
$errorDeCampo = static function (string $clave) use ($errores): string {
    foreach ($errores as $error) {
        if (stripos($error, $clave) !== false) {
            return $error;
        }
    }
    return '';
};

/** Marca un campo como con error y devuelve la clase correspondiente. */
$claseError = static function (string $clave) use ($errorDeCampo): string {
    return $errorDeCampo($clave) !== '' ? ' campo--con-error' : '';
};
?>

<!-- Errores generales del formulario -->
<?php if (!empty($errores)) : ?>
    <div class="nota nota--error mb-md" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span>
            <?php foreach ($errores as $indice => $error) : ?>
                <?= $indice > 0 ? '<br>' : '' ?><?= escaparValores($error) ?>
            <?php endforeach; ?>
        </span>
    </div>
<?php endif; ?>

<!-- Encabezado de la entrada -->
<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Encabezado de la entrada</legend>

    <div class="campo<?= $claseError('proveedor') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="proveedor_id">Proveedor</label>
        <select class="control" id="proveedor_id" name="proveedor_id" required>
            <option value="">Selecciona un proveedor…</option>
            <?php foreach ($proveedores as $opcion) : ?>
                <option value="<?= (int) $opcion['id'] ?>"
                    <?= (int) $entrada->proveedor_id === (int) $opcion['id'] ? 'selected' : '' ?>>
                    <?= escaparValores($opcion['etiqueta']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($errorDeCampo('proveedor') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('proveedor')) ?></p>
        <?php endif; ?>
        <span class="campo__ayuda">
            ¿No está en la lista?
            <a href="/admin/proveedores/crear">Registra el proveedor primero</a>.
        </span>
    </div>

    <div class="campo<?= $claseError('fecha') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha de la entrega</label>
        <input class="control" type="date" id="fecha" name="fecha" required
               value="<?= escaparValores($entrada->fecha) ?>">
        <?php if ($errorDeCampo('fecha') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('fecha')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="numero_pedido">Número de pedido</label>
        <input class="control" type="text" id="numero_pedido" name="numero_pedido" maxlength="60"
               placeholder="OC-0001, remisión, guía…"
               value="<?= escaparValores($entrada->numero_pedido) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="categoria">Categoría</label>
        <!-- Catálogo compartido (tabla categorias_proveedor): el valor que
             viaja es el id entero, nunca el texto. "Sin categoría" sólo
             queda para lo no clasificado (id 0, válido siempre). -->
        <select class="control" id="categoria" name="categoria">
            <option value="0"<?= (int) $entrada->categoria === 0 ? ' selected' : '' ?>>
                <?= escaparValores(\App\CategoriasProveedor::SIN_CATEGORIA) ?>
            </option>
            <?php foreach (\App\CategoriasProveedor::mapa() as $idCat => $nombreCat) : ?>
                <option value="<?= (int) $idCat ?>"<?= (int) $entrada->categoria === $idCat ? ' selected' : '' ?>>
                    <?= escaparValores($nombreCat) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="monto">Monto</label>
        <input class="control" type="number" id="monto" name="monto" min="0" step="0.01"
               inputmode="decimal"
               placeholder="0.00"
               value="<?= $entrada->monto > 0 ? number_format((float) $entrada->monto, 2, '.', '') : '' ?>">
        <span class="campo__ayuda">
            Importe del pedido en pesos. Alimenta el "Balance general" del proveedor.
        </span>
    </div>
</fieldset>

<!-- Descripción, foto y notas -->
<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Descripción de lo recibido</legend>

    <div class="campo campo--completo<?= $claseError('describe') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="descripcion">Descripción</label>
        <textarea class="control" id="descripcion" name="descripcion" rows="4" required
                  placeholder="Materiales, cantidades, medidas, estado en que se recibió…"><?= escaparValores($entrada->descripcion) ?></textarea>
        <?php if ($errorDeCampo('describe') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('describe')) ?></p>
        <?php endif; ?>
    </div>

    <?php template('campoFoto', [
        'foto'     => $entrada->foto ?? '',
        'prefijo'  => 'entrada',
        'etiqueta' => 'Foto de entrega del proveedor',
        'ayuda'    => 'Fotografía del material recibido. JPG, PNG o WebP de hasta 6 MB.',
        'errores'  => $errores,
    ]); ?>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="notas">Notas adicionales</label>
        <textarea class="control" id="notas" name="notas" rows="3"
                  placeholder="Observaciones, faltantes, acuerdos de pago…"><?= escaparValores($entrada->notas) ?></textarea>
    </div>
</fieldset>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/proveedores/entradas">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar entrada</button>
</div>

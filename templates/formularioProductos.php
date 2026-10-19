<?php
/**
 * templates/formularioProductos.php — Formulario de alta y edición de productos.
 *
 * Se reutiliza desde views/admin/productos/crear.php y editar.php.
 *
 * El campo de imagen es EXCLUSIVAMENTE <input type="file">: las URLs de las
 * maquetas de diseño no se copian (OPENCODE.md). El servidor recorta a
 * cuadrado 1000×1000 y crea la miniatura 300×300.
 *
 * Variables esperadas:
 *   $producto     — instancia de App\Productos
 *   $errores      — array de mensajes de validación del servidor
 *   $empresas     — array de negocios para el <select>
 */

$producto = $producto ?? new \App\Productos();
$errores  = $errores ?? [];
$empresas = $empresas ?? [];

/** Devuelve el primer mensaje de error que menciona un campo concreto. */
$errorDeCampo = static function (string $clave) use ($errores): string {
    foreach ($errores as $error) {
        if (stripos($error, $clave) !== false) {
            return $error;
        }
    }
    return '';
};

$claseError = static function (string $clave) use ($errorDeCampo): string {
    return $errorDeCampo($clave) !== '' ? ' campo--con-error' : '';
};

$imagenActual = \App\Imagenes::url($producto->imagen ?: null);
?>

<?php if (!empty($errores)) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span>
            <?php foreach ($errores as $indice => $error) : ?>
                <?= $indice > 0 ? '<br>' : '' ?><?= escaparValores($error) ?>
            <?php endforeach; ?>
        </span>
    </div>
<?php endif; ?>

<div class="formulario__rejilla">
    <fieldset class="campo campo--completo<?= $claseError('negocio') ?>">
        <legend class="campo__etiqueta campo__etiqueta--obligatorio">Negocios en los que se vende</legend>
        <?php foreach ($empresas as $empresa) : ?>
            <label class="comprobacion" for="negocio_<?= (int) $empresa->id ?>">
                <input type="checkbox" id="negocio_<?= (int) $empresa->id ?>" name="negocios[]"
                       value="<?= (int) $empresa->id ?>"
                       <?= in_array((int) $empresa->id, array_map('intval', (array) $producto->negocios), true) ? 'checked' : '' ?>>
                <span><?= escaparValores($empresa->nombre) ?></span>
            </label>
        <?php endforeach; ?>
        <span class="campo__ayuda">Las existencias son una sola; asignar varios negocios no las duplica.</span>
        <?php if ($errorDeCampo('negocio') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('negocio')) ?></p>
        <?php endif; ?>
    </fieldset>

    <div class="campo">
        <label class="campo__etiqueta" for="sku">SKU / código</label>
        <input class="control" type="text" id="sku" name="sku" maxlength="40"
               placeholder="Ej. AC-100"
               value="<?= escaparValores($producto->sku) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="categoria">Categoría</label>
        <input class="control" type="text" id="categoria" name="categoria" maxlength="80"
               placeholder="Ej. Estantes, Canastillas, Accesorios"
               value="<?= escaparValores($producto->categoria) ?>">
    </div>

    <div class="campo campo--completo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre del producto</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="150"
               value="<?= escaparValores($producto->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="descripcion">Descripción</label>
        <textarea class="control" id="descripcion" name="descripcion" rows="4"
                  placeholder="Especificaciones, materiales, medidas…"><?= escaparValores($producto->descripcion) ?></textarea>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="unidad">Unidad de medida</label>
        <select class="control" id="unidad" name="unidad">
            <?php foreach (['pza', 'juego', 'caja', 'kg', 'm', 'm2', 'm3', 'litro', 'servicio'] as $unidad) : ?>
                <option value="<?= $unidad ?>"<?= $producto->unidad === $unidad ? ' selected' : '' ?>>
                    <?= $unidad ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo<?= $claseError('precio') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="precio">Precio de lista (sin IVA)</label>
        <input class="control control--moneda" type="number" id="precio" name="precio" required
               min="0" step="0.01" inputmode="decimal"
               value="<?= number_format((float) $producto->precio, 2, '.', '') ?>">
        <span class="campo__ayuda">Precio fijo: en el cotizador se muestra sólo lectura.</span>
        <?php if ($errorDeCampo('precio') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('precio')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo<?= $claseError('costo') ?>">
        <label class="campo__etiqueta" for="costo_actual">Costo actual de adquisición</label>
        <input class="control control--moneda" type="number" id="costo_actual" name="costo_actual"
               min="0" step="0.01" inputmode="decimal"
               value="<?= number_format((float) $producto->costo_actual, 2, '.', '') ?>">
        <span class="campo__ayuda">Editable; cambiarlo no altera los costos de compras ya registradas.</span>
        <?php if ($errorDeCampo('costo') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('costo')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo<?= $claseError('mínima') ?>">
        <label class="campo__etiqueta" for="stock_minimo">Cantidad mínima (alerta)</label>
        <input class="control" type="number" id="stock_minimo" name="stock_minimo" min="0" step="1"
               inputmode="numeric" value="<?= $producto->stock_minimo !== null ? (int) $producto->stock_minimo : '' ?>">
        <span class="campo__ayuda">Opcional. Avisa en Inventario cuando las existencias lleguen a este número.</span>
    </div>

    <!-- Imagen del producto: únicamente carga de archivo local -->
    <div class="campo campo--completo<?= $claseError('imagen') ?>">
        <span class="campo__etiqueta">Imagen del producto</span>
        <div class="carga-imagen">
            <div class="carga-imagen__zona">
                <?php if ($imagenActual !== '') : ?>
                    <img class="carga-imagen__preview"
                         src="<?= escaparValores(\App\Imagenes::urlMiniatura($producto->imagen)) ?>"
                         alt="<?= escaparValores($producto->nombre) ?>">
                <?php else : ?>
                    <span class="carga-imagen__placeholder material-symbols-outlined" aria-hidden="true">image</span>
                <?php endif; ?>

                <span class="campo__ayuda">
                    Se recorta en cuadrado de 1000×1000 y se genera una miniatura de 300×300.
                    JPG, PNG o WebP de hasta 6 MB.
                    <?php if ($imagenActual !== '') : ?>
                        <br>Deja el campo vacío para conservar la imagen actual.
                    <?php endif; ?>
                </span>
            </div>

            <label class="campo__etiqueta" for="imagen">Archivo de imagen</label>
            <input class="control carga-imagen__input" type="file" id="imagen" name="imagen"
                   accept="image/jpeg,image/png,image/webp" data-preview>
            <?php if ($errorDeCampo('imagen') !== '') : ?>
                <p class="campo__error"><?= escaparValores($errorDeCampo('imagen')) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="campo campo--completo">
        <span class="campo__etiqueta">Estado</span>
        <label class="comprobacion" for="activo">
            <input type="checkbox" id="activo" name="activo" value="1" <?= (int) $producto->activo === 1 ? 'checked' : '' ?>>
            <span>
              <strong>Producto activo</strong><br>
              <span class="texto-xs texto-secundario">Sólo los productos activos aparecen en el formulario de cotización.</span>
            </span>
        </label>
    </div>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/productos">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar producto</button>
</div>

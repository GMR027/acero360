<?php
/**
 * Formulario de alta/edición de un catálogo de texto. Variables: $c (config),
 * $registro (App\CatalogoTexto), $errores.
 */
?>
<?php if (!empty($errores)) : ?>
    <div class="nota nota--error mb-md" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?= implode('<br>', array_map('escaparValores', $errores)) ?></span>
    </div>
<?php endif; ?>

<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Datos de la <?= escaparValores($c['singular']) ?></legend>
    <div class="campo<?= !empty($errores) ? ' campo--con-error' : '' ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="<?= (int) $c['maximo'] ?>"
               placeholder="<?= escaparValores($c['ejemplo']) ?>" autofocus
               value="<?= escaparValores($registro->nombre) ?>">
    </div>
</fieldset>

<?php if (!empty($c['web'])) : ?>
<fieldset class="formulario__rejilla mt-lg">
    <legend class="texto-etiqueta mb-md">Sitio web de Soluciones en Acero</legend>
    <p class="texto-xs texto-secundario campo--completo">
        La categoría aparece en el catálogo público cuando tiene al menos un producto marcado
        «Publicar en el sitio web».
    </p>

    <div class="campo">
        <label class="campo__etiqueta" for="slug">Dirección web</label>
        <input class="control" type="text" id="slug" name="slug" maxlength="90" pattern="[a-z0-9-]*"
               placeholder="Se genera del nombre" value="<?= escaparValores($registro->slug) ?>">
        <span class="texto-xs texto-secundario">/productos/<strong><?= escaparValores($registro->slug ?: '…') ?></strong>
            · No cambia al renombrar; si la cambias, los enlaces anteriores dejan de funcionar.</span>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="orden">Orden en el sitio</label>
        <input class="control" type="number" id="orden" name="orden" min="0" max="9999" step="1"
               value="<?= (int) $registro->orden ?>">
        <span class="texto-xs texto-secundario">Menor número = aparece primero.</span>
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="descripcion">Descripción</label>
        <textarea class="control" id="descripcion" name="descripcion" rows="2" maxlength="255"
                  placeholder="Texto corto que se muestra en la tarjeta y en la página de la categoría"><?= escaparValores($registro->descripcion) ?></textarea>
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="imagen">Portada</label>
        <?php if ($registro->imagen !== '') : ?>
            <img src="<?= escaparValores(\App\Imagenes::url($registro->imagen)) ?>" alt="Portada actual"
                 width="120" height="120" style="object-fit:contain;background:#fff;border-radius:8px" class="mb-sm">
        <?php endif; ?>
        <input class="control" type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp">
        <span class="texto-xs texto-secundario">JPG, PNG o WebP; se recorta cuadrada. Sin portada, el sitio usa la imagen del primer producto.</span>
        <?php if ($registro->imagen !== '') : ?>
            <label class="comprobacion mt-sm" for="quitar_imagen">
                <input type="checkbox" id="quitar_imagen" name="quitar_imagen" value="1">
                <span>Quitar la portada</span>
            </label>
        <?php endif; ?>
    </div>
</fieldset>
<?php endif; ?>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="<?= $c['ruta'] ?>">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar</button>
</div>

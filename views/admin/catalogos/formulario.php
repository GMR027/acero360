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

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="<?= $c['ruta'] ?>">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar</button>
</div>

<?php
/**
 * Formulario de alta/edición de un distribuidor. Variables: $distribuidor
 * (App\Distribuidores), $errores, $empresas (Empresas::paraSelector()),
 * $municipios (para sugerir los que ya existen).
 */
?>
<?php if (!empty($errores)) : ?>
    <div class="nota nota--error mb-md" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?= implode('<br>', array_map('escaparValores', $errores)) ?></span>
    </div>
<?php endif; ?>

<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Datos del distribuidor</legend>

    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="120" autofocus
               placeholder="Ej. Ferretería el Clavo (Camino Real)"
               value="<?= escaparValores($distribuidor->nombre) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="sucursal">Sucursal</label>
        <input class="control" type="text" id="sucursal" name="sucursal" maxlength="120"
               placeholder="Ej. Camino Real" value="<?= escaparValores($distribuidor->sucursal) ?>">
        <span class="texto-xs texto-secundario">En el sitio se lee «Sucursal …».</span>
    </div>

    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="municipio">Municipio</label>
        <input class="control" type="text" id="municipio" name="municipio" required maxlength="80"
               list="municipios-distribuidor" placeholder="Ej. La Paz"
               value="<?= escaparValores($distribuidor->municipio) ?>">
        <datalist id="municipios-distribuidor">
            <?php foreach ($municipios as $opcion) : ?>
                <option value="<?= escaparValores($opcion) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <span class="texto-xs texto-secundario">Escríbelo igual que los demás para que queden en el mismo grupo y filtro.</span>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <input class="control" type="tel" id="telefono" name="telefono" maxlength="30"
               placeholder="Ej. 612 123 4567" value="<?= escaparValores($distribuidor->telefono) ?>">
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="direccion">Dirección</label>
        <input class="control" type="text" id="direccion" name="direccion" required maxlength="255"
               placeholder="Calle y número, colonia, código postal, ciudad"
               value="<?= escaparValores($distribuidor->direccion) ?>">
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="maps">Enlace de Google Maps</label>
        <input class="control" type="url" id="maps" name="maps" maxlength="255"
               placeholder="https://maps.app.goo.gl/…" value="<?= escaparValores($distribuidor->maps) ?>">
        <span class="texto-xs texto-secundario">En Google Maps: el lugar → «Compartir» → «Copiar vínculo». Es el botón «Cómo llegar» del sitio.</span>
    </div>
</fieldset>

<fieldset class="formulario__rejilla mt-lg">
    <legend class="texto-etiqueta mb-md">Sitio web</legend>

    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="empresa_id">Negocio</label>
        <select class="control" id="empresa_id" name="empresa_id" required>
            <?php foreach ($empresas as $empresa) : ?>
                <option value="<?= (int) $empresa['id'] ?>" <?= (int) $empresa['id'] === (int) $distribuidor->empresa_id ? 'selected' : '' ?>>
                    <?= escaparValores($empresa['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="texto-xs texto-secundario">El sitio de Soluciones en Acero sólo muestra los de su negocio.</span>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="orden">Orden</label>
        <input class="control" type="number" id="orden" name="orden" min="0" max="65535" step="1"
               placeholder="Al final" value="<?= (int) $distribuidor->orden > 0 ? (int) $distribuidor->orden : '' ?>">
        <span class="texto-xs texto-secundario">Menor número = aparece primero. Vacío: al final de la lista.</span>
    </div>

    <div class="campo campo--completo">
        <label class="comprobacion" for="publicado">
            <input type="hidden" name="publicado" value="0">
            <input type="checkbox" id="publicado" name="publicado" value="1" <?= (int) $distribuidor->publicado === 1 ? 'checked' : '' ?>>
            <span>Publicar en el sitio web</span>
        </label>
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="imagen">Foto de la fachada</label>
        <?php if ($distribuidor->imagen !== '') : ?>
            <img src="<?= escaparValores($distribuidor->urlImagen()) ?>" alt="Fachada actual"
                 width="240" height="156" style="object-fit:cover;border-radius:8px" class="mb-sm">
            <?php if (\App\Distribuidores::esHeredada($distribuidor->imagen)) : ?>
                <span class="texto-xs texto-secundario">Imagen heredada del sitio anterior. Puedes reemplazarla subiendo otra.</span>
            <?php endif; ?>
        <?php endif; ?>
        <input class="control" type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp">
        <span class="texto-xs texto-secundario">JPG, PNG o WebP (máximo 6 MB). Se recorta horizontal (800×520).</span>
        <?php if ($distribuidor->imagen !== '') : ?>
            <label class="comprobacion mt-sm" for="quitar_imagen">
                <input type="checkbox" id="quitar_imagen" name="quitar_imagen" value="1">
                <span>Quitar la foto</span>
            </label>
        <?php endif; ?>
    </div>
</fieldset>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/distribuidores">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar</button>
</div>

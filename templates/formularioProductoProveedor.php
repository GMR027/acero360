<?php
/**
 * templates/formularioProductoProveedor.php — alta y edición de un semiterminado de proveedor.
 *
 * Variables: $registro (array), $proveedores (Proveedores::paraSelector), $errores, $cancelar (URL), $editando (bool)
 */
$errores = $errores ?? [];
$activo = !array_key_exists('activo', $registro) || (int) $registro['activo'] === 1;
?>
<?php if ($errores) : ?>
    <div class="nota nota--error mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span><?php foreach ($errores as $i => $e) : ?><?= $i ? '<br>' : '' ?><?= escaparValores($e) ?><?php endforeach; ?></span>
    </div>
<?php endif; ?>

<div class="formulario__rejilla">
    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="proveedor_id">Proveedor</label>
        <select class="control" id="proveedor_id" name="proveedor_id" required>
            <option value="">— Elige un proveedor —</option>
            <?php foreach ($proveedores as $p) : ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) ($registro['proveedor_id'] ?? 0) === $p['id'] ? 'selected' : '' ?>><?= escaparValores($p['etiqueta']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre del producto</label>
        <input class="control" type="text" id="nombre" name="nombre" maxlength="150" required autocomplete="off"
               value="<?= escaparValores($registro['nombre'] ?? '') ?>" placeholder="Ej. Armazón de canastilla sin pintar">
    </div>
    <div class="campo">
        <label class="campo__etiqueta" data-ayuda="El código con el que el proveedor identifica este artículo, o uno interno. No se repite dentro del mismo proveedor." for="codigo">Código del producto</label>
        <input class="control" type="text" id="codigo" name="codigo" maxlength="40" autocomplete="off"
               value="<?= escaparValores($registro['codigo'] ?? '') ?>" placeholder="Opcional">
    </div>
    <div class="campo">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="costo_unitario">Costo unitario de compra</label>
        <input class="control control--moneda" type="number" id="costo_unitario" name="costo_unitario" min="0" step="0.01" inputmode="decimal" required
               value="<?= escaparValores($registro['costo_unitario'] ?? '') ?>" placeholder="0.00">
        <span class="campo__ayuda">Lo que cobra el proveedor por pieza. Cambiarlo no altera compras anteriores: cada compra guarda su costo.</span>
    </div>
    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="descripcion">Descripción</label>
        <input class="control" type="text" id="descripcion" name="descripcion" maxlength="255"
               value="<?= escaparValores($registro['descripcion'] ?? '') ?>" placeholder="Características que lo identifican (medida, calibre, acabado…)">
    </div>
    <div class="campo campo--completo">
        <span class="campo__etiqueta">Imagen del artículo</span>
        <div class="carga-imagen">
            <div class="carga-imagen__zona">
                <?php if (!empty($registro['imagen'])) : ?>
                    <img class="carga-imagen__preview" src="<?= escaparValores(\App\Imagenes::urlMiniatura($registro['imagen'])) ?>"
                         alt="<?= escaparValores($registro['nombre'] ?? '') ?>">
                <?php else : ?>
                    <span class="carga-imagen__placeholder material-symbols-outlined" aria-hidden="true">image</span>
                <?php endif; ?>
                <span class="campo__ayuda">
                    Se muestra al recibir la compra para identificar el artículo. JPG, PNG o WebP de hasta 6 MB.
                    <?php if (!empty($registro['imagen'])) : ?><br>Deja el campo vacío para conservar la imagen actual.<?php endif; ?>
                </span>
            </div>
            <label class="campo__etiqueta" for="imagen">Archivo de imagen</label>
            <input class="control carga-imagen__input" type="file" id="imagen" name="imagen"
                   accept="image/jpeg,image/png,image/webp" data-preview>
            <?php if (!empty($registro['imagen'])) : ?>
                <label class="comprobacion" for="quitar_imagen">
                    <input type="checkbox" id="quitar_imagen" name="quitar_imagen" value="1">
                    <span class="texto-sm">Quitar la imagen actual</span>
                </label>
            <?php endif; ?>
        </div>
    </div>
</div>

<label class="comprobacion" for="activo">
    <input type="checkbox" id="activo" name="activo" value="1" <?= $activo ? 'checked' : '' ?>>
    <span><strong>Activo</strong><br>
        <span class="texto-xs texto-secundario">Los productos inactivos no se ofrecen en compras nuevas, pero se conservan en el historial.</span></span>
</label>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="<?= escaparValores($cancelar) ?>">Cancelar</a>
    <button class="btn btn--primario" type="submit"><?= !empty($editando) ? 'Guardar cambios' : 'Guardar producto' ?></button>
</div>

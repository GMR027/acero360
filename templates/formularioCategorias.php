<?php
/**
 * templates/formularioCategorias.php — Alta y edición de categorías de compra.
 *
 * Formulario del CRUD de /admin/proveedores/categorias.
 *
 * El catálogo es ÚNICO para todos los proveedores: lo que se clasifica es el
 * tipo de compra (Acero, Empaque, Servicio…), no el proveedor que la trajo.
 * Por eso el nombre debe ser corto e inequívoco: "acero" y "Acero" no pueden
 * convivir, porque terminarían en dos filtros distintos del mismo significado.
 *
 * Variables esperadas:
 *   $categoria — instancia de App\CategoriasProveedor (nueva o existente)
 *   $errores   — array de mensajes de validación del servidor
 */

$categoria = $categoria ?? new \App\CategoriasProveedor();
$errores   = $errores ?? [];

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

<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Datos de la categoría</legend>

    <div class="campo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="80"
               placeholder="Ej. Acero, Empaque, Servicio…"
               value="<?= escaparValores($categoria->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
        <span class="texto-xs texto-secundario">
            Se ofrece tal cual en el campo «Categoría» de las entradas de TODOS los proveedores.
        </span>
    </div>
</fieldset>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/proveedores/categorias">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar categoría</button>
</div>

<?php
/**
 * templates/formularioProveedores.php — Alta y edición de proveedores.
 *
 * Sólo recoge los datos del PROVEEDOR (identidad y contacto). Todo lo que
 * cambia en cada entrega —número de pedido, categoría, descripción, foto y
 * notas— se registra como una entrada desde views/admin/proveedores/entradas.
 *
 * Variables esperadas:
 *   $proveedor — instancia de App\Proveedores (nueva o existente)
 *   $errores   — array de mensajes de validación del servidor
 */

$proveedor = $proveedor ?? new \App\Proveedores();
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

<!-- Identificación -->
<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Identificación del proveedor</legend>

    <div class="campo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="150"
               placeholder="Razón social o nombre del proveedor"
               value="<?= escaparValores($proveedor->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo<?= $claseError('tipo de proveedor') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="tipo_proveedor">Tipo de proveedor</label>
        <select class="control" id="tipo_proveedor" name="tipo_proveedor" required>
            <?php foreach (\App\Proveedores::TIPOS as $tipo) : ?>
                <option value="<?= escaparValores($tipo) ?>"
                    <?= $proveedor->tipo_proveedor === $tipo ? 'selected' : '' ?>>
                    <?= escaparValores($tipo) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($errorDeCampo('tipo de proveedor') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('tipo de proveedor')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="rfc">RFC</label>
        <input class="control" type="text" id="rfc" name="rfc" maxlength="20"
               style="text-transform:uppercase" placeholder="XAXX010101000"
               value="<?= escaparValores($proveedor->rfc) ?>">
    </div>
</fieldset>

<!-- Contacto -->
<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Contacto y ubicación</legend>

    <div class="campo">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <input class="control" type="tel" id="telefono" name="telefono" maxlength="30"
               value="<?= escaparValores($proveedor->telefono) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="ubicacion">Ubicación</label>
        <input class="control" type="text" id="ubicacion" name="ubicacion" maxlength="120"
               placeholder="Ciudad, estado o colonia"
               value="<?= escaparValores($proveedor->ubicacion) ?>">
    </div>
</fieldset>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/proveedores">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar proveedor</button>
</div>

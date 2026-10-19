<?php
/**
 * templates/formularioEmpresas.php — Formulario de alta y edición de negocios.
 *
 * Se reutiliza desde views/admin/empresas/crear.php y editar.php.
 * Recoge los datos fiscales que OPENCODE.md pide completar desde el CRUD:
 * RFC, régimen, domicilio, código postal y logo.
 *
 * Variables esperadas:
 *   $empresa — instancia de App\Empresas
 *   $errores — array de mensajes de validación del servidor
 *   $accion  — acción del <form> (se imprime en el action)
 */

$empresa = $empresa ?? new \App\Empresas();
$errores = $errores ?? [];
$accion  = $accion ?? '/admin/empresas/crear';

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

$logoActual = \App\Imagenes::url($empresa->logo ?: null);
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

<!-- Identificación del negocio -->
<div class="formulario__rejilla">
    <div class="campo campo--completo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre del negocio</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="150"
               placeholder="Ej. Soluciones en Acero"
               value="<?= escaparValores($empresa->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="rfc">RFC</label>
        <input class="control" type="text" id="rfc" name="rfc" maxlength="20"
               style="text-transform:uppercase" placeholder="XAXX010101000"
               value="<?= escaparValores($empresa->rfc) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="regimen">Régimen fiscal (SAT)</label>
        <input class="control" type="text" id="regimen" name="regimen" maxlength="60"
               placeholder="Ej. 601 - General de Ley"
               value="<?= escaparValores($empresa->regimen) ?>">
    </div>

    <!-- Logo del negocio -->
    <div class="campo campo--completo">
        <span class="campo__etiqueta">Logo</span>
        <div class="carga-imagen">
            <div class="carga-imagen__zona">
                <?php if ($logoActual !== '') : ?>
                    <img class="carga-imagen__preview carga-imagen__preview--amplia"
                         src="<?= escaparValores($logoActual) ?>"
                         alt="Logo de <?= escaparValores($empresa->nombre) ?>">
                <?php else : ?>
                    <span class="carga-imagen__placeholder material-symbols-outlined" aria-hidden="true">image</span>
                <?php endif; ?>

                <span class="campo__ayuda">
                    Se guarda como un cuadro de 300×300 px sin deformarse. JPG, PNG o WebP de hasta 6 MB.
                    <?php if ($logoActual !== '') : ?>
                        <br>Deja el campo vacío para conservar el logo actual.
                    <?php endif; ?>
                </span>
            </div>

            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="logo">Archivo de imagen</label>
            <input class="control carga-imagen__input" type="file" id="logo" name="logo"
                   accept="image/jpeg,image/png,image/webp" data-preview>
            <?php if ($errorDeCampo('imagen') !== '') : ?>
                <p class="campo__error"><?= escaparValores($errorDeCampo('imagen')) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Domicilio fiscal -->
<div class="formulario__rejilla">
    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="direccion">Domicilio</label>
        <input class="control" type="text" id="direccion" name="direccion" maxlength="255"
               placeholder="Calle, número, colonia"
               value="<?= escaparValores($empresa->direccion) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="ciudad">Ciudad</label>
        <input class="control" type="text" id="ciudad" name="ciudad" maxlength="100"
               value="<?= escaparValores($empresa->ciudad) ?>">
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="estado">Estado</label>
        <input class="control" type="text" id="estado" name="estado" maxlength="100"
               value="<?= escaparValores($empresa->estado) ?>">
    </div>

    <div class="campo<?= $claseError('código postal') ?>">
        <label class="campo__etiqueta" for="cp">Código postal</label>
        <input class="control control--moneda" type="text" id="cp" name="cp" maxlength="5"
               value="<?= escaparValores($empresa->cp) ?>">
        <?php if ($errorDeCampo('código postal') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('código postal')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <input class="control" type="tel" id="telefono" name="telefono" maxlength="20"
               value="<?= escaparValores($empresa->telefono) ?>">
    </div>

    <div class="campo<?= $claseError('correo') ?>">
        <label class="campo__etiqueta" for="email">Correo electrónico</label>
        <input class="control" type="email" id="email" name="email" maxlength="120"
               value="<?= escaparValores($empresa->email) ?>">
        <?php if ($errorDeCampo('correo') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('correo')) ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Condiciones por defecto de las cotizaciones -->
<div class="formulario__rejilla">
    <div class="campo">
        <label class="campo__etiqueta" for="condiciones_envio">Condiciones de envío</label>
        <textarea class="control" id="condiciones_envio" name="condiciones_envio" rows="4"
                  placeholder="Texto que se usará por defecto en las cotizaciones"><?= escaparValores($empresa->condiciones_envio) ?></textarea>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="condiciones_compra">Condiciones de compra</label>
        <textarea class="control" id="condiciones_compra" name="condiciones_compra" rows="4"
                  placeholder="Texto que se usará por defecto en las cotizaciones"><?= escaparValores($empresa->condiciones_compra) ?></textarea>
    </div>

    <div class="campo campo--completo">
        <span class="campo__etiqueta">Estado</span>
        <label class="comprobacion" for="activa">
            <input type="checkbox" id="activa" name="activa" value="1" <?= (int) $empresa->activa === 1 ? 'checked' : '' ?>>
            <span>
              <strong>Negocio activo</strong><br>
              <span class="texto-xs texto-secundario">Sólo los negocios activos se ofrecen en el formulario de cotización.</span>
            </span>
        </label>
    </div>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/empresas">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar negocio</button>
</div>

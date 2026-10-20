<?php
/**
 * templates/formularioClientes.php — Formulario de alta y edición de clientes.
 *
 * Se reutiliza desde views/admin/clientes/crear.php y editar.php para que
 * ambos escenarios tengan exactamente los mismos campos y el mismo diseño.
 *
 * Variables esperadas:
 *   $cliente — instancia de App\Clientes (nueva o existente)
 *   $errores — array de mensajes de validación del servidor
 */

$cliente = $cliente ?? new \App\Clientes();
$errores = $errores ?? [];

/** Devuelve el primer mensaje de error que menciona un campo concreto. */
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
    <legend class="texto-etiqueta mb-md">Identificación</legend>

    <div class="campo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre del contacto</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="120"
               value="<?= escaparValores($cliente->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo<?= $claseError('nombre de la empresa') ?>">
        <label class="campo__etiqueta" for="nombre_empresa">Nombre de la empresa</label>
        <input class="control" type="text" id="nombre_empresa" name="nombre_empresa" maxlength="150"
               placeholder="Razón social o negocio"
               value="<?= escaparValores($cliente->nombre_empresa) ?>">
        <span class="campo__ayuda">Aparece en la cotización como "Cliente destino". Llena este campo o el alias: al menos uno es obligatorio.</span>
    </div>

    <div class="campo<?= $claseError('alias') ?>">
        <label class="campo__etiqueta" for="alias">Alias</label>
        <input class="control" type="text" id="alias" name="alias" maxlength="80"
               placeholder="Nombre corto para buscar rápido"
               value="<?= escaparValores($cliente->alias) ?>">
        <span class="campo__ayuda">Se usa para localizar al cliente en el buscador. Obligatorio si no escribes el nombre de la empresa.</span>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="rfc">RFC</label>
        <input class="control" type="text" id="rfc" name="rfc" maxlength="20"
               style="text-transform:uppercase" placeholder="XAXX010101000"
               value="<?= escaparValores($cliente->rfc) ?>">
    </div>

    <!-- Foto de perfil redonda (cuadro 500×500 px). -->
    <?php template('campoRetrato', [
        'foto'     => $cliente->foto ?? '',
        'prefijo'  => 'cliente',
        'etiqueta' => 'Foto de perfil',
        'errores'  => $errores,
    ]); ?>
</fieldset>

<!-- Contacto -->
<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Contacto y ubicación</legend>

    <div class="campo">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <input class="control" type="tel" id="telefono" name="telefono" maxlength="20"
               value="<?= escaparValores($cliente->telefono) ?>">
    </div>

    <div class="campo<?= $claseError('correo') ?>">
        <label class="campo__etiqueta" for="email">Correo electrónico</label>
        <input class="control" type="email" id="email" name="email" maxlength="120"
               value="<?= escaparValores($cliente->email) ?>">
        <?php if ($errorDeCampo('correo') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('correo')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo formulario__rejilla--completo">
        <label class="campo__etiqueta" for="ubicacion">Ubicación</label>
        <input class="control" type="text" id="ubicacion" name="ubicacion" maxlength="255"
               placeholder="Calle, colonia, ciudad, estado"
               value="<?= escaparValores($cliente->ubicacion) ?>">
    </div>
</fieldset>

<!-- Condición comercial -->
<fieldset class="formulario__rejilla">
    <legend class="texto-etiqueta mb-md">Condición comercial</legend>

    <div class="campo<?= $claseError('descuento') ?>">
        <label class="campo__etiqueta" for="descuento_pct">Descuento global (%)</label>
        <input class="control control--moneda" type="number" id="descuento_pct" name="descuento_pct"
               min="0" max="100" step="0.5" value="<?= escaparValores($cliente->descuento_pct) ?>">
        <span class="campo__ayuda">Se aplica automáticamente a sus cotizaciones.</span>
        <?php if ($errorDeCampo('descuento') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('descuento')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <span class="campo__etiqueta">Condición</span>
        <label class="comprobacion" for="vip">
            <input type="checkbox" id="vip" name="vip" value="1" <?= (int) $cliente->vip === 1 ? 'checked' : '' ?>>
            <span>
              <strong>Cliente VIP</strong><br>
              <span class="texto-xs texto-secundario">Marca a los clientes más frecuentes para identificarlos de un vistazo.</span>
            </span>
        </label>
    </div>

    <div class="campo formulario__rejilla--completo">
        <label class="campo__etiqueta" for="notas">Notas</label>
        <textarea class="control" id="notas" name="notas" rows="3"
                  placeholder="Condiciones especiales, persona de contacto, horarios…"><?= escaparValores($cliente->notas) ?></textarea>
    </div>
</fieldset>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/clientes">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar cliente</button>
</div>

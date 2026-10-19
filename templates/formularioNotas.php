<?php
/**
 * templates/formularioNotas.php — Formulario de alta y edición de notas.
 *
 * Se reutiliza desde views/admin/notas/crear.php y editar.php. Los campos
 * son los del modal "Nueva Nota Personal" de design/notas_personales:
 * título, contenido, categoría, fecha de recordatorio, referencia y fijado.
 *
 * Variables esperadas:
 *   $nota    — instancia de App\Notas (nueva o existente)
 *   $errores — array de mensajes de validación del servidor
 */

$nota    = $nota ?? new \App\Notas();
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

$categorias = \App\Notas::categorias();
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

<div class="formulario__rejilla">
    <div class="campo campo--completo<?= $claseError('título') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="titulo">Título de la nota</label>
        <input class="control" type="text" id="titulo" name="titulo" required maxlength="200"
               placeholder="Ej. Revisión precio Servidor R750"
               value="<?= escaparValores($nota->titulo) ?>">
        <?php if ($errorDeCampo('título') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('título')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo campo--completo<?= $claseError('contenido') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="cuerpo">Contenido</label>
        <textarea class="control" id="cuerpo" name="cuerpo" rows="5" required
                  placeholder="Escribe aquí el recordatorio, la revisión o el cambio…"><?= escaparValores($nota->cuerpo) ?></textarea>
        <?php if ($errorDeCampo('contenido') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('contenido')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo<?= $claseError('categoría') ?>">
        <label class="campo__etiqueta" for="categoria">Categoría / Etiqueta</label>
        <select class="control" id="categoria" name="categoria">
            <?php foreach ($categorias as $valor => $etiqueta) : ?>
                <option value="<?= $valor ?>"<?= $nota->categoria === $valor ? ' selected' : '' ?>>
                    <?= $etiqueta ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($errorDeCampo('categoría') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('categoría')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="fecha_recordatorio">Fecha y recordatorio (opcional)</label>
        <input class="control" type="text" id="fecha_recordatorio" name="fecha_recordatorio" maxlength="80"
               placeholder="Ej. Mañana 9:00 AM, Próx. Viernes…"
               value="<?= escaparValores($nota->fecha_recordatorio) ?>">
        <span class="campo__ayuda">Etiqueta de vencimiento que se muestra en la tarjeta.</span>
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="referencia">Referencia o cliente asociado (opcional)</label>
        <input class="control" type="text" id="referencia" name="referencia" maxlength="255"
               placeholder="Ej. Cliente: Grupo Industrial del Norte"
               value="<?= escaparValores($nota->referencia) ?>">
        <span class="campo__ayuda">Separa etiqueta y valor con dos puntos para resaltar la etiqueta.</span>
    </div>

    <div class="campo campo--completo">
        <span class="campo__etiqueta">Prioridad</span>
        <label class="comprobacion" for="fijada">
            <input type="checkbox" id="fijada" name="fijada" value="1" <?= (int) $nota->fijada === 1 ? 'checked' : '' ?>>
            <span>
              <strong>Fijar en la parte superior</strong><br>
              <span class="texto-xs texto-secundario">Mantener esta nota visible prioritariamente en el tablero.</span>
            </span>
        </label>
    </div>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/notas">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar nota</button>
</div>

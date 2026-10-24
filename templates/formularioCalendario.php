<?php
/**
 * templates/formularioCalendario.php — Formulario de alta y edición de un
 * recordatorio o evento del Calendario.
 *
 * Se reutiliza desde views/admin/calendario/index.php (alta del día elegido)
 * y views/admin/calendario/editar.php. Los campos son los pedidos en el módulo:
 * fecha, nombre del evento, descripción opcional, negocio opcional, nivel de
 * importancia y hora.
 *
 * Variables esperadas:
 *   $evento       — instancia de App\EventosCalendario (nueva o existente)
 *   $errores      — array de mensajes de validación del servidor
 *   $accion       — URL a la que envía el formulario (POST)
 *   $rutaCancelar — URL del botón «Cancelar»
 *   $idMensaje    — id del contenedor de errores (para aria-describedby)
 */

$evento       = $evento ?? new \App\EventosCalendario();
$errores      = $errores ?? [];
$accion       = $accion ?? '/admin/calendario/crear';
$rutaCancelar = $rutaCancelar ?? '/admin/calendario';
$idMensaje    = $idMensaje ?? 'errores-calendario';

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

$importancias = \App\EventosCalendario::importancias();
$negocios     = \App\Empresas::paraSelector();

/** El input de hora admite «HH:MM»: se recorta un «HH:MM:SS» guardado. */
$horaCampo = trim((string) $evento->hora);
if (strlen($horaCampo) === 8) {
    $horaCampo = substr($horaCampo, 0, 5);
}
?>

<!-- Errores generales del formulario -->
<?php if (!empty($errores)) : ?>
    <div class="nota nota--error mb-md" role="alert" id="<?= escaparValores($idMensaje) ?>">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">error</span>
        <span>
            <?php foreach ($errores as $indice => $error) : ?>
                <?= $indice > 0 ? '<br>' : '' ?><?= escaparValores($error) ?>
            <?php endforeach; ?>
        </span>
    </div>
<?php endif; ?>

<div class="formulario__rejilla">
    <div class="campo<?= $claseError('fecha') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="fecha">Fecha</label>
        <input class="control" type="date" id="fecha" name="fecha" required
               value="<?= escaparValores($evento->fecha) ?>">
        <?php if ($errorDeCampo('fecha') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('fecha')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="hora">Hora del evento (opcional)</label>
        <input class="control" type="time" id="hora" name="hora"
               value="<?= escaparValores($horaCampo) ?>">
        <span class="campo__ayuda">Déjala vacía si ocurre todo el día.</span>
    </div>

    <div class="campo campo--completo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre del evento</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="200"
               placeholder="Ej. Visita a Grupo Industrial del Norte"
               value="<?= escaparValores($evento->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo campo--completo">
        <label class="campo__etiqueta" for="descripcion">Descripción (opcional)</label>
        <textarea class="control" id="descripcion" name="descripcion" rows="3"
                  placeholder="Detalles, qué llevar, a quién llamar…"><?= escaparValores($evento->descripcion) ?></textarea>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="empresa_id">Negocio (opcional)</label>
        <select class="control" id="empresa_id" name="empresa_id">
            <option value="">Sin negocio</option>
            <?php foreach ($negocios as $negocio) : ?>
                <option value="<?= (int) $negocio['id'] ?>"<?= (int) $evento->empresa_id === (int) $negocio['id'] ? ' selected' : '' ?>>
                    <?= escaparValores($negocio['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo<?= $claseError('importancia') ?>">
        <label class="campo__etiqueta" for="importancia">Nivel de importancia</label>
        <select class="control" id="importancia" name="importancia">
            <?php foreach ($importancias as $valor => $etiqueta) : ?>
                <option value="<?= $valor ?>"<?= $evento->importancia === $valor ? ' selected' : '' ?>>
                    <?= $etiqueta ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($errorDeCampo('importancia') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('importancia')) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="<?= escaparValores($rutaCancelar) ?>">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar</button>
</div>

<?php
/**
 * templates/formularioUsuarios.php — Formulario de alta y edición de usuarios.
 *
 * Se reutiliza desde views/admin/usuarios/crear.php y editar.php.
 * Todos los usuarios registrados son administradores (OPENCODE.md), por lo
 * que el rol se muestra como dato de sólo lectura.
 *
 * Variables esperadas:
 *   $usuario — instancia de App\Usuarios
 *   $errores — array de mensajes de validación del servidor
 *   $esAlta  — true en el alta (la contraseña es obligatoria)
 */

$usuario = $usuario ?? new \App\Usuarios();
$errores = $errores ?? [];
$esAlta  = $esAlta ?? false;

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

<div class="formulario__rejilla">
    <div class="campo campo--completo<?= $claseError('nombre') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre completo</label>
        <input class="control" type="text" id="nombre" name="nombre" required maxlength="100"
               autocomplete="name"
               value="<?= escaparValores($usuario->nombre) ?>">
        <?php if ($errorDeCampo('nombre') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('nombre')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo<?= $claseError('correo') ?>">
        <label class="campo__etiqueta campo__etiqueta--obligatorio" for="email">Correo electrónico</label>
        <input class="control" type="email" id="email" name="email" required maxlength="120"
               autocomplete="email" placeholder="usuario@empresa.com"
               value="<?= escaparValores($usuario->email) ?>">
        <span class="campo__ayuda">Es el usuario con el que se inicia sesión.</span>
        <?php if ($errorDeCampo('correo') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('correo')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <input class="control" type="tel" id="telefono" name="telefono" maxlength="20"
               autocomplete="tel"
               value="<?= escaparValores($usuario->telefono) ?>">
    </div>

    <!-- Foto de perfil redonda (cuadro 500×500 px). -->
    <?php template('campoRetrato', [
        'foto'     => $usuario->foto ?? '',
        'prefijo'  => 'usuario',
        'etiqueta' => 'Foto de perfil',
        'errores'  => $errores,
    ]); ?>

    <div class="campo<?= $claseError('contraseña') ?>">
        <label class="campo__etiqueta <?= $esAlta ? 'campo__etiqueta--obligatorio' : '' ?>" for="contrasena">Contraseña</label>
        <input class="control" type="password" id="contrasena" name="contrasena" minlength="8"
               autocomplete="<?= $esAlta ? 'new-password' : 'new-password' ?>"
               placeholder="<?= $esAlta ? 'Mínimo 8 caracteres' : 'Déjala vacía para no cambiarla' ?>"
               value="">
        <span class="campo__ayuda">
            <?= $esAlta
                ? 'Se guarda cifrada con bcrypt; nunca se muestra en texto plano.'
                : 'Escribe una nueva sólo si deseas cambiarla.' ?>
        </span>
        <?php if ($errorDeCampo('contraseña') !== '') : ?>
            <p class="campo__error"><?= escaparValores($errorDeCampo('contraseña')) ?></p>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="rol-fijo">Rol</label>
        <input class="control" type="text" id="rol-fijo" value="Administrador" readonly>
        <span class="campo__ayuda">Todos los usuarios registrados son administradores.</span>
    </div>

    <div class="campo">
        <label class="campo__etiqueta" for="alta-fija">Alta</label>
        <input class="control" type="text" id="alta-fija" readonly
               value="<?= $usuario->creado_en ? escaparValores(date('d/m/Y', strtotime($usuario->creado_en))) : 'Se registrará hoy' ?>">
    </div>
</div>

<div class="formulario__acciones">
    <a class="btn btn--secundario" href="/admin/usuarios">Cancelar</a>
    <button class="btn btn--primario" type="submit">Guardar usuario</button>
</div>

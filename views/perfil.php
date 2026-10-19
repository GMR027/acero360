<?php
/**
 * views/perfil.php — Edición de los datos del usuario en sesión.
 * Requisito de OPENCODE.md: "los usuarios deberán poder editar sus datos".
 */

soloAdmin();

$errores = [];
$usuario = null;

/* --------------------------------------------------------------------------
 * Carga del registro actual
 * ------------------------------------------------------------------------- */
$consulta = $db->prepare('SELECT id, nombre, email, telefono, foto FROM usuarios WHERE id = ? LIMIT 1');
$idSesion = (int) ($_SESSION['id'] ?? 0);
$consulta->bind_param('i', $idSesion);
$consulta->execute();
$usuario = $consulta->get_result()->fetch_assoc();
$consulta->close();

if (!$usuario) {
    redirigir('/login', 'error', 'No fue posible cargar tu usuario.');
}

// Foto que tiene guardada ahora: se usa para restituirla si falla el envío
// y para borrar el archivo viejo sólo cuando lo nuevo ya quedó grabado.
$fotoPrevia = $usuario['foto'] ?? '';

/* --------------------------------------------------------------------------
 * Guardado
 * ------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nombre     = trim($_POST['nombre'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $telefono   = trim($_POST['telefono'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';
    $repetir    = $_POST['repetir'] ?? '';

    if ($nombre === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Introduce un correo electrónico válido.';
    }
    if ($telefono === '') {
        $errores[] = 'El teléfono es obligatorio.';
    }
    if ($contrasena !== '' && strlen($contrasena) < 8) {
        $errores[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
    }
    if ($contrasena !== $repetir) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    // El correo no puede pertenecer a otra cuenta.
    if (empty($errores)) {
        $consulta = $db->prepare('SELECT COUNT(*) AS total FROM usuarios WHERE email = ? AND id <> ?');
        $consulta->bind_param('si', $email, $idSesion);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        if ($total > 0) {
            $errores[] = 'Ya existe otra cuenta con ese correo electrónico.';
        }
    }

    // Foto de perfil: archivo nuevo, casilla de quitar, o nada que tocar.
    // Sólo se procesa si los demás datos ya son válidos, para no dejar un
    // archivo sin referencia cuando el envío se rechaza.
    $foto = null;

    if (empty($errores)) {
        $foto = \App\Imagenes::subirRetrato(
            $_FILES['foto'] ?? null,
            !empty($_POST['quitar_foto']),
            $errores
        );

        if ($foto !== null) {
            $usuario['foto'] = $foto;
        }
    }

    if (empty($errores)) {
        $fotoGuardada = $usuario['foto'] ?? '';

        if ($contrasena !== '') {
            $hash  = password_hash($contrasena, PASSWORD_BCRYPT);
            $consulta = $db->prepare('UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, foto = ?, contrasena = ? WHERE id = ?');
            $consulta->bind_param('sssssi', $nombre, $email, $telefono, $fotoGuardada, $hash, $idSesion);
        } else {
            $consulta = $db->prepare('UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, foto = ? WHERE id = ?');
            $consulta->bind_param('ssssi', $nombre, $email, $telefono, $fotoGuardada, $idSesion);
        }

        if ($consulta->execute()) {
            // La foto anterior sólo se borra cuando ya quedó sustituida.
            if ($fotoGuardada !== $fotoPrevia) {
                \App\Imagenes::eliminar($fotoPrevia ?: null);
            }

            // Se refleja el nuevo nombre en la sesión para el resto de la navegación.
            $_SESSION['nombre'] = $nombre;
            $_SESSION['email']  = $email;
            $consulta->close();
            redirigir('/admin/perfil', 'exito', 'Tus datos se actualizaron correctamente.');
        }

        $consulta->close();
        $errores[] = 'No fue posible guardar los cambios.';
    }

    // Si hubo errores se muestran los valores que el usuario ya había escrito.
    $usuario = [
        'id'       => $idSesion,
        'nombre'   => $nombre,
        'email'    => $email,
        'telefono' => $telefono,
        'foto'     => $foto ?? $fotoPrevia,
    ];
}

template('header', [
    'titulo'     => 'Mi perfil',
    'subtitulo'  => 'Cuenta de administrador',
    'rutaActiva' => '/admin/perfil',
]);
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

<div class="rejilla-2">
    <section class="tarjeta">
        <div class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">Datos de la cuenta</h2>
        </div>

        <form class="formulario" action="/admin/perfil" method="post" data-validar
              enctype="multipart/form-data">
            <!-- Foto de perfil: cuadro redondo de 80px con carga opcional. -->
            <?php template('campoRetrato', [
                'foto'     => $usuario['foto'] ?? '',
                'prefijo'  => 'perfil',
                'errores'  => $errores,
            ]); ?>

            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre</label>
                    <input class="control" type="text" id="nombre" name="nombre" required
                           value="<?= escaparValores($usuario['nombre']) ?>">
                </div>

                <div class="campo">
                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="telefono">Teléfono</label>
                    <input class="control" type="tel" id="telefono" name="telefono" required
                           value="<?= escaparValores($usuario['telefono'] ?? '') ?>">
                </div>

                <div class="campo formulario__rejilla--completo">
                    <label class="campo__etiqueta campo__etiqueta--obligatorio" for="email">Correo electrónico</label>
                    <input class="control" type="email" id="email" name="email" required
                           value="<?= escaparValores($usuario['email']) ?>">
                </div>
            </div>

            <div class="formulario__acciones">
                <a class="btn btn--secundario" href="/admin">Cancelar</a>
                <button class="btn btn--primario" type="submit">Guardar cambios</button>
            </div>
        </form>
    </section>

    <section class="tarjeta">
        <div class="tarjeta__cabecera">
            <h2 class="tarjeta__titulo">Cambiar contraseña</h2>
        </div>

        <p class="texto-sm texto-secundario mb-md">
            Deja ambos campos en blanco si no deseas modificar tu contraseña actual.
        </p>

        <form class="formulario" action="/admin/perfil" method="post" data-validar>
            <!-- Se reenvían los datos básicos para no perderlos al cambiar la contraseña. -->
            <input type="hidden" name="nombre" value="<?= escaparValores($usuario['nombre']) ?>">
            <input type="hidden" name="email" value="<?= escaparValores($usuario['email']) ?>">
            <input type="hidden" name="telefono" value="<?= escaparValores($usuario['telefono'] ?? '') ?>">

            <div class="formulario__rejilla">
                <div class="campo">
                    <label class="campo__etiqueta" for="contrasena">Nueva contraseña</label>
                    <input class="control" type="password" id="contrasena" name="contrasena"
                           minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres">
                    <span class="campo__ayuda">Opcional</span>
                </div>

                <div class="campo">
                    <label class="campo__etiqueta" for="repetir">Repetir contraseña</label>
                    <input class="control" type="password" id="repetir" name="repetir"
                           minlength="8" autocomplete="new-password" data-comparar="contrasena">
                </div>
            </div>

            <div class="formulario__acciones">
                <button class="btn btn--primario" type="submit">Actualizar contraseña</button>
            </div>
        </form>
    </section>
</div>

<?php template('footer'); ?>

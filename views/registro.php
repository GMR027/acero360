<?php
/**
 * views/registro.php — Alta de nuevos usuarios.
 *
 * SEGURIDAD: esta ruta lleva el middleware "soloRegistroAbierto", por lo que
 * está DESHABILITADA salvo que se defina la variable de entorno
 * REGISTRO_ABIERTO=1. Según OPENCODE.md, los usuarios que se registren
 * tendrán rol de administrador, así que dejarlo público sería un riesgo.
 */

sesionActiva();
if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    redirigir('/admin');
}

$errores = [];

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
    if (strlen($contrasena) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($contrasena !== $repetir) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    // Comprobación de correo duplicado.
    if (empty($errores)) {
        $consulta = $db->prepare('SELECT COUNT(*) AS total FROM usuarios WHERE email = ?');
        $consulta->bind_param('s', $email);
        $consulta->execute();
        $total = (int) $consulta->get_result()->fetch_assoc()['total'];
        $consulta->close();

        if ($total > 0) {
            $errores[] = 'Ya existe una cuenta con ese correo electrónico.';
        }
    }

    if (empty($errores)) {
        $hash = password_hash($contrasena, PASSWORD_BCRYPT);

        // Los usuarios registrados por esta vía son administradores (rol 1).
        $rol = 1;
        $consulta = $db->prepare('INSERT INTO usuarios (nombre, email, telefono, contrasena, rol) VALUES (?, ?, ?, ?, ?)');
        $consulta->bind_param('ssssi', $nombre, $email, $telefono, $hash, $rol);
        $guardado = $consulta->execute();
        $consulta->close();

        if ($guardado) {
            redirigir('/login', 'exito', 'Cuenta creada correctamente. Ya puedes iniciar sesión.');
        }

        $errores[] = 'No fue posible crear la cuenta. Inténtalo de nuevo.';
    }
}

template('header', [
    'titulo'         => 'Crear cuenta',
    'mostrarSidebar' => false,
]);
?>

<section class="auth__tarjeta">
    <div class="auth__marca">
        <span class="auth__logo" aria-hidden="true">C</span>
        <h1 class="auth__titulo">Crear cuenta</h1>
        <p class="auth__subtitulo">Los usuarios registrados tendrán acceso de administrador</p>
    </div>

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

    <form class="auth__formulario" action="/registro" method="post" data-validar autocomplete="on">
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="nombre">Nombre completo</label>
            <input class="control" type="text" id="nombre" name="nombre" required autocomplete="name"
                   value="<?= escaparValores($_POST['nombre'] ?? '') ?>">
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="email">Correo electrónico</label>
            <input class="control" type="email" id="email" name="email" required autocomplete="email"
                   value="<?= escaparValores($_POST['email'] ?? '') ?>">
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="telefono">Teléfono</label>
            <input class="control" type="tel" id="telefono" name="telefono" required autocomplete="tel"
                   value="<?= escaparValores($_POST['telefono'] ?? '') ?>">
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="contrasena">Contraseña</label>
            <input class="control" type="password" id="contrasena" name="contrasena" required
                   minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres">
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="repetir">Repetir contraseña</label>
            <input class="control" type="password" id="repetir" name="repetir" required
                   minlength="8" autocomplete="new-password" data-comparar="contrasena">
        </div>

        <button class="btn btn--primario btn--bloque" type="submit">Crear cuenta</button>
    </form>

    <p class="auth__enlace">
        ¿Ya tienes una cuenta?
        <a href="/login">Inicia sesión</a>
    </p>
</section>

<?php template('footer'); ?>

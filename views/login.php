<?php
/**
 * views/login.php — Acceso al panel de administración.
 *
 * Autentica por correo electrónico y contraseña (bcrypt).
 * Todo el feedback se muestra con alertas visuales en el DOM.
 */

// Si ya hay sesión iniciada, no tiene sentido ver el formulario.
sesionActiva();
if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    redirigir('/admin');
}

$errores = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Nunca se confía en la entrada del usuario: se recorta.
    // El escapado para HTML se hace al mostrar, no aquí (evita duplicar
    // los entidades y romper la búsqueda en la base de datos).
    $email      = trim($_POST['email'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';

    if ($email === '') {
        $errores[] = 'El correo electrónico es obligatorio.';
    }
    if ($contrasena === '') {
        $errores[] = 'La contraseña es obligatoria.';
    }

    if (empty($errores)) {
        $consulta = $db->prepare('SELECT id, nombre, email, contrasena, rol FROM usuarios WHERE email = ? LIMIT 1');
        $consulta->bind_param('s', $email);
        $consulta->execute();
        $resultado = $consulta->get_result();
        $usuario   = $resultado->fetch_assoc();
        $consulta->close();

        if ($usuario && password_verify($contrasena, $usuario['contrasena'])) {
            session_regenerate_id(true); // Evita el secuestro de sesión.

            $_SESSION['id']       = (int) $usuario['id'];
            $_SESSION['nombre']   = $usuario['nombre'];
            $_SESSION['email']    = $usuario['email'];
            $_SESSION['rol']      = (int) $usuario['rol'];
            $_SESSION['login']    = true;

            redirigir('/admin', 'exito', 'Bienvenido, ' . $usuario['nombre'] . '.');
        }

        $errores[] = 'El correo o la contraseña no son correctos.';
    }
}

template('header', [
    'titulo'         => 'Iniciar sesión',
    'mostrarSidebar' => false,
]);
?>

<section class="auth__tarjeta">
    <div class="auth__marca">
        <span class="auth__logo" aria-hidden="true">C</span>
        <h1 class="auth__titulo">Cotizaciones</h1>
        <p class="auth__subtitulo">Acceso al panel de administración</p>
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

    <form class="auth__formulario" action="/login" method="post" data-validar autocomplete="on">
        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="email">Correo electrónico</label>
            <input class="control" type="email" id="email" name="email" required
                   autocomplete="email" placeholder="usuario@empresa.com"
                   value="<?= escaparValores($_POST['email'] ?? '') ?>">
        </div>

        <div class="campo">
            <label class="campo__etiqueta campo__etiqueta--obligatorio" for="contrasena">Contraseña</label>
            <input class="control" type="password" id="contrasena" name="contrasena" required
                   autocomplete="current-password" placeholder="••••••••">
        </div>

        <button class="btn btn--primario btn--bloque" type="submit">Iniciar sesión</button>
    </form>

    <p class="auth__enlace">
        ¿Aún no tienes cuenta?
        <a href="/registro">Regístrate aquí</a>
    </p>
</section>

<?php template('footer'); ?>

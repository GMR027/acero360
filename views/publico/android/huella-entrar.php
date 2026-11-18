<?php
/**
 * POST /app/android/huella/entrar — la app, tras confirmar la huella en el teléfono, envía la
 * llave de esta instalación (con el _csrf de la página de inicio de sesión) y recibe una sesión.
 */

sesionActiva();
$app = \App\AppAndroid::desdeApp();
$usuario = $app ? \App\HuellaAndroid::usar((string) ($_POST['llave'] ?? ''), $app['instalacion']) : null;

if (!$usuario) {
    // huella=invalida: la app borra su llave y pide la contraseña (que emitirá una nueva).
    agregarAlerta('advertencia', 'El acceso con huella ya no es válido en este teléfono. Inicia sesión con tu contraseña.');
    header('Location: /login?huella=invalida');
    exit;
}

abrirSesionUsuario($usuario);
header('Location: /admin');
exit;

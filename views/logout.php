<?php
/**
 * views/logout.php — Cierre de sesión.
 * Destruye la sesión y devuelve al usuario a la pantalla de acceso.
 */

sesionActiva();
session_unset();
session_destroy();

// Se inicia una sesión nueva para poder mostrar la alerta de despedida.
session_start();
$_SESSION['alertas'][] = [
    'tipo'  => 'info',
    'texto' => 'Sesión cerrada correctamente.',
];

// «salio=1»: la app no vuelve a entrar sola con la huella justo después de cerrar sesión.
header('Location: /login?salio=1');
exit;

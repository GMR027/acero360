<?php
/** POST /app/android/avisos/revocar — con sesión: al apagar las notificaciones, la llave deja de servir. */

header('Content-Type: application/json; charset=utf-8');
sesionActiva();
$app = \App\AppAndroid::desdeApp();
if (!empty($_SESSION['login']) && $app) {
    \App\AvisosAndroid::revocar((int) $_SESSION['id'], $app['instalacion']);
}
echo json_encode(['ok' => true]);

<?php
/** POST /app/android/huella/revocar — con sesión: al quitar la huella en la app, la llave deja de servir. */

header('Content-Type: application/json; charset=utf-8');
sesionActiva();
$app = \App\AppAndroid::desdeApp();
if (!empty($_SESSION['login']) && $app) {
    \App\HuellaAndroid::revocar((int) $_SESSION['id'], $app['instalacion']);
}
echo json_encode(['ok' => true]);

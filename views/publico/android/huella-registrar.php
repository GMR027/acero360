<?php
/**
 * POST /app/android/huella/registrar — con sesión y desde la app: emite la llave de huella de
 * esta instalación y la devuelve en JSON para que la app la guarde (ver clases/HuellaAndroid.php).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sesionActiva();
$app = \App\AppAndroid::desdeApp();

if (empty($_SESSION['login']) || !sesionSigueVigente() || !$app) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$llave = \App\HuellaAndroid::emitir((int) $_SESSION['id'], $app['instalacion']);
if ($llave === null) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'llave' => $llave]);

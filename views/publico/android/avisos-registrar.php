<?php
/**
 * POST /app/android/avisos/registrar — con sesión y desde la app: emite la llave de avisos de
 * esta instalación y la devuelve en JSON para que la app revise en segundo plano los avisos
 * nuevos del Centro de seguimiento (ver clases/AvisosAndroid.php).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
sesionActiva();
$app = \App\AppAndroid::desdeApp();

if (empty($_SESSION['login']) || (int) ($_SESSION['rol'] ?? 0) !== 1 || !sesionSigueVigente() || !$app) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$llave = \App\AvisosAndroid::emitir((int) $_SESSION['id'], $app['instalacion']);
if ($llave === null) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'llave' => $llave, 'instalacion' => $app['instalacion']]);

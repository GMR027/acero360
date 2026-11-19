<?php
/**
 * GET /app/android/avisos.json — la app Android, en segundo plano y SIN sesión, pide los avisos
 * sin leer del Centro de seguimiento para mostrarlos como notificaciones del teléfono.
 *
 * Cabeceras: «X-Acero360-Llave: <llave>» y «X-Acero360-Instalacion: <uuid>» (no Authorization:
 * Apache/PHP-FPM la descartan a veces). La llave solo sirve para esta lectura (no abre sesión).
 * 401 → la app borra la llave; el sitio le da otra en la siguiente página con sesión.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$llave = trim((string) ($_SERVER['HTTP_X_ACERO360_LLAVE'] ?? ''));
$instalacion = (string) ($_SERVER['HTTP_X_ACERO360_INSTALACION'] ?? '');
$usuario = preg_match('/^[0-9a-f-]{36}$/', $instalacion) ? \App\AvisosAndroid::usar($llave, $instalacion) : null;

if (!$usuario) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true] + \App\AvisosAndroid::pendientes((int) $usuario['id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

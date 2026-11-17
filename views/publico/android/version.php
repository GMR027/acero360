<?php
/**
 * views/publico/android/version.php — GET /app/android/version.json
 * La app Android lo consulta al abrir para saber si hay una versión más nueva. Público: solo
 * versión, tamaño, suma y notas. CORS abierto porque la pantalla local de la app tiene otro origen.
 */

$esquema = conexionSegura() ? 'https' : 'http';
$info = \App\AppAndroid::infoPublica($esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');
echo json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

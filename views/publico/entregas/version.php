<?php
/**
 * views/publico/entregas/version.php — GET /app/entregas/version.json
 *
 * Versión vigente de la app independiente «Acero Entregas». Público: solo
 * versión, tamaño, suma y notas; no expone datos de la plataforma.
 */

$esquema = conexionSegura() ? 'https' : 'http';
$info = \App\AppAndroid::infoPublica(
    $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    'entregas'
);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');
echo json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

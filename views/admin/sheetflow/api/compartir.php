<?php
/**
 * views/admin/sheetflow/api/compartir.php — Enlace público de solo lectura.
 *
 * accion=estado → token y URL actuales
 * accion=crear  → crea el token si no existe
 * accion=quitar → desactiva el enlace
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$hoja = \Model\SheetflowHoja::find((int) ($_POST['id'] ?? 0));
if (!$hoja) {
    json_respuesta(['success' => false, 'error' => 'La tabla no existe o fue eliminada.'], 404);
}

$db = \Model\SheetflowHoja::conexion();
$id = (int) $hoja->id;
$accion = (string) ($_POST['accion'] ?? 'estado');
try {
    $qToken = $db->prepare('SELECT token_publico FROM sheetflow_hojas WHERE id = ? LIMIT 1');
    $qToken->bind_param('i', $id);
    $qToken->execute();
    $token = (string) ($qToken->get_result()->fetch_row()[0] ?? '');
    $qToken->close();
} catch (\Throwable $e) {
    json_respuesta(['success' => false, 'error' => 'Falta aplicar la migración de enlaces públicos.'], 500);
}

function url_publica_sheetflow(string $token): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . '/s?t=' . $token;
}

if ($accion === 'crear') {
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $q = $db->prepare('UPDATE sheetflow_hojas SET token_publico = ?, actualizado = actualizado WHERE id = ?');
        $q->bind_param('si', $token, $id);
        $q->execute();
        $q->close();
    }
    json_respuesta(['success' => true, 'token' => $token, 'url' => url_publica_sheetflow($token)]);
}

if ($accion === 'quitar') {
    $q = $db->prepare('UPDATE sheetflow_hojas SET token_publico = NULL, actualizado = actualizado WHERE id = ?');
    $q->bind_param('i', $id);
    $q->execute();
    $q->close();
    json_respuesta(['success' => true, 'token' => '']);
}

if ($accion === 'estado') {
    json_respuesta(['success' => true, 'token' => $token, 'url' => $token !== '' ? url_publica_sheetflow($token) : '']);
}

json_respuesta(['success' => false, 'error' => 'Acción no válida'], 422);

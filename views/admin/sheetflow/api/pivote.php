<?php
/**
 * views/admin/sheetflow/api/pivote.php — Crea una hoja nueva con un resumen
 * (tabla dinámica) calculado en el navegador.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
$filas = json_decode((string) ($_POST['filas'] ?? ''), true);
if (!is_array($filas) || !$filas || count($filas) > 1000) {
    json_respuesta(['success' => false, 'error' => 'El resumen no tiene datos válidos'], 422);
}
foreach ($filas as $fila) {
    if (!is_array($fila) || count($fila) > 30) {
        json_respuesta(['success' => false, 'error' => 'El resumen tiene una forma no válida'], 422);
    }
}

try {
    $hoja = \Classes\Sheetflow::crearHoja($nombre !== '' ? $nombre : 'Resumen', $filas, (int) ($_SESSION['id'] ?? 0));
    json_respuesta(['success' => true, 'id' => (int) $hoja->id, 'nombre' => (string) $hoja->nombre]);
} catch (\Throwable $e) {
    json_respuesta(['success' => false, 'error' => $e->getMessage() ?: 'No se pudo crear el resumen'], 422);
}

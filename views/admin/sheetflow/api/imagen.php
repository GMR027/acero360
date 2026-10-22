<?php
/**
 * views/admin/sheetflow/api/imagen.php — Sube una imagen para una celda.
 */

soloAdmin();

use Classes\Imagenes;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$hoja = \Model\SheetflowHoja::find((int) ($_POST['id'] ?? 0));
if (!$hoja) {
    json_respuesta(['success' => false, 'error' => 'La tabla no existe o fue eliminada'], 404);
}

$archivo = $_FILES['imagen'] ?? null;
if (!$archivo || empty($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name']) || ($archivo['size'] ?? 0) > 20 * 1024 * 1024) {
    json_respuesta(['success' => false, 'error' => 'Elige una imagen de hasta 20 MB'], 422);
}

$nombre = Imagenes::guardarGrande($archivo['tmp_name'], 1600);
if (!$nombre) {
    json_respuesta(['success' => false, 'error' => 'La imagen no es válida (jpg, png, webp o gif)'], 422);
}

json_respuesta(['success' => true, 'src' => '/public/uploads/vplan/' . $nombre]);

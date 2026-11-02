<?php
/** views/admin/redactor/api/imagen.php — Sube una imagen para el documento. */

soloAdmin();

use Classes\Imagenes;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe o fue eliminado'], 404);
}

$archivoId = (int) ($_POST['archivo_id'] ?? 0);
if ($archivoId > 0) {
    $nombre = Imagenes::copiarDesdeArchivo($archivoId, 2400);
    if (!$nombre) {
        json_respuesta(['success' => false, 'error' => 'La imagen del Archivo no se puede insertar (usa jpg, png, gif, webp, bmp o avif)'], 422);
    }
    json_respuesta(['success' => true, 'src' => '/public/uploads/vplan/' . $nombre]);
}

$archivo = $_FILES['imagen'] ?? null;
if (!$archivo || empty($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name']) || ($archivo['size'] ?? 0) > 20 * 1024 * 1024) {
    json_respuesta(['success' => false, 'error' => 'Elige una imagen de hasta 20 MB'], 422);
}

$nombre = Imagenes::guardarGrande($archivo['tmp_name'], 2400);
if (!$nombre) {
    json_respuesta(['success' => false, 'error' => 'La imagen no es válida (jpg, png, webp o gif)'], 422);
}

json_respuesta(['success' => true, 'src' => '/public/uploads/vplan/' . $nombre]);

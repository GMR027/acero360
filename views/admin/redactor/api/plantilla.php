<?php
/** views/admin/redactor/api/plantilla.php — Guarda el documento como plantilla propia. */

soloAdmin();

use Classes\RedactorPlantillas;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
if ($nombre === '') {
    json_respuesta(['success' => false, 'error' => 'Escribe un nombre para la plantilla'], 422);
}

if (!RedactorPlantillas::guardar($nombre, (string) ($_POST['descripcion'] ?? ''), $documento)) {
    json_respuesta(['success' => false, 'error' => 'No se pudo guardar la plantilla'], 500);
}

json_respuesta(['success' => true]);

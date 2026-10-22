<?php
/** views/admin/redactor/api/version.php — Contenido de una versión concreta. */

soloAdmin();

use Classes\Redactor;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$version = \Model\RedactorDocumento::version((int) $documento->id, (int) ($_POST['version_id'] ?? 0));
if (!$version) {
    json_respuesta(['success' => false, 'error' => 'Versión no encontrada'], 404);
}

$version['contenido'] = Redactor::limpiar((string) $version['contenido']);
json_respuesta(['success' => true, 'version' => $version]);

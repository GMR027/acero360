<?php
/** views/admin/redactor/api/palabra.php — Agrega una palabra al diccionario personal. */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

if (!\Model\RedactorDocumento::agregarPalabra((string) ($_POST['palabra'] ?? ''))) {
    json_respuesta(['success' => false, 'error' => 'Palabra no válida'], 422);
}

json_respuesta(['success' => true]);

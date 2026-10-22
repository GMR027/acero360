<?php
/** views/admin/redactor/api/firma.php — Guarda una firma manuscrita del usuario. */

soloAdmin();

use Classes\RedactorFirmas;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$resultado = RedactorFirmas::guardar((int) ($_SESSION['id'] ?? 0), (string) ($_POST['nombre'] ?? ''), (string) ($_POST['png'] ?? ''));
if (is_string($resultado)) {
    json_respuesta(['success' => false, 'error' => $resultado], 422);
}

json_respuesta(['success' => true, 'firma' => $resultado]);

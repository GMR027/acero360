<?php
/** views/admin/redactor/api/firma-borrar.php — Borra una firma manuscrita del usuario. */

soloAdmin();

use Classes\RedactorFirmas;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

RedactorFirmas::eliminar((int) ($_SESSION['id'] ?? 0), (int) ($_POST['firma'] ?? 0));
json_respuesta(['success' => true]);

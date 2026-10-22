<?php
/** views/admin/redactor/api/versiones.php — Lista las versiones guardadas del documento. */

soloAdmin();

$documento = \Model\RedactorDocumento::find((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

json_respuesta(['success' => true, 'versiones' => \Model\RedactorDocumento::versiones((int) $documento->id)]);

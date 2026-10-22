<?php
/**
 * views/admin/sheetflow/api/version.php — Huella de la tabla para refrescar vistas abiertas.
 */

soloAdmin();

$hoja = \Model\SheetflowHoja::find((int) ($_GET['id'] ?? 0));
if (!$hoja) {
    json_respuesta(['success' => false, 'error' => 'La tabla no existe'], 404);
}

json_respuesta([
    'success' => true,
    'version' => md5($hoja->actualizado . '|' . $hoja->nombre . '|' . strlen((string) $hoja->datos)),
]);

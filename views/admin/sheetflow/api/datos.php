<?php
/**
 * views/admin/sheetflow/api/datos.php — Tabla ya formateada (para el Redactor y el Editor).
 */

soloAdmin();

use Classes\Sheetflow;

$hoja = \Model\SheetflowHoja::find((int) ($_GET['id'] ?? 0));
if (!$hoja) {
    json_respuesta(['success' => false, 'error' => 'La tabla no existe'], 404);
}

json_respuesta(['success' => true, 'nombre' => $hoja->nombre] + Sheetflow::matriz($hoja));

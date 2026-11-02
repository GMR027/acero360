<?php
/**
 * views/admin/sheetflow/api/externo.php — Datos externos para SheetFlow.
 *
 * accion=catalogo → empresas, productos, campos y valores actuales.
 * accion=valores  → resuelve las referencias enviadas (JSON en `refs`).
 *
 * Solo lectura: nunca modifica inventario ni tablas.
 */

soloAdmin();

use Classes\DatosExternos;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$accion = (string) ($_POST['accion'] ?? '');

if ($accion === 'catalogo') {
    json_respuesta(['success' => true] + DatosExternos::catalogo());
}

if ($accion === 'valores') {
    $refs = json_decode((string) ($_POST['refs'] ?? ''), true);
    if (!is_array($refs) || count($refs) > 5000) {
        json_respuesta(['success' => false, 'error' => 'Referencias no válidas'], 422);
    }
    $claves = array_values(array_filter($refs, 'is_string'));
    json_respuesta(['success' => true, 'valores' => DatosExternos::resolver($claves)]);
}

json_respuesta(['success' => false, 'error' => 'Acción no válida'], 422);

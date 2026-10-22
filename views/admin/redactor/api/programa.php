<?php
/**
 * views/admin/redactor/api/programa.php — Tablas externas para insertar.
 *
 * En Acero 360 no existen los módulos de programa de obra ni Punch list;
 * sólo se ofrecen las tablas de SheetFlow (tipo `sf:ID`).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$tipo = (string) ($_POST['tabla'] ?? '');

if ($tipo === 'punchvista' || $tipo === 'punchlist' || $tipo === '@programa') {
    json_respuesta(['success' => false, 'error' => 'Ese módulo no está disponible en Acero 360'], 422);
}

if ($tipo !== '') {
    $tabla = \Model\RedactorDocumento::tablaExtra($tipo);
    if (!$tabla) {
        json_respuesta(['success' => false, 'error' => 'Tabla no válida'], 422);
    }
    json_respuesta(['success' => true] + $tabla);
}

json_respuesta(['success' => true, 'filas' => []]);

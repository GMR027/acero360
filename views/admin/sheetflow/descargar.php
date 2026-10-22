<?php
/**
 * views/admin/sheetflow/descargar.php — Exporta una tabla.
 *
 * Formatos: xlsx (Excel), csv, pdf y .sheetflow (para volver a importarla).
 * Con inline=1 el PDF se abre en el navegador (Imprimir).
 */

soloAdmin();

use Classes\Sheetflow;

$hoja = \Model\SheetflowHoja::find((int) ($_GET['id'] ?? 0));
if (!$hoja) {
    redirigir('/admin/sheetflow', 'error', 'La tabla no existe o fue eliminada.');
}

$formato = in_array($_GET['formato'] ?? 'xlsx', ['xlsx', 'csv', 'pdf', 'sheetflow'], true) ? $_GET['formato'] : 'xlsx';
$base = date('Y_m_d') . '_' . slug_archivo($hoja->nombre);
@set_time_limit(120);

try {
    $datos = match ($formato) {
        'csv' => Sheetflow::csv($hoja),
        'pdf' => Sheetflow::pdf($hoja),
        'sheetflow' => Sheetflow::aArchivo($hoja),
        default => Sheetflow::xlsx($hoja),
    };
} catch (\Throwable $e) {
    error_log('SheetFlow exportar: ' . $e->getMessage());
    redirigir('/admin/sheetflow/ver?id=' . (int) $hoja->id, 'error', 'No se pudo generar el archivo.');
}

$tipos = [
    'xlsx'      => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv'       => 'text/csv; charset=utf-8',
    'pdf'       => 'application/pdf',
    'sheetflow' => 'application/octet-stream',
];

enviar_descarga($datos, $tipos[$formato], $base . '.' . $formato, $formato === 'pdf' && !empty($_GET['inline']));

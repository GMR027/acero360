<?php
/**
 * views/admin/redactor/descargar.php — Exporta un documento.
 *
 * Formatos: docx (Word), odt (LibreOffice) y pdf. Con inline=1 el PDF se abre
 * en el navegador (Imprimir). Los comentarios, los cambios marcados y las
 * firmas se incluyen según los parámetros de la barra de exportación.
 */

soloAdmin();

use Classes\Redactor;

$documento = \Model\RedactorDocumento::find((int) ($_GET['id'] ?? 0));
if (!$documento) {
    redirigir('/admin/redactor', 'error', 'El documento no existe o fue eliminado.');
}

$formato = in_array($_GET['formato'] ?? 'docx', ['docx', 'odt', 'pdf'], true) ? $_GET['formato'] : 'docx';
$base = date('Y_m_d') . '_' . slug_archivo($documento->nombre);
@set_time_limit(120);

try {
    $opc = [
        'cambios'    => !empty($_GET['cambios']),
        'comentarios' => !empty($_GET['comentarios']),
        'firmas'     => !isset($_GET['firmas']) || !empty($_GET['firmas']),
    ];
    $datos = $formato === 'pdf' ? Redactor::pdf($documento, $opc) : Redactor::exportar($documento, $formato, $opc);
} catch (\Throwable $e) {
    error_log('Redactor exportar: ' . $e->getMessage());
    redirigir('/admin/redactor/ver?id=' . (int) $documento->id, 'error', 'No se pudo generar el archivo.');
}

$tipos = [
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt'  => 'application/vnd.oasis.opendocument.text',
    'pdf'  => 'application/pdf',
];

enviar_descarga($datos, $tipos[$formato], $base . '.' . $formato, $formato === 'pdf' && !empty($_GET['inline']));

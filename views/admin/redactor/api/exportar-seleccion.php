<?php
/** views/admin/redactor/api/exportar-seleccion.php — Descarga sólo el fragmento seleccionado. */

soloAdmin();

use Classes\Redactor;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$html = Redactor::limpiar((string) ($_POST['html'] ?? ''));
if (trim(strip_tags($html)) === '' && !str_contains($html, '<img')) {
    json_respuesta(['success' => false, 'error' => 'La selección está vacía'], 422);
}

$formato = in_array($_POST['formato'] ?? 'docx', ['docx', 'odt', 'pdf'], true) ? $_POST['formato'] : 'docx';
$copia = new \Model\RedactorDocumento(get_object_vars($documento));
$copia->contenido = $html;
@set_time_limit(120);

try {
    $opc = ['cambios' => false, 'comentarios' => false, 'firmas' => false];
    $datos = $formato === 'pdf' ? Redactor::pdf($copia, $opc) : Redactor::exportar($copia, $formato, $opc);
} catch (\Throwable $e) {
    error_log('Redactor exportar selección: ' . $e->getMessage());
    json_respuesta(['success' => false, 'error' => 'No se pudo generar el archivo'], 500);
}

$tipos = [
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt'  => 'application/vnd.oasis.opendocument.text',
    'pdf'  => 'application/pdf',
];

header_remove('Content-Type');
header('Content-Type: ' . $tipos[$formato]);
header('X-Nombre-Archivo: ' . date('Y_m_d') . '_' . slug_archivo($documento->nombre) . '_seleccion.' . $formato);
header('Content-Length: ' . strlen($datos));
echo $datos;
exit;

<?php
/**
 * views/admin/redactor/accion.php — Duplicar, eliminar o mover de carpeta.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/redactor');
}

$accion = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

if ($accion === 'renombrar') {
    $afectados = \Classes\Carpetas::renombrar('redactor', 0, (string) ($_POST['de'] ?? ''), (string) ($_POST['a'] ?? ''));
    json_respuesta(['success' => true, 'afectados' => $afectados]);
}

$id = (int) ($_POST['id'] ?? 0);
$documento = \Model\RedactorDocumento::find($id);

if (!$documento) {
    if ($accion === 'mover') {
        json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
    }
    redirigir('/admin/redactor', 'error', 'El documento no existe o fue eliminado.');
}

if ($accion === 'duplicar') {
    $copia = new \Model\RedactorDocumento(get_object_vars($documento));
    $copia->id = null;
    $copia->nombre = mb_substr($documento->nombre, 0, 140) . ' (copia)';
    $copia->fecha = date('Y-m-d');
    $copia->creado_por = (int) ($_SESSION['id'] ?? 0);
    $copia->guardar();
    redirigir('/admin/redactor', 'exito', 'Documento duplicado.');
}

if ($accion === 'eliminar') {
    $documento->eliminar();
    $documento->eliminarArchivos();
    redirigir('/admin/redactor', 'exito', 'Documento eliminado.');
}

if ($accion === 'mover') {
    \Classes\Carpetas::mover('redactor', 0, $id, (string) ($_POST['carpeta'] ?? ''));
    json_respuesta(['success' => true]);
}

redirigir('/admin/redactor');

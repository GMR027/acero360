<?php
/**
 * views/admin/sheetflow/accion.php — Duplicar, eliminar o mover de carpeta.
 *
 * Las tres acciones llegan por POST (con el _csrf que inyecta la aplicación).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/sheetflow');
}

$accion = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

if ($accion === 'renombrar') {
    $de = (string) ($_POST['de'] ?? '');
    $a = (string) ($_POST['a'] ?? '');
    $afectadas = \Classes\Carpetas::renombrar('sheetflow', 0, $de, $a);
    json_respuesta(['success' => true, 'afectadas' => $afectadas]);
}

$id = (int) ($_POST['id'] ?? 0);
$hoja = \Model\SheetflowHoja::find($id);

if (!$hoja) {
    if ($accion === 'mover') {
        json_respuesta(['success' => false, 'error' => 'La tabla no existe'], 404);
    }
    redirigir('/admin/sheetflow', 'error', 'La tabla no existe o fue eliminada.');
}

if ($accion === 'duplicar') {
    $nueva = new \Model\SheetflowHoja(get_object_vars($hoja));
    $nueva->id = null;
    $nueva->nombre = mb_substr($hoja->nombre, 0, 140) . ' (copia)';
    $nueva->fecha = date('Y-m-d');
    $nueva->creado_por = (int) ($_SESSION['id'] ?? 0);
    $nueva->guardar();
    redirigir('/admin/sheetflow/abrir?id=' . (int) $nueva->id, 'exito', 'Tabla duplicada.');
}

if ($accion === 'eliminar') {
    $imagenes = $hoja->imagenes();
    $hoja->eliminar();
    \Classes\Sheetflow::limpiarImagenes($imagenes, [], $id);
    redirigir('/admin/sheetflow', 'exito', 'Tabla eliminada.');
}

if ($accion === 'mover') {
    $carpeta = (string) ($_POST['carpeta'] ?? '');
    \Classes\Carpetas::mover('sheetflow', 0, $id, $carpeta);
    json_respuesta(['success' => true]);
}

redirigir('/admin/sheetflow');

<?php
/**
 * views/admin/sheetflow/api/guardar.php — Guarda una tabla (AJAX).
 *
 * Controla la versión para no pisar lo que otra sesión guardó después de
 * abrir la tabla. El JSON se sanea con Classes\Sheetflow::normalizar().
 */

soloAdmin();

use Classes\Sheetflow;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$hoja = \Model\SheetflowHoja::find((int) ($_POST['id'] ?? 0));
if (!$hoja) {
    json_respuesta(['success' => false, 'error' => 'La tabla no existe o fue eliminada'], 404);
}

$version = (string) ($_POST['version'] ?? '');
if ($version !== '' && empty($_POST['forzar']) && $version !== (string) $hoja->actualizado) {
    json_respuesta([
        'success'   => false,
        'conflicto' => true,
        'error'     => 'Esta tabla se guardó desde otra sesión después de que la abrieras.',
    ], 409);
}

$crudo = (string) ($_POST['datos'] ?? '');
if (strlen($crudo) > Sheetflow::MAX_BYTES) {
    json_respuesta(['success' => false, 'error' => 'La tabla es demasiado grande'], 422);
}

$limpio = Sheetflow::normalizar(json_decode($crudo, true));

$nueva = new \Model\SheetflowHoja([
    'id'           => $hoja->id,
    'creado_por'   => $hoja->creado_por,
    'descripcion'  => $_POST['descripcion'] ?? $hoja->descripcion,
    'nombre'       => $_POST['nombre'] ?? $hoja->nombre,
    'fecha'        => $_POST['fecha'] ?? $hoja->fecha,
    'departamento' => $_POST['departamento'] ?? $hoja->departamento,
    'datos'        => json_encode($limpio, JSON_UNESCAPED_UNICODE),
]);

if ($alertas = $nueva->validar()) {
    json_respuesta(['success' => false, 'error' => $alertas['error'][0]], 422);
}

$nueva->guardar();
Sheetflow::limpiarImagenes($hoja->imagenes(), $nueva->imagenes(), (int) $hoja->id);

$actual = \Model\SheetflowHoja::find((int) $hoja->id);
json_respuesta(['success' => true, 'version' => (string) $actual->actualizado]);

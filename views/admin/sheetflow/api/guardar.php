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

$limpio = Sheetflow::refrescarExternos(json_decode($crudo, true));
$jsonNuevo = json_encode($limpio, JSON_UNESCAPED_UNICODE);

// Guarda una versión de la tabla anterior (máx. 20 por hoja, sin duplicados).
$previo = (string) $hoja->datos;
if ($previo !== '' && $previo !== $jsonNuevo) {
    try {
        $db = \Model\SheetflowHoja::conexion();
        $hash = md5($previo);
        $existe = $db->prepare('SELECT id FROM sheetflow_versiones WHERE hoja_id = ? AND MD5(datos) = ? LIMIT 1');
        $idHoja = (int) $hoja->id;
        $existe->bind_param('is', $idHoja, $hash);
        $existe->execute();
        $yaEsta = (bool) $existe->get_result()->fetch_row();
        $existe->close();
        if (!$yaEsta) {
            $ins = $db->prepare('INSERT INTO sheetflow_versiones (hoja_id, nombre, datos, creado_por) VALUES (?, ?, ?, ?)');
            $nombreVersion = (string) $hoja->nombre;
            $usuarioId = (int) ($_SESSION['id'] ?? 0);
            $ins->bind_param('issi', $idHoja, $nombreVersion, $previo, $usuarioId);
            $ins->execute();
            $ins->close();
            $db->query("DELETE FROM sheetflow_versiones WHERE hoja_id = $idHoja AND id NOT IN (SELECT id FROM (SELECT id FROM sheetflow_versiones WHERE hoja_id = $idHoja ORDER BY id DESC LIMIT 20) t)");
        }
    } catch (\Throwable $e) {
        // Si la migración de versiones no está aplicada, guardar la tabla sigue funcionando.
    }
}

$nueva = new \Model\SheetflowHoja([
    'id'           => $hoja->id,
    'creado_por'   => $hoja->creado_por,
    'descripcion'  => $_POST['descripcion'] ?? $hoja->descripcion,
    'nombre'       => $_POST['nombre'] ?? $hoja->nombre,
    'fecha'        => $_POST['fecha'] ?? $hoja->fecha,
    'departamento' => $_POST['departamento'] ?? $hoja->departamento,
    'datos'        => $jsonNuevo,
]);

if ($alertas = $nueva->validar()) {
    json_respuesta(['success' => false, 'error' => $alertas['error'][0]], 422);
}

$nueva->guardar();
Sheetflow::limpiarImagenes($hoja->imagenes(), $nueva->imagenes(), (int) $hoja->id);

$actual = \Model\SheetflowHoja::find((int) $hoja->id);
json_respuesta(['success' => true, 'version' => (string) $actual->actualizado]);

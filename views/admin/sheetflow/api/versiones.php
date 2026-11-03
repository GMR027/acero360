<?php
/**
 * views/admin/sheetflow/api/versiones.php — Historial de versiones de una hoja.
 *
 * accion=listar    → versiones guardadas (sin el JSON completo)
 * accion=crear     → guarda una versión de la tabla actual
 * accion=restaurar → reemplaza la tabla por una versión (guarda la actual antes)
 */

soloAdmin();

use Classes\Sheetflow;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$hoja = \Model\SheetflowHoja::find((int) ($_POST['id'] ?? 0));
if (!$hoja) {
    json_respuesta(['success' => false, 'error' => 'La tabla no existe o fue eliminada.'], 404);
}
$accion = (string) ($_POST['accion'] ?? 'listar');
$db = \Model\SheetflowHoja::conexion();
$idHoja = (int) $hoja->id;
$usuarioId = (int) ($_SESSION['id'] ?? 0);

function guardar_version($db, int $idHoja, string $nombre, string $datos, int $usuarioId): void
{
    $hash = md5($datos);
    $q = $db->prepare('SELECT id FROM sheetflow_versiones WHERE hoja_id = ? AND MD5(datos) = ? LIMIT 1');
    $q->bind_param('is', $idHoja, $hash);
    $q->execute();
    if ($q->get_result()->fetch_row()) {
        $q->close();
        return;
    }
    $q->close();
    $ins = $db->prepare('INSERT INTO sheetflow_versiones (hoja_id, nombre, datos, creado_por) VALUES (?, ?, ?, ?)');
    $ins->bind_param('issi', $idHoja, $nombre, $datos, $usuarioId);
    $ins->execute();
    $ins->close();
    $db->query("DELETE FROM sheetflow_versiones WHERE hoja_id = $idHoja AND id NOT IN (SELECT id FROM (SELECT id FROM sheetflow_versiones WHERE hoja_id = $idHoja ORDER BY id DESC LIMIT 20) t)");
}

try {
if ($accion === 'listar') {
    $q = $db->prepare('SELECT id, nombre, creado_en, LENGTH(datos) AS bytes FROM sheetflow_versiones WHERE hoja_id = ? ORDER BY id DESC LIMIT 20');
    $q->bind_param('i', $idHoja);
    $q->execute();
    $filas = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    json_respuesta(['success' => true, 'versiones' => array_map(fn($f) => [
        'id' => (int) $f['id'],
        'nombre' => (string) $f['nombre'],
        'fecha' => (string) $f['creado_en'],
        'bytes' => (int) $f['bytes'],
    ], $filas)]);
}

if ($accion === 'crear') {
    guardar_version($db, $idHoja, (string) $hoja->nombre, (string) $hoja->datos, $usuarioId);
    json_respuesta(['success' => true]);
}

if ($accion === 'restaurar') {
    $versionId = (int) ($_POST['version'] ?? 0);
    $q = $db->prepare('SELECT datos, nombre FROM sheetflow_versiones WHERE id = ? AND hoja_id = ? LIMIT 1');
    $q->bind_param('ii', $versionId, $idHoja);
    $q->execute();
    $version = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$version) {
        json_respuesta(['success' => false, 'error' => 'La versión no existe.'], 404);
    }

    // La tabla actual queda guardada antes de reemplazarla.
    guardar_version($db, $idHoja, (string) $hoja->nombre, (string) $hoja->datos, $usuarioId);

    $nueva = new \Model\SheetflowHoja([
        'id'           => $idHoja,
        'creado_por'   => $hoja->creado_por,
        'descripcion'  => $hoja->descripcion,
        'nombre'       => $hoja->nombre,
        'fecha'        => $hoja->fecha,
        'departamento' => $hoja->departamento,
        'datos'        => (string) $version['datos'],
    ]);
    if ($alertas = $nueva->validar()) {
        json_respuesta(['success' => false, 'error' => $alertas['error'][0]], 422);
    }
    $nueva->guardar();
    json_respuesta(['success' => true]);
}

json_respuesta(['success' => false, 'error' => 'Acción no válida'], 422);
} catch (\Throwable $e) {
    json_respuesta(['success' => false, 'error' => 'Falta aplicar la migración de versiones.'], 500);
}

<?php
/**
 * views/admin/redactor/api/hoja.php — Crea una tabla de SheetFlow desde el Redactor.
 *
 * `accion=nueva` (en blanco) o `accion=convertir` con `filas` (JSON; el
 * encabezado va en la primera fila). Devuelve el HTML vinculado (`data-sf`).
 */

soloAdmin();

use Classes\Sheetflow;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe'], 404);
}

$nombre = mb_substr(trim((string) ($_POST['nombre'] ?? '')), 0, 150);
if ($nombre === '') {
    $nombre = mb_substr('Tabla · ' . $documento->nombre, 0, 150);
}

$filas = null;
$accion = (string) ($_POST['accion'] ?? '');

if ($accion === 'convertir') {
    $json = json_decode((string) ($_POST['filas'] ?? ''), true);
    if (!is_array($json) || count($json) < 1) {
        json_respuesta(['success' => false, 'error' => 'La tabla no tiene datos'], 422);
    }
    $filas = array_map(
        fn($f) => is_array($f) ? array_map(fn($v) => is_scalar($v) ? (string) $v : '', $f) : [],
        array_slice($json, 0, Sheetflow::MAX_FILAS + 1)
    );
} elseif ($accion !== 'nueva') {
    json_respuesta(['success' => false, 'error' => 'Acción no válida'], 422);
}

try {
    $hoja = Sheetflow::crearHoja($nombre, $filas, (int) ($_SESSION['id'] ?? 0));
} catch (\RuntimeException $e) {
    json_respuesta(['success' => false, 'error' => $e->getMessage()], 422);
}

json_respuesta([
    'success' => true,
    'id'      => (int) $hoja->id,
    'html'    => Sheetflow::htmlRedactor($hoja),
    'url'     => '/admin/sheetflow/abrir?id=' . (int) $hoja->id,
]);

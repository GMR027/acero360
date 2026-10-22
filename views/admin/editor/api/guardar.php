<?php
/**
 * views/admin/editor/api/guardar.php — Guarda el documento y sus láminas.
 *
 * Recibe `datos` (JSON): id, version, nombre, fondo y láminas. Cada lámina
 * trae su JSON de Konva y, opcionalmente, un PNG en dataURL para la miniatura.
 */

soloAdmin();

use Classes\Imagenes;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$datos = json_decode((string) ($_POST['datos'] ?? ''), true);
if (!is_array($datos)) {
    json_respuesta(['success' => false, 'error' => 'Datos no válidos'], 422);
}

$documento = \Model\EditorDocumento::find((int) ($datos['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe o fue eliminado'], 404);
}

$version = (string) ($datos['version'] ?? '');
if ($version !== '' && empty($_POST['forzar']) && $version !== (string) $documento->actualizado) {
    json_respuesta([
        'success'   => false,
        'conflicto' => true,
        'error'     => 'Este documento se guardó desde otra sesión después de que lo abrieras.',
    ], 409);
}

$entrantes = $datos['laminas'] ?? null;
if (!is_array($entrantes) || count($entrantes) < 1 || count($entrantes) > 60) {
    json_respuesta(['success' => false, 'error' => 'El documento debe tener entre 1 y 60 láminas'], 422);
}

$nombre = trim((string) ($datos['nombre'] ?? $documento->nombre));
if ($nombre === '') {
    json_respuesta(['success' => false, 'error' => 'El nombre es obligatorio'], 422);
}

$fondo = (string) ($datos['fondo'] ?? $documento->fondo);
if (!isset(\Model\EditorDocumento::FONDOS[$fondo]) || $fondo === 'imagen') {
    $fondo = 'liso';
}

$config = $datos['fondo_config'] ?? [];
$paso = max(8, min(120, (int) ($config['paso'] ?? 40)));
$linea = preg_match('/^#[0-9a-f]{6}$/i', (string) ($config['color'] ?? '')) ? $config['color'] : '#e2e8f0';

/* Valida y guarda el PNG de una lámina. Devuelve el nombre del archivo o null. */
function guardarPngLamina(string $data): ?string
{
    if ($data === '') {
        return null;
    }
    if (!preg_match('#^data:image/png;base64,#', $data)) {
        return null;
    }
    $bin = base64_decode(substr($data, strlen('data:image/png;base64,')), true);
    if ($bin === false || strlen($bin) > 25 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        return null;
    }
    if (!is_dir(Imagenes::CARPETA_VPLAN)) {
        @mkdir(Imagenes::CARPETA_VPLAN, 0775, true);
    }
    $archivo = bin2hex(random_bytes(16)) . '.png';
    if (@file_put_contents(Imagenes::CARPETA_VPLAN . '/' . $archivo, $bin) === false) {
        return null;
    }
    return $archivo;
}

$existentes = [];
foreach ($documento->laminas() as $l) {
    $existentes[(int) $l->id] = $l;
}

$conservar = [];
$respuestaLaminas = [];

foreach ($entrantes as $i => $l) {
    $json = (string) ($l['json'] ?? '');
    if (strlen($json) > 3_000_000) {
        json_respuesta(['success' => false, 'error' => 'Una lámina es demasiado grande'], 422);
    }
    if ($json !== '') {
        json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            json_respuesta(['success' => false, 'error' => 'El contenido de una lámina no es válido'], 422);
        }
    }

    $id = (int) ($l['id'] ?? 0);
    $nombreLamina = mb_substr(trim((string) ($l['nombre'] ?? '')), 0, 60);
    if ($nombreLamina === '') {
        $nombreLamina = 'Lámina ' . ($i + 1);
    }

    $png = guardarPngLamina((string) ($l['png'] ?? ''));

    if ($id && isset($existentes[$id])) {
        $guardada = $existentes[$id];
        $viejoPng = $guardada->marcas_png;
        $guardada->orden = $i + 1;
        $guardada->nombre = $nombreLamina;
        $guardada->marcas_json = $json;
        if ($png) {
            $guardada->marcas_png = $png;
        }
        $guardada->guardar();
        if ($png && $viejoPng && $viejoPng !== $png) {
            Imagenes::eliminarVplan($viejoPng);
        }
        $conservar[] = $id;
        $respuestaLaminas[] = ['id' => (int) $guardada->id, 'png' => $png ?: $guardada->marcas_png];
    } else {
        $nueva = new \Model\EditorLamina([
            'documento_id' => (int) $documento->id,
            'orden'        => $i + 1,
            'nombre'       => $nombreLamina,
            'marcas_json'  => $json,
            'marcas_png'   => $png,
        ]);
        $nueva->guardar();
        $respuestaLaminas[] = ['id' => (int) $nueva->id, 'png' => $png];
    }
}

foreach ($existentes as $id => $l) {
    if (!in_array($id, $conservar, true)) {
        $l->eliminar();
        if (!empty($l->marcas_png)) {
            Imagenes::eliminarVplan($l->marcas_png);
        }
    }
}

$documento->nombre = $nombre;
$documento->fondo = $fondo;
$documento->fondo_color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($datos['fondo_color'] ?? '')) ? $datos['fondo_color'] : '#ffffff';
$documento->fondo_config = json_encode(['paso' => $paso, 'color' => $linea]);
$documento->guardar();
$documento->tocar();

$fresco = \Model\EditorDocumento::find((int) $documento->id);

json_respuesta([
    'success' => true,
    'version' => $fresco ? (string) $fresco->actualizado : '',
    'laminas' => $respuestaLaminas,
]);

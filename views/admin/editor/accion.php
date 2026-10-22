<?php
/** views/admin/editor/accion.php — Duplicar o eliminar un documento. */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/editor');
}

$accion = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$documento = \Model\EditorDocumento::find((int) ($_POST['id'] ?? 0));

if (!$documento) {
    redirigir('/admin/editor', 'error', 'El documento no existe o fue eliminado.');
}

if ($accion === 'duplicar') {
    $copia = new \Model\EditorDocumento([
        'nombre'      => mb_substr($documento->nombre, 0, 140) . ' (copia)',
        'ancho'       => $documento->ancho,
        'alto'        => $documento->alto,
        'orientacion' => $documento->orientacion,
        'fondo'       => $documento->fondo,
        'fondo_color' => $documento->fondo_color,
        'fondo_imagen' => $documento->fondo_imagen,
        'fondo_config' => $documento->fondo_config,
        'creado_por'  => (int) ($_SESSION['id'] ?? 0),
    ]);
    $copia->id = $copia->guardar()['id'];

    foreach ($documento->laminas() as $l) {
        $nueva = new \Model\EditorLamina([
            'documento_id' => (int) $copia->id,
            'orden'        => (int) $l->orden,
            'nombre'       => $l->nombre,
            'marcas_json'  => $l->marcas_json,
            'marcas_png'   => null,
        ]);
        $nueva->guardar();
    }

    redirigir('/admin/editor/abrir?id=' . (int) $copia->id, 'exito', 'Documento duplicado.');
}

if ($accion === 'eliminar') {
    $documento->eliminar();
    redirigir('/admin/editor', 'exito', 'Documento eliminado.');
}

redirigir('/admin/editor');

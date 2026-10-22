<?php
/**
 * views/admin/redactor/api/guardar.php — Guarda el documento (AJAX).
 *
 * Limpia el HTML, controla la versión y conserva un historial automático
 * cada 10 minutos. Las imágenes que dejan de usarse se borran del disco.
 */

soloAdmin();

use Classes\Redactor;
use Classes\RedactorFirmas;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_respuesta(['success' => false, 'error' => 'Método no permitido'], 405);
}

$documento = \Model\RedactorDocumento::find((int) ($_POST['id'] ?? 0));
if (!$documento) {
    json_respuesta(['success' => false, 'error' => 'El documento no existe o fue eliminado'], 404);
}

$html = (string) ($_POST['contenido'] ?? '');
if (strlen($html) > 5_000_000) {
    json_respuesta(['success' => false, 'error' => 'El documento es demasiado grande'], 422);
}

$version = (string) ($_POST['version'] ?? '');
if ($version !== '' && empty($_POST['forzar']) && $version !== (string) $documento->actualizado) {
    json_respuesta([
        'success'   => false,
        'conflicto' => true,
        'error'     => 'Este documento se guardó desde otra sesión después de que lo abrieras.',
    ], 409);
}

$nuevo = new \Model\RedactorDocumento([
    'id'               => $documento->id,
    'creado_por'       => $documento->creado_por,
    'nombre'           => $_POST['nombre'] ?? $documento->nombre,
    'fecha'            => $_POST['fecha'] ?? $documento->fecha,
    'departamento'     => $_POST['departamento'] ?? $documento->departamento,
    'idioma'           => $_POST['idioma'] ?? $documento->idioma,
    'papel'            => $_POST['papel'] ?? $documento->papel,
    'orientacion'      => $_POST['orientacion'] ?? $documento->orientacion,
    'margen_sup'       => $_POST['margen_sup'] ?? 2.54,
    'margen_inf'       => $_POST['margen_inf'] ?? 2.54,
    'margen_izq'       => $_POST['margen_izq'] ?? 2.54,
    'margen_der'       => $_POST['margen_der'] ?? 2.54,
    'numerar'          => !empty($_POST['numerar']) ? 1 : 0,
    'encabezado'       => !empty($_POST['encabezado']) ? 1 : 0,
    'rastrear'         => !empty($_POST['rastrear']) ? 1 : 0,
    'marca_agua'       => $_POST['marca_agua'] ?? $documento->marca_agua,
    'encabezado_texto' => $_POST['encabezado_texto'] ?? $documento->encabezado_texto,
    'pie_texto'        => $_POST['pie_texto'] ?? $documento->pie_texto,
    'contenido'        => Redactor::limpiar($html),
    'comentarios'      => Redactor::limpiarComentarios((string) ($_POST['comentarios'] ?? '[]')),
]);

if ($alertas = $nuevo->validar()) {
    json_respuesta(['success' => false, 'error' => $alertas['error'][0]], 422);
}

$viejas = \Model\RedactorDocumento::imagenesDe($documento->contenido);

if (trim((string) $documento->contenido) !== '' && $documento->contenido !== $nuevo->contenido) {
    $ultima = \Model\RedactorDocumento::ultimaVersion((int) $documento->id);
    if ($ultima === null || time() - strtotime($ultima) > 600) {
        \Model\RedactorDocumento::guardarVersion((int) $documento->id, (string) $documento->contenido, (string) ($_SESSION['nombre'] ?? ''), 'Automática');
    }
}

$nuevo->guardar();

if (trim((string) ($_POST['version_etiqueta'] ?? '')) !== '') {
    \Model\RedactorDocumento::guardarVersion((int) $documento->id, (string) $nuevo->contenido, (string) ($_SESSION['nombre'] ?? ''), trim((string) $_POST['version_etiqueta']));
}

$sobran = array_diff($viejas, \Model\RedactorDocumento::imagenesDe($nuevo->contenido));
if ($sobran) {
    $documento->contenido = implode(' ', array_map(fn($n) => '/public/uploads/vplan/' . $n, $sobran));
    $documento->eliminarArchivos();
}

$fresco = \Model\RedactorDocumento::find((int) $documento->id);

json_respuesta([
    'success'   => true,
    'version'   => $fresco ? (string) $fresco->actualizado : '',
    'contenido' => $nuevo->contenido,
    'sellos'    => RedactorFirmas::sellos($nuevo),
]);

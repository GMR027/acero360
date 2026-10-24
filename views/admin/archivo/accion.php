<?php
/**
 * views/admin/archivo/accion.php — Acciones de la herramienta Archivo.
 *
 * Rutas que llegan por POST (con el _csrf que inyecta la aplicación):
 *   /admin/archivo/crear-carpeta      → crea una carpeta y entra en ella
 *   /admin/archivo/renombrar-carpeta  → cambia el nombre (JSON, es un <dialog>)
 *   /admin/archivo/eliminar-carpeta   → la borra; el contenido pasa al padre
 *   /admin/archivo/mover              → mueve un archivo de sitio (JSON)
 *   /admin/archivo/eliminar-archivo   → borra el registro y los bytes
 *
 * Al borrar una carpeta MySQL empuja su contenido a la raíz (carpeta_id NULL
 * / carpeta_padre_id NULL), así que NUNCA se pierde un archivo por esta vía.
 */

soloAdmin();

use Classes\ArchivoAlmacen;
use Model\Archivo;
use Model\ArchivoCarpeta;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/archivo');
}

$accion = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

$irACarpeta = static function (?int $id): string {
    return '/admin/archivo' . ($id ? '?carpeta=' . (int) $id : '');
};

/* ---------------------------------------------------- crear carpeta ----- */
if ($accion === 'crear-carpeta') {
    $padreId = (int) ($_POST['carpeta_padre_id'] ?? 0) ?: null;
    $nombre  = ArchivoCarpeta::limpiarNombre($_POST['nombre'] ?? '');

    $carpeta = new ArchivoCarpeta([
        'nombre'          => $nombre,
        'carpeta_padre_id' => $padreId,
        'creado_por'      => (int) ($_SESSION['id'] ?? 0),
    ]);
    $alertas = $carpeta->validar();
    if ($alertas) {
        redirigir($irACarpeta($padreId), 'error', implode(' · ', $alertas['error'] ?? ['No se pudo crear la carpeta.']));
    }
    if ($carpeta->nombreUsado($nombre, $padreId ?: 0)) {
        redirigir($irACarpeta($padreId), 'error', 'Ya existe una carpeta llamada «' . $nombre . '» aquí.');
    }

    // ActiveRecord::crear() devuelve el id pero no lo asigna al objeto.
    $resultado = $carpeta->guardar();
    $nuevoId = (int) ($resultado['id'] ?? 0);
    redirigir($irACarpeta($nuevoId ?: null), 'exito', 'Carpeta «' . $nombre . '» creada.');
}

/* ------------------------------------------------- renombrar carpeta ---- */
if ($accion === 'renombrar-carpeta') {
    $id     = (int) ($_POST['id'] ?? 0);
    $nombre = ArchivoCarpeta::limpiarNombre($_POST['nombre'] ?? '');

    $carpeta = ArchivoCarpeta::find($id);
    if (!$carpeta) {
        json_respuesta(['success' => false, 'error' => 'La carpeta ya no existe'], 404);
    }
    if ($nombre === '') {
        json_respuesta(['success' => false, 'error' => 'El nombre es obligatorio'], 422);
    }
    $padre = (int) ($carpeta->carpeta_padre_id ?? 0);
    if ($nombre !== $carpeta->nombre && $carpeta->nombreUsado($nombre, $padre)) {
        json_respuesta(['success' => false, 'error' => 'Ya existe una carpeta con ese nombre aquí'], 422);
    }

    $carpeta->nombre = $nombre;
    $carpeta->guardar();
    json_respuesta(['success' => true]);
}

/* ------------------------------------------------- eliminar carpeta ----- */
if ($accion === 'eliminar-carpeta') {
    $id = (int) ($_POST['id'] ?? 0);
    $carpeta = ArchivoCarpeta::find($id);
    if (!$carpeta) {
        redirigir('/admin/archivo', 'error', 'La carpeta ya no existe.');
    }

    $padre = $carpeta->carpeta_padre_id ? (int) $carpeta->carpeta_padre_id : null;
    $n = $carpeta->totalArchivos();
    $carpeta->eliminar();   // FK: el contenido pasa al padre (raíz si era de primer nivel)

    $detalle = $n > 0
        ? ' Se movieron ' . $n . ' archivo(s) a ' . ($padre ? 'la carpeta contenedora' : 'la raíz') . '.'
        : '';
    redirigir($irACarpeta($padre), 'exito', 'Carpeta «' . $carpeta->nombre . '» eliminada.' . $detalle);
}

/* --------------------------------------------------------- mover -------- */
if ($accion === 'mover') {
    $id        = (int) ($_POST['archivo_id'] ?? 0);
    $destinoId = (int) ($_POST['carpeta_id'] ?? 0) ?: null;

    $archivo = Archivo::find($id);
    if (!$archivo) {
        json_respuesta(['success' => false, 'error' => 'El archivo ya no existe'], 404);
    }
    if ($destinoId) {
        $destino = ArchivoCarpeta::find($destinoId);
        if (!$destino) {
            json_respuesta(['success' => false, 'error' => 'La carpeta de destino ya no existe'], 404);
        }
        if (count($destino->ruta()) > ArchivoCarpeta::PROFUNDIDAD_MAX) {
            json_respuesta(['success' => false, 'error' => 'La carpeta de destino está en la profundidad máxima'], 422);
        }
        // No meter un archivo dentro de sí mismo (imposible, pero no cuesta nada).
        if ($archivo->carpeta_id && (int) $archivo->carpeta_id === $destinoId) {
            json_respuesta(['success' => true]);   // ya está ahí
        }
    }

    // Los bytes viven en una carpeta física por carpeta virtual: si cambia de
    // sitio hay que moverlos también para no dejar huérfanos en disco.
    $rutaVieja = ArchivoAlmacen::rutaAbsoluta($archivo->archivo);
    if ($rutaVieja !== null) {
        $prueba = ArchivoAlmacen::guardar(
            ['tmp_name' => $rutaVieja, 'name' => $archivo->nombre],
            $archivo->extension() !== '' ? $archivo->extension() : 'bin',
            $archivo->familia(),
            $destinoId
        );
        if ($prueba === null) {
            json_respuesta(['success' => false, 'error' => implode(' · ', ArchivoAlmacen::errores())], 500);
        }
        $archivo->archivo = $prueba['archivo'];
    }

    $archivo->carpeta_id = $destinoId;
    $archivo->guardar();
    json_respuesta(['success' => true, 'carpeta' => $destinoId]);
}

/* -------------------------------------------------- eliminar archivo ---- */
if ($accion === 'eliminar-archivo') {
    $id = (int) ($_POST['id'] ?? 0);
    $archivo = Archivo::find($id);
    if (!$archivo) {
        redirigir('/admin/archivo', 'error', 'El archivo ya no existe.');
    }

    $carpeta = $archivo->carpeta_id ? (int) $archivo->carpeta_id : null;
    $nombre = $archivo->titulo();
    ArchivoAlmacen::borrar($archivo->archivo);
    $archivo->eliminar();

    redirigir($irACarpeta($carpeta), 'exito', '«' . $nombre . '» eliminado.');
}

redirigir('/admin/archivo');

<?php
/**
 * views/admin/archivo/descargar.php — Descarga un archivo de la herramienta Archivo.
 *
 * Sólo administradores con sesión. La ruta se resuelve con realpath dentro
 * del almacén (storage/archivo) y se envía siempre como adjunto, con el
 * nombre original que eligió el usuario.
 */

soloAdmin();

use Classes\ArchivoAlmacen;
use Model\Archivo;

$archivo = Archivo::find((int) ($_GET['id'] ?? 0));
if (!$archivo) {
    redirigir('/admin/archivo', 'error', 'El archivo ya no existe.');
}

$ruta = ArchivoAlmacen::rutaAbsoluta($archivo->archivo);
if ($ruta === null) {
    // El registro existe pero los bytes no: se retira para que no vuelva a fallar.
    $archivo->eliminar();
    redirigir('/admin/archivo', 'error', 'El archivo «' . $archivo->titulo() . '» ya no está en el servidor.');
}

ArchivoAlmacen::descargar($ruta, $archivo->titulo(), $archivo->mime);

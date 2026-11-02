<?php
/**
 * views/admin/archivo/ver.php — Vista previa de un archivo (solo administradores).
 *
 * Sirve PDF e imágenes inline para que la ventana modal pueda mostrarlos sin
 * descargarlos. La descarga sigue teniendo su propia ruta.
 */

soloAdmin();

use Classes\ArchivoAlmacen;
use Model\Archivo;

$archivo = Archivo::find((int) ($_GET['id'] ?? 0));
if (!$archivo) {
    paginaNoEncontrada();
}

$ruta  = ArchivoAlmacen::rutaAbsoluta($archivo->archivo);
$vista = $archivo->vista();
if ($ruta === null || $vista === null) {
    paginaNoEncontrada();
}

ArchivoAlmacen::ver($ruta, ArchivoAlmacen::mimeInline($archivo->extension()), $archivo->titulo(), $vista);

<?php
/**
 * views/admin/archivo/miniatura.php — Miniatura del listado (solo administradores).
 *
 * Genera y cachea la miniatura de imágenes y de la primera página de los PDF,
 * de modo que en la tabla se reconozca el archivo de un vistazo. El SVG se
 * sirve tal cual como imagen. Si no hay miniatura posible responde 404 y la
 * vista muestra el icono del tipo.
 */

soloAdmin();

use Classes\ArchivoAlmacen;
use Model\Archivo;

$archivo = Archivo::find((int) ($_GET['id'] ?? 0));
if (!$archivo || !$archivo->tieneMiniatura()) {
    paginaNoEncontrada();
}

$ruta = ArchivoAlmacen::rutaAbsoluta($archivo->archivo);
if ($ruta === null) {
    paginaNoEncontrada();
}

if ($archivo->extension() === 'svg') {
    ArchivoAlmacen::enviarMiniaturaSvg($ruta);
}

$miniatura = ArchivoAlmacen::miniatura($archivo->archivo, $archivo->extension());
if ($miniatura === null) {
    paginaNoEncontrada();
}

ArchivoAlmacen::enviarMiniatura($miniatura);

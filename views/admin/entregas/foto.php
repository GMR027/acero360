<?php
/**
 * views/admin/entregas/foto.php — Foto del acta de entrega con marca de agua.
 *
 * La foto original de public/image NUNCA se modifica: aquí se dibuja al vuelo
 * el logo del negocio emisor (arriba a la izquierda) y la fecha, hora y
 * ubicación de la entrega (abajo a la izquierda), con sombra para resaltar.
 *
 * La imagen ampliada del seguimiento apunta a este endpoint; las miniaturas
 * siguen mostrando el archivo original. Las fotos anteriores también salen
 * marcadas porque la fecha y la ubicación se completan con los datos de la
 * entrega cuando la foto ya no conserva EXIF.
 *
 * Parámetros:
 *   f → nombre del archivo (sólo se sirven fotos registradas en entrega_fotos)
 *   w → ancho máximo de salida (400–1600 px, por omisión 1600)
 */

soloAdmin();

$archivo = (string) ($_GET['f'] ?? '');
$foto    = \App\Entregas::fotoDeEntrega($archivo);

if (!$foto) {
    paginaNoEncontrada();
}

$ancho = (int) ($_GET['w'] ?? \App\FotosEntrega::ANCHO_MAX);
$ancho = max(400, min(\App\FotosEntrega::ANCHO_MAX, $ancho));

$marcada = \App\FotosEntrega::marcada($foto, $ancho);

if ($marcada === null) {
    // Si el dibujo fallara, la ventana no debe quedar rota: se sirve el original.
    $ruta = \App\Imagenes::CARPETA . '/' . basename((string) $foto['archivo']);
    $medidas = is_file($ruta) ? @getimagesize($ruta) : false;
    $tipo = is_array($medidas) ? (string) ($medidas['mime'] ?? '') : '';

    if ($tipo === '') {
        paginaNoEncontrada();
    }

    $contenido = @file_get_contents($ruta);
    if ($contenido === false) {
        paginaNoEncontrada();
    }

    $marcada = ['contenido' => $contenido, 'mime' => $tipo];
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $marcada['mime']);
header('Content-Length: ' . strlen($marcada['contenido']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=86400');

echo $marcada['contenido'];
exit;

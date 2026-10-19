<?php

/**
 * scripts/imagenes_prueba.php — Genera las imágenes de los productos de prueba.
 *
 * Los productos de mysql/datos_prueba.sql se insertan sin imagen; este
 * script les dibuja una ficha cuadrada 1000×1000 y su miniatura 300×300,
 * con el mismo formato (JPG) y la misma carpeta que usa la subida real
 * (public/image). Así el catálogo, el listado y el cotizador se ven
 * completos sin depender de archivos externos.
 *
 * USO:  php scripts/imagenes_prueba.php
 *
 * Es idempotente: sólo dibuja los productos cuya columna `imagen` está
 * vacía, y reutiliza el archivo si ya existe.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';

/** Carpeta pública donde vive el resto de las imágenes. */
$carpeta = __DIR__ . '/../public/image';

/** Fuente TTF para el texto (SKU y nombre corto del producto). */
$fuentes = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
];
$fuente = '';
foreach ($fuentes as $candidata) {
    if (is_file($candidata)) {
        $fuente = $candidata;
        break;
    }
}

/**
 * Paleta por negocio: así se distingue de un vistazo a qué empresa
 * pertenece cada producto en el catálogo de prueba.
 *
 * @return array{0: int, 1: int, 2: int} componentes RGB.
 */
function colorDeEmpresa(int $empresaId): array
{
    $paleta = [
        ['90', '146', '195'],   // Soluciones en Acero — azul acero
        ['180', '100', '36'],   // Canastillas de la Baja — óxido
        ['22', '163', '74'],    // Ferretera del Norte — verde
        ['77', '76', '83'],     // Grupo Aceros del Bajío — gris
        ['70', '121', '166'],   // Canastillas Industriales MX
        ['120', '90', '60'],    // Lámina y Tubos del Sur
    ];

    $rgb = $paleta[($empresaId - 1) % count($paleta)];
    return [(int) $rgb[0], (int) $rgb[1], (int) $rgb[2]];
}

/** Dibuja la ficha cuadrada de un producto (1000×1000, fondo sólido + rejilla). */
function dibujarFicha(string $sku, string $nombre, array $rgb, string $fuente): GDImage
{
    $lado = 1000;
    $lienzo = imagecreatetruecolor($lado, $lado);

    [$r, $g, $b] = $rgb;
    $fondo      = imagecolorallocate($lienzo, $r, $g, $b);
    $fondoClaro = imagecolorallocate($lienzo, min(255, $r + 40), min(255, $g + 40), min(255, $b + 40));
    $blanco     = imagecolorallocate($lienzo, 255, 255, 255);
    $sombra     = imagecolorallocate($lienzo, (int) ($r * 0.7), (int) ($g * 0.7), (int) ($b * 0.7));

    imagefill($lienzo, 0, 0, $fondo);

    // Marco interior.
    imagefilledrectangle($lienzo, 40, 40, $lado - 40, $lado - 40, $fondoClaro);
    imagerectangle($lienzo, 40, 40, $lado - 40, $lado - 40, $blanco);

    // Rejilla que evoca una canastilla / estructura metálica.
    for ($i = 1; $i <= 3; $i++) {
        $x = 40 + (int) (($lado - 80) * $i / 4);
        imageline($lienzo, $x, 200, $x, 700, $sombra);
        $y = 200 + (int) (500 * $i / 4);
        imageline($lienzo, 200, $y, 800, $y, $sombra);
    }
    imagefilledrectangle($lienzo, 200, 200, 800, 700, $fondoClaro);
    imagerectangle($lienzo, 200, 200, 800, 700, $blanco);
    imageline($lienzo, 400, 200, 400, 700, $blanco);
    imageline($lienzo, 600, 200, 600, 700, $blanco);
    imageline($lienzo, 200, 366, 800, 366, $blanco);
    imageline($lienzo, 200, 533, 800, 533, $blanco);

    // SKU centrado en la banda inferior.
    if ($fuente !== '' && $sku !== '') {
        $ancho = imagettfbbox(48, 0, $fuente, $sku);
        $x = (int) (500 - ($ancho[2] - $ancho[0]) / 2);
        imagettftext($lienzo, 48, 0, $x, 850, $blanco, $fuente, $sku);

        $linea = mb_substr($nombre, 0, 26);
        $ancho = imagettfbbox(30, 0, $fuente, $linea);
        $x = (int) (500 - ($ancho[2] - $ancho[0]) / 2);
        imagettftext($lienzo, 30, 0, $x, 920, $blanco, $fuente, $linea);
    }

    return $lienzo;
}

if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
    fwrite(STDERR, "No se pudo crear la carpeta $carpeta\n");
    exit(1);
}

$db = database();
$resultado = $db->query(
    'SELECT id, empresa_id, nombre, sku, imagen
       FROM productos
      WHERE imagen IS NULL OR imagen = ""
      ORDER BY id'
);

$generadas = 0;
$fallidas  = 0;

while ($producto = $resultado->fetch_assoc()) {
    $sku = (string) ($producto['sku'] ?: ('PR-' . $producto['id']));
    $clave = strtolower(preg_replace('/[^a-z0-9]/i', '', $sku));
    $nombre = 'producto-' . $clave . '.jpg';
    $miniatura = 'producto-' . $clave . '_mini.jpg';
    $ruta = $carpeta . '/' . $nombre;
    $rutaMini = $carpeta . '/' . $miniatura;

    if (!is_file($ruta)) {
        $rgb = colorDeEmpresa((int) $producto['empresa_id']);
        $ficha = dibujarFicha($sku, (string) $producto['nombre'], $rgb, $fuente);
        imagejpeg($ficha, $ruta, 88);
        imagedestroy($ficha);
    }

    if (!is_file($rutaMini) && is_file($ruta)) {
        $origen = imagecreatefromjpeg($ruta);
        $mini = imagecreatetruecolor(300, 300);
        imagecopyresampled($mini, $origen, 0, 0, 0, 0, 300, 300,
            imagesx($origen), imagesy($origen));
        imagejpeg($mini, $rutaMini, 88);
        imagedestroy($mini);
        imagedestroy($origen);
    }

    if (!is_file($ruta) || !is_file($rutaMini)) {
        $fallidas++;
        fwrite(STDERR, "No se pudo generar la imagen de {$producto['nombre']}\n");
        continue;
    }

    $sentencia = $db->prepare('UPDATE productos SET imagen = ? WHERE id = ?');
    $id = (int) $producto['id'];
    $sentencia->bind_param('si', $nombre, $id);

    if ($sentencia->execute()) {
        $generadas++;
    } else {
        $fallidas++;
        fwrite(STDERR, "No se pudo guardar la referencia para {$producto['nombre']}\n");
    }
    $sentencia->close();
}

echo "Imágenes asignadas: $generadas\n";
echo "Fallos: $fallidas\n";
exit($fallidas > 0 ? 1 : 0);

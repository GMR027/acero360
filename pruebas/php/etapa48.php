<?php
/**
 * pruebas/php/etapa48.php — Fotos de entrega: 15 MB, compresión y ubicación.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/etapa48.php
 *
 * Comprueba:
 *   · el tope de 15 MB por foto y la compresión del servidor;
 *   · la lectura/normalización de metadatos del navegador;
 *   · la ubicación manual (enlace de Google Maps, coordenadas o dirección)
 *     y su prioridad frente al GPS y la dirección del cliente.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';
@session_start();

use App\FotosEntrega;
use App\Imagenes;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};

echo "1. Tope y compresión del servidor\n";
$prueba('el tope de entrega es 15 MB', Imagenes::TAMANO_MAXIMO_ENTREGA === 15 * 1024 * 1024, (string) Imagenes::TAMANO_MAXIMO_ENTREGA);
$prueba('validarArchivo acepta 10 MB con el tope de entrega',
    Imagenes::validarArchivo([
        'name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/no-importa',
        'error' => UPLOAD_ERR_OK, 'size' => 10 * 1024 * 1024,
    ], Imagenes::TAMANO_MAXIMO_ENTREGA) === '');
$prueba('validarArchivo rechaza 16 MB',
    Imagenes::validarArchivo([
        'name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/no-importa',
        'error' => UPLOAD_ERR_OK, 'size' => 16 * 1024 * 1024,
    ], Imagenes::TAMANO_MAXIMO_ENTREGA) !== '');

$temporal = tempnam(sys_get_temp_dir(), 'foto48-');
$imagen = imagecreatetruecolor(3200, 2400);
for ($y = 0; $y < 2400; $y += 12) {
    for ($x = 0; $x < 3200; $x += 12) {
        $color = imagecolorallocate($imagen, ($x * 7) % 256, ($y * 11) % 256, (($x + $y) * 3) % 256);
        imagefilledrectangle($imagen, $x, $y, $x + 12, $y + 12, $color);
    }
}
imagejpeg($imagen, $temporal, 95);
imagedestroy($imagen);
$pesoOriginal = filesize($temporal);
// Se agregan bytes al final para superar los 6 MB del tope de producto y
// comprobar que el tope de entrega sí los admite.
file_put_contents($temporal, str_repeat("\0", 8 * 1024 * 1024), FILE_APPEND);
$pesoEntrada = filesize($temporal);

$nombre = Imagenes::subir([
    'name' => 'acta-grande.jpg', 'type' => 'image/jpeg', 'tmp_name' => $temporal,
    'error' => UPLOAD_ERR_OK, 'size' => $pesoEntrada,
], 'prueba48', 'informe', Imagenes::TAMANO_MAXIMO_ENTREGA);
$rutaSalida = $nombre ? Imagenes::CARPETA . '/' . $nombre : '';
$prueba('la foto de 10 MB se procesa y se guarda', is_string($nombre) && is_file($rutaSalida), (string) $nombre);
$prueba('el archivo guardado es JPEG', $nombre !== null && str_ends_with((string) $nombre, '.jpg'));
$prueba('la foto guardada pesa menos de 2 MB', is_file($rutaSalida) && filesize($rutaSalida) <= 2 * 1024 * 1024, is_file($rutaSalida) ? (string) filesize($rutaSalida) : 'no existe');
$prueba('la foto guardada pesa mucho menos que la original', is_file($rutaSalida) && filesize($rutaSalida) < $pesoOriginal,
    is_file($rutaSalida) ? filesize($rutaSalida) . ' vs ' . $pesoOriginal : 'no existe');

$medidas = is_file($rutaSalida) ? getimagesize($rutaSalida) : false;
$prueba('el lado mayor queda en 1600 px o menos', $medidas && max($medidas[0], $medidas[1]) <= 1600,
    $medidas ? $medidas[0] . 'x' . $medidas[1] : 'sin medidas');

if ($nombre) {
    Imagenes::eliminar($nombre);
}
@unlink($temporal);

echo "2. Metadatos del navegador\n";
$cliente = FotosEntrega::metadatosDeFormulario([
    'tomada_en' => '2026-11-07 10:30:00', 'latitud' => 24.1426667, 'longitud' => -110.31275,
]);
$prueba('fecha y GPS del navegador se normalizan',
    $cliente['tomada_en'] === '2026-11-07 10:30:00' && abs($cliente['latitud'] - 24.1426667) < 0.0000001 && abs($cliente['longitud'] + 110.31275) < 0.0000001);
$prueba('coordenadas fuera de rango se descartan',
    FotosEntrega::metadatosDeFormulario(['latitud' => 120, 'longitud' => -300])['latitud'] === null);
$servidor = ['tomada_en' => '2026-11-06 09:00:00', 'latitud' => null, 'longitud' => null];
$combinado = FotosEntrega::combinarMetadatos($servidor, $cliente);
$prueba('el EXIF del servidor manda y el navegador completa el GPS',
    $combinado['tomada_en'] === '2026-11-06 09:00:00' && $combinado['latitud'] !== null);

echo "3. Ubicación manual\n";
$enlace = 'https://www.google.com/maps/@24.1426667,-110.3127500,17z';
$norm = FotosEntrega::normalizarUbicacion($enlace);
$prueba('acepta un enlace de Google Maps', $norm['error'] === '' && $norm['valor'] === $enlace, $norm['error']);
$norm = FotosEntrega::normalizarUbicacion('maps.app.goo.gl/abc123');
$prueba('completa el https de un enlace corto', $norm['error'] === '' && $norm['valor'] === 'https://maps.app.goo.gl/abc123', $norm['error']);
$norm = FotosEntrega::normalizarUbicacion('24.1426667, -110.3127500');
$prueba('normaliza coordenadas escritas a mano', $norm['valor'] === '24.1426667, -110.3127500', $norm['valor']);
$norm = FotosEntrega::normalizarUbicacion('https://ejemplo.com/mapa');
$prueba('rechaza un enlace que no es de Google Maps', $norm['error'] !== '', $norm['error']);
$norm = FotosEntrega::normalizarUbicacion('https://google.evil.com/maps');
$prueba('rechaza un dominio que sólo imita a Google', $norm['error'] !== '', $norm['error']);
$norm = FotosEntrega::normalizarUbicacion(str_repeat('a', 501));
$prueba('rechaza una ubicación de más de 500 caracteres', $norm['error'] !== '');

$foto = ['latitud' => 24.1426667, 'longitud' => -110.3127500];
$mapas = FotosEntrega::urlsMapa($foto, 'La Paz', 'https://maps.app.goo.gl/corto');
$prueba('el GPS de la foto tiene prioridad sobre la ubicación manual',
    str_contains($mapas['google'] ?? '', '24.1426667%2C-110.3127500'));
$mapas = FotosEntrega::urlsMapa(['latitud' => null, 'longitud' => null], 'La Paz', $enlace);
$prueba('sin GPS usa las coordenadas del enlace manual',
    str_contains($mapas['google'] ?? '', '24.1426667%2C-110.3127500') && isset($mapas['apple']),
    json_encode($mapas));
$mapas = FotosEntrega::urlsMapa(['latitud' => null, 'longitud' => null], '', 'https://maps.app.goo.gl/corto');
$prueba('un enlace corto sólo genera Google Maps', isset($mapas['google']) && !isset($mapas['apple']), json_encode($mapas));
$mapas = FotosEntrega::urlsMapa(['latitud' => null, 'longitud' => null], '', 'La Paz, BCS');
$prueba('una dirección manual genera Google y Apple',
    isset($mapas['google']) && isset($mapas['apple']));
$mapas = FotosEntrega::urlsMapa(['latitud' => null, 'longitud' => null], 'La Paz, BCS', '');
$prueba('sin manual cae a la dirección del cliente',
    ($mapas['google'] ?? '') === 'https://www.google.com/maps/search/?api=1&query=La%20Paz%2C%20BCS');

echo "\n" . ($fallos === 0 ? "Resultado: TODO OK\n" : "Resultado: $fallos fallo(s)\n");
exit($fallos === 0 ? 0 : 1);

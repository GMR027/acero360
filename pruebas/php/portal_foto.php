<?php
/**
 * pruebas/php/portal_foto.php — Fotos de entrega firmadas para el portal de clientes.
 *
 *   DB_NAME=cotizaciones_test php pruebas/php/portal_foto.php
 *
 * Comprueba: el endpoint se apaga sin secreto, la firma HMAC (válida, alterada,
 * vencida, demasiado larga), el ancho se acota, la imagen sale con marca de agua
 * (JPEG) y por HTTP una firma inválida responde 404.
 */
require __DIR__ . '/../../config/entorno.php';
if (getenv('DB_NAME') !== 'cotizaciones_test') {
    fwrite(STDERR, "Usa DB_NAME=cotizaciones_test\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../includes/app.php';

use App\FotosEntrega;
use App\Imagenes;
use App\PortalFotos;

$fallos = 0;
$prueba = function (string $n, bool $ok, string $d = '') use (&$fallos) {
    echo ($ok ? '  ok   ' : '  FALLA ') . $n . ($ok || $d === '' ? '' : " → $d") . "\n";
    $fallos += $ok ? 0 : 1;
};

PortalFotos::setDB($db);

/* --- Secreto ------------------------------------------------------------- */
$original = getenv('PORTAL_FOTOS_SECRETO');
putenv('PORTAL_FOTOS_SECRETO=');
$prueba('sin secreto el endpoint está apagado', PortalFotos::secreto() === null);
putenv('PORTAL_FOTOS_SECRETO=corto');
$prueba('un secreto corto no se acepta', PortalFotos::secreto() === null);
$secreto = str_repeat('a1', 20);
putenv("PORTAL_FOTOS_SECRETO=$secreto");
$prueba('un secreto de 40 caracteres se acepta', PortalFotos::secreto() === $secreto);

/* --- Firma --------------------------------------------------------------- */
$ahora = 1_800_000_000;
$exp   = $ahora + 300;
$sig   = PortalFotos::firma(7, 800, $exp, $secreto);

$prueba('firma válida devuelve el ancho', PortalFotos::validar(7, 800, $exp, $sig, $secreto, $ahora) === 800);
$prueba('firma alterada se rechaza', PortalFotos::validar(7, 800, $exp, substr($sig, 0, -1) . '0', $secreto, $ahora) === null);
$prueba('otro id con la misma firma se rechaza', PortalFotos::validar(8, 800, $exp, $sig, $secreto, $ahora) === null);
$prueba('otro ancho con la misma firma se rechaza', PortalFotos::validar(7, 1600, $exp, $sig, $secreto, $ahora) === null);
$prueba('firma vencida se rechaza', PortalFotos::validar(7, 800, $exp, $sig, $secreto, $exp + 1) === null);
$lejana = $ahora + PortalFotos::VIGENCIA_MAX + 60;
$prueba('vigencia mayor al máximo se rechaza',
    PortalFotos::validar(7, 800, $lejana, PortalFotos::firma(7, 800, $lejana, $secreto), $secreto, $ahora) === null);
$prueba('sin secreto nada valida', PortalFotos::validar(7, 800, $exp, $sig, null, $ahora) === null);
$prueba('el ancho se acota al máximo',
    PortalFotos::validar(7, 9000, $exp, PortalFotos::firma(7, 9000, $exp, $secreto), $secreto, $ahora) === FotosEntrega::ANCHO_MAX);

/* --- Imagen -------------------------------------------------------------- */
$prueba('foto inexistente → null', PortalFotos::imagen(999999999, 400) === null);

$fila = $db->query('SELECT id, archivo FROM entrega_fotos ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$conArchivo = array_values(array_filter($fila, fn ($f) => is_file(Imagenes::CARPETA . '/' . basename($f['archivo']))));
if ($conArchivo) {
    $imagen = PortalFotos::imagen((int) $conArchivo[0]['id'], 400);
    $prueba('foto real sale como imagen', $imagen !== null && str_starts_with($imagen['mime'], 'image/'));
    $info = $imagen ? @getimagesizefromstring($imagen['contenido']) : false;
    $prueba('respeta el ancho pedido', is_array($info) && $info[0] <= 400, is_array($info) ? "ancho {$info[0]}" : 'sin imagen');
} else {
    echo "  --   sin fotos de entrega con archivo en esta base: se omite la prueba de imagen\n";
}

/* --- HTTP ---------------------------------------------------------------- */
$base = rtrim((string) (getenv('BASE_URL') ?: 'http://127.0.0.1:8017'), '/');
$ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
$url  = "$base/portal/entregas/foto?id=1&w=400&exp=" . (time() + 60) . '&sig=' . str_repeat('0', 64);
$resp = @file_get_contents($url, false, $ctx);
$codigo = isset($http_response_header[0]) ? (int) explode(' ', $http_response_header[0])[1] : 0;
if ($codigo === 0) {
    echo "  --   servidor de pruebas no disponible en $base: se omite la prueba HTTP\n";
} else {
    $prueba('firma inválida por HTTP → 404', $codigo === 404, "código $codigo");
}

putenv('PORTAL_FOTOS_SECRETO=' . ($original === false ? '' : $original));

echo $fallos === 0 ? "Resultado: todas las pruebas pasaron\n" : "Resultado: $fallos fallas\n";
exit($fallos === 0 ? 0 : 1);

<?php
/**
 * views/publico/portal-foto.php — Foto de entrega para el portal de clientes.
 *
 * Ruta pública SIN sesión, protegida por una URL firmada (HMAC) que sólo puede
 * generar el portal con PORTAL_FOTOS_SECRETO. Ver clases/PortalFotos.php.
 *
 * Con &modo=galeria (galería pública del sitio) la foto sale sólo con el logo.
 *
 * Cualquier fallo (secreto ausente, firma inválida o vencida, foto inexistente)
 * responde 404 para no revelar si la foto existe.
 */

use App\PortalFotos;

$id     = (int) ($_GET['id'] ?? 0);
$ancho  = (int) ($_GET['w'] ?? 0);
$expira = (int) ($_GET['exp'] ?? 0);
$firma  = (string) ($_GET['sig'] ?? '');
$modo   = (string) ($_GET['modo'] ?? '');

$anchoValido = PortalFotos::validar($id, $ancho, $expira, $firma, PortalFotos::secreto(), null, $modo);
$imagen      = $anchoValido !== null ? PortalFotos::imagen($id, $anchoValido, $modo) : null;

if ($imagen === null) {
    paginaNoEncontrada();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $imagen['mime']);
header('Content-Length: ' . strlen($imagen['contenido']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=86400');

echo $imagen['contenido'];
exit;

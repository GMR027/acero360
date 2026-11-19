<?php
/** views/admin/distribuidores/publicar.php — Publica u oculta en el sitio desde el listado. Sólo POST. */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

// Vuelve al listado con los mismos filtros (sólo rutas de esta sección).
$volver = (string) ($_POST['volver'] ?? '');
if (!preg_match('#^/admin/distribuidores(\?[^\s]*)?$#', $volver) || str_contains($volver, '//')) {
    $volver = '/admin/distribuidores';
}

$distribuidor = \App\Distribuidores::buscarID((int) ($_POST['id'] ?? 0));
if (!$distribuidor) {
    redirigir($volver, 'error', 'El distribuidor seleccionado ya no existe.');
}

$publicar = !empty($_POST['publicado']);
if ($distribuidor->cambiarPublicado($publicar)) {
    redirigir($volver, 'exito', '«' . $distribuidor->nombre . '» ' . ($publicar ? 'ya se muestra en el sitio.' : 'ya no se muestra en el sitio.'));
}
redirigir($volver, 'error', 'No fue posible cambiarlo. Intenta de nuevo.');

<?php
/**
 * views/admin/ajustes-tema.php — Guarda la apariencia elegida (tema claro | oscuro | auto y color de
 * acento) para quien la cambia. Lo llama app.js al elegir una opción en Ajustes → Apariencia; responde
 * JSON con un token nuevo para el siguiente cambio (cada token sirve una sola vez). Las cookies las pone
 * el navegador; esto las guarda en el usuario para recordarlas también en otros equipos.
 */

soloAdmin();

header('Content-Type: application/json; charset=utf-8');

$usuarioId = (int) ($_SESSION['id'] ?? 0);
$tema = (string) ($_POST['tema'] ?? '');
$color = (string) ($_POST['color'] ?? '');

if (!consumirToken($_POST['token'] ?? null)
    || ($tema !== '' && !in_array($tema, TEMAS_VALIDOS, true))
    || ($color !== '' && !isset(COLORES_ACENTO[$color]))
    || ($tema === '' && $color === '')) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'token' => tokenFormulario(), 'mensaje' => 'No se pudo guardar la preferencia; recarga la página.']);
    exit;
}

if ($tema !== '' && \App\Configuracion::obtener('tema_usuario_' . $usuarioId, 'auto') !== $tema) {
    \App\Configuracion::guardar('tema_usuario_' . $usuarioId, $tema, $usuarioId);
}
if ($color !== '' && \App\Configuracion::obtener('color_usuario_' . $usuarioId, 'azul') !== $color) {
    \App\Configuracion::guardar('color_usuario_' . $usuarioId, $color, $usuarioId);
}

echo json_encode(['ok' => true, 'token' => tokenFormulario(), 'tema' => $tema, 'color' => $color]);
exit;

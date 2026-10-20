<?php
/**
 * views/admin/ajustes-tema.php — Guarda el tema visual elegido (claro | oscuro | auto) para quien lo cambia.
 * Lo llama app.js al elegir una opción en Ajustes → Apariencia; responde JSON con un token nuevo para el
 * siguiente cambio (cada token sirve una sola vez). La cookie la pone el navegador; esto lo hace
 * disponible también en otros equipos.
 */

soloAdmin();

header('Content-Type: application/json; charset=utf-8');

$tema = (string) ($_POST['tema'] ?? '');
if (!consumirToken($_POST['token'] ?? null) || !in_array($tema, TEMAS_VALIDOS, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'token' => tokenFormulario(), 'mensaje' => 'No se pudo guardar la preferencia; recarga la página.']);
    exit;
}

$usuarioId = (int) ($_SESSION['id'] ?? 0);
if (\App\Configuracion::obtener('tema_usuario_' . $usuarioId, 'auto') !== $tema) {
    \App\Configuracion::guardar('tema_usuario_' . $usuarioId, $tema, $usuarioId);
}

echo json_encode(['ok' => true, 'token' => tokenFormulario(), 'tema' => $tema]);
exit;

<?php
/**
 * views/publico/entregas/descargar.php — GET /app/entregas/descargar
 *
 * Entrega el APK vigente de «Acero Entregas» sin pedir sesión: el teléfono lo
 * baja con su navegador. El APK no contiene datos ni secretos de la plataforma.
 */

$vigente = \App\AppAndroid::vigente('entregas');
if (!$vigente) {
    paginaNoEncontrada();
    exit;
}

sesionActiva();
\App\AppAndroid::descargar($vigente, isset($_SESSION['id']) ? (int) $_SESSION['id'] : null);

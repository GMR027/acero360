<?php
/**
 * views/publico/android/descargar.php — GET /app/android/descargar
 * Entrega el APK VIGENTE sin pedir sesión: el teléfono lo baja con su navegador, que no comparte
 * la sesión de la app. El APK no lleva datos ni secretos. Las versiones anteriores solo las baja
 * un administrador desde /admin/android.
 */

$vigente = \App\AppAndroid::vigente();
if (!$vigente) {
    paginaNoEncontrada();
    exit;
}

sesionActiva();
\App\AppAndroid::descargar($vigente, isset($_SESSION['id']) ? (int) $_SESSION['id'] : null);

<?php
/**
 * views/admin/android/descargar.php — descarga de cualquier APK publicado (solo administradores).
 */

soloAdmin();

$paquete = \App\AppAndroid::buscarID((int) ($_GET['id'] ?? 0));
if (!$paquete) {
    paginaNoEncontrada();
    exit;
}
\App\AppAndroid::descargar($paquete, (int) ($_SESSION['id'] ?? 0));

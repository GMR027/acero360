<?php
/**
 * views/admin/android/retirar.php — retira una versión defectuosa del APK (solo POST, con motivo).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

if (\App\AppAndroid::retirar((int) ($_POST['id'] ?? 0), (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
    redirigir('/admin/android', 'exito', 'Versión retirada. Queda en el registro con su motivo.');
}

redirigir('/admin/android', 'error', \App\AppAndroid::getErrores()[0] ?? 'No fue posible retirar la versión.');

<?php
/**
 * views/admin/escritorio/retirar.php — retira una versión defectuosa (solo POST, con motivo).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

if (\App\PaquetesEscritorio::retirar((int) ($_POST['id'] ?? 0), (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
    redirigir('/admin/escritorio', 'exito', 'Versión retirada. Queda en el registro con su motivo.');
}

redirigir('/admin/escritorio', 'error', \App\PaquetesEscritorio::getErrores()[0] ?? 'No fue posible retirar la versión.');

<?php
/**
 * views/admin/escritorio/descargar.php — descarga del .deb (solo administradores; verifica la suma SHA-256).
 */

soloAdmin();

\App\PaquetesEscritorio::descargar((int) ($_GET['id'] ?? 0), (int) ($_SESSION['id'] ?? 0));

<?php
/**
 * views/admin/contabilidad/documentos/ver.php — Vista previa controlada (solo administradores).
 * Sirve PDF e imágenes inline y el XML como texto plano; la ventana modal decide cómo mostrarlo.
 */

soloAdmin();

\App\Archivos::ver((int) ($_GET['id'] ?? 0));

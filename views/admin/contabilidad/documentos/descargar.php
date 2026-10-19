<?php
/**
 * views/admin/contabilidad/documentos/descargar.php — Descarga controlada (solo administradores).
 * Siempre como adjunto, con tipo fijo y sin que el navegador interprete el contenido.
 */

soloAdmin();

\App\Archivos::descargar((int) ($_GET['id'] ?? 0));

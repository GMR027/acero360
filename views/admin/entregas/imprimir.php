<?php
/**
 * views/admin/entregas/imprimir.php — Hoja de preparación o entrega lista para imprimir (solo lectura).
 */

soloAdmin();

\App\Documentos::imprimirEntrega(
    (int) ($_GET['id'] ?? 0),
    (string) ($_GET['tipo'] ?? 'preparacion'),
    array_map('intval', (array) ($_GET['ventas'] ?? []))
);

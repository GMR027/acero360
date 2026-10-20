<?php
/**
 * views/admin/entregas/pdf.php — Lista de preparación o PDF de entrega (solo lectura).
 * Acepta una vista guardada (id) o una selección sin guardar (ventas[]).
 */

soloAdmin();

\App\Documentos::descargarEntrega(
    (int) ($_GET['id'] ?? 0),
    (string) ($_GET['tipo'] ?? 'preparacion'),
    array_map('intval', (array) ($_GET['ventas'] ?? []))
);

<?php
/**
 * views/admin/entregas/pdf.php — Lista de preparación o PDF de entrega (solo lectura).
 */

soloAdmin();

\App\Documentos::descargarEntrega((int) ($_GET['id'] ?? 0), (string) ($_GET['tipo'] ?? 'preparacion'));

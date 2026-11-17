<?php
/** views/admin/pedidos/pdf.php — Confirmación de pedido en PDF (mismo diseño que la cotización). */

soloAdmin();

$pedido = \App\Pedidos::buscarID((int) ($_GET['id'] ?? 0));
if (!$pedido) {
    paginaNoEncontrada();
}

\App\Documentos::descargar($pedido, \App\Pedidos::detalles((int) $pedido->id));

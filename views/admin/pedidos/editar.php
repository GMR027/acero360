<?php
/** views/admin/pedidos/editar.php — Edición de un pedido pendiente (ver formulario.php). */
soloAdmin();
$pedido = \App\Pedidos::buscarID((int) ($_GET['id'] ?? 0));
if (!$pedido) {
    paginaNoEncontrada();
}
if ($pedido->estado !== 'pendiente') {
    redirigir('/admin/pedidos/detalle?id=' . (int) $pedido->id, 'error', 'Sólo se puede editar un pedido pendiente.');
}
$modo = 'editar';
require __DIR__ . '/formulario.php';

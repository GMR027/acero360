<?php
/**
 * views/admin/contabilidad/gastos/accion.php — cancelar un gasto (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id = (int) ($_POST['id'] ?? 0);
$destino = '/admin/contabilidad/gastos/detalle?id=' . $id;

if (\App\Gastos::cancelar($id, (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
    redirigir($destino, 'exito', 'Gasto cancelado. Queda en el historial con su motivo.');
}

redirigir($destino, 'error', \App\Gastos::getErrores()[0] ?? 'No fue posible cancelar el gasto.');

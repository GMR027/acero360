<?php
/**
 * views/admin/contabilidad/gastos/accion.php — acciones de un gasto (solo POST):
 * marcar como pagado, definir el método de pago del CFDI y cancelar.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id = (int) ($_POST['id'] ?? 0);
$destino = '/admin/contabilidad/gastos/detalle?id=' . $id;

// Solo se acepta volver al Centro de seguimiento (ruta interna fija), nunca una URL arbitraria.
if (preg_match('#^/admin/seguimiento(\?[a-z_=&]*)?$#', (string) ($_POST['volver'] ?? ''))) {
    $destino = $_POST['volver'];
}

if (($_POST['accion'] ?? '') === 'pagar') {
    if (\App\Gastos::marcarPagado($id, (string) ($_POST['metodo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        redirigir($destino, 'exito', 'Gasto marcado como pagado.');
    }
    redirigir($destino, 'error', \App\Gastos::getErrores()[0] ?? 'No fue posible marcar el gasto como pagado.');
}

// Centro de seguimiento: definir el método de pago del CFDI (PUE, PPD o por definir).
if (($_POST['accion'] ?? '') === 'metodo_cfdi') {
    if (\App\Gastos::metodoCfdi($id, (string) ($_POST['metodo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
        redirigir($destino, 'exito', 'Método de pago del CFDI guardado.');
    }
    redirigir($destino, 'error', \App\Gastos::getErrores()[0] ?? 'No fue posible guardar el método de pago del CFDI.');
}

if (\App\Gastos::cancelar($id, (string) ($_POST['motivo'] ?? ''), (int) ($_SESSION['id'] ?? 0))) {
    redirigir($destino, 'exito', 'Gasto cancelado. Queda en el historial con su motivo.');
}

redirigir($destino, 'error', \App\Gastos::getErrores()[0] ?? 'No fue posible cancelar el gasto.');

<?php
/**
 * views/admin/proyectos/accion.php — Acciones POST de un proyecto.
 *
 * - estado: activo, pausado, completado o cancelado.
 * - desvincular: quita una venta del proyecto sin borrar la venta.
 * - eliminar: sólo si no tiene ventas vinculadas.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('/admin/proyectos');
}

$accion = (string) ($_POST['accion'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);
$proyecto = \App\ProyectosEntrega::buscarID($id);

if (!$proyecto) {
    paginaNoEncontrada();
}

$usuarioId = (int) ($_SESSION['id'] ?? 0);

if ($accion === 'estado') {
    $estado = (string) ($_POST['estado'] ?? '');
    if (\App\ProyectosEntrega::cambiarEstado($id, $estado, $usuarioId)) {
        redirigir('/admin/proyectos/detalle?id=' . $id, 'exito', 'Estado del proyecto actualizado.');
    }
    redirigir('/admin/proyectos/detalle?id=' . $id, 'error', \App\ProyectosEntrega::getErrores()[0] ?? 'No fue posible cambiar el estado.');
}

if ($accion === 'desvincular') {
    $ventaId = (int) ($_POST['venta_id'] ?? 0);
    if (\App\ProyectosEntrega::desvincularVenta($id, $ventaId, $usuarioId)) {
        redirigir('/admin/proyectos/detalle?id=' . $id, 'exito', 'Venta desvinculada del proyecto. La venta y sus entregas se conservan.');
    }
    redirigir('/admin/proyectos/detalle?id=' . $id, 'error', \App\ProyectosEntrega::getErrores()[0] ?? 'No fue posible desvincular la venta.');
}

if ($accion === 'eliminar') {
    if (\App\ProyectosEntrega::eliminar($id, $usuarioId)) {
        redirigir('/admin/proyectos', 'exito', 'Proyecto eliminado.');
    }
    redirigir('/admin/proyectos/detalle?id=' . $id, 'error', \App\ProyectosEntrega::getErrores()[0] ?? 'No fue posible eliminar el proyecto.');
}

redirigir('/admin/proyectos/detalle?id=' . $id, 'error', 'Acción no reconocida.');

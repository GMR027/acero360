<?php
/**
 * views/admin/contabilidad/cierre-accion.php — cerrar o reabrir un mes (solo POST).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$anio    = (int) ($_POST['anio'] ?? 0);
$mes     = (int) ($_POST['mes'] ?? 0);
$contrib = \App\Contabilidad::contribuyentePrincipal();
$usuario = (int) ($_SESSION['id'] ?? 0);
$destino = "/admin/contabilidad/cierre?anio=$anio&mes=$mes";

if (($_POST['accion'] ?? '') === 'cerrar') {
    if (\App\CierrePeriodo::cerrar($contrib, $anio, $mes, $usuario, array_map('strval', (array) ($_POST['reconocidos'] ?? [])), (string) ($_POST['nota'] ?? ''))) {
        redirigir($destino, 'exito', 'Periodo cerrado. Se guardó la fotografía del mes.');
    }
} elseif (($_POST['accion'] ?? '') === 'reabrir') {
    if (\App\CierrePeriodo::reabrir($contrib, $anio, $mes, $usuario, (string) ($_POST['contrasena'] ?? ''), (string) ($_POST['motivo'] ?? ''))) {
        redirigir($destino, 'exito', 'Periodo reabierto. Quedó registrado el motivo.');
    }
} else {
    redirigir($destino, 'error', 'Acción no reconocida.');
}

redirigir($destino, 'error', \App\CierrePeriodo::getErrores()[0] ?? 'No fue posible completar la operación.');

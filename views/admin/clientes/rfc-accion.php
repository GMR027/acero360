<?php
/**
 * views/admin/clientes/rfc-accion.php — RFCs de facturación de un cliente (FASE 9).
 *
 * Acciones del panel «RFCs de facturación» de la ficha:
 *   · agregar: registra otro RFC del cliente (formato válido y LIBRE, con sus
 *     datos fiscales) — nada se inventa y queda auditado;
 *   · quitar: retira un RFC SECUNDARIO (el activo no se quita).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST)) {
    redirigir('/admin/clientes', 'error', 'Usa el panel de RFCs de la ficha del cliente.');
}

$clienteId = (int) ($_POST['cliente'] ?? 0);
$destino = $clienteId > 0 ? '/admin/clientes/editar?id=' . $clienteId . '#rfcs-facturacion' : '/admin/clientes';
if (!consumirToken($_POST['token'] ?? null)) {
    redirigir($destino, 'error', 'Este formulario ya se envió; no se guardó dos veces.');
}
$usuario = (int) ($_SESSION['id'] ?? 0);

if (($_POST['accion'] ?? '') === 'agregar') {
    $resultado = \App\ClientesRfc::agregar($clienteId, $_POST, $usuario);
    if ($resultado['ok']) {
        redirigir($destino, 'exito', 'RFC agregado a la ficha: al facturar podrás elegir cuál usar.');
    }
    redirigir($destino, 'error', implode(' · ', $resultado['errores']) ?: 'No fue posible agregar el RFC.');
}

if (($_POST['accion'] ?? '') === 'quitar') {
    if (\App\ClientesRfc::quitar((int) ($_POST['rfc_id'] ?? 0), $usuario)) {
        redirigir($destino, 'exito', 'RFC quitado de la ficha.');
    }
    redirigir($destino, 'error', implode(' · ', \App\ClientesRfc::getErrores()) ?: 'No fue posible quitar el RFC.');
}

redirigir($destino, 'error', 'Acción no reconocida.');

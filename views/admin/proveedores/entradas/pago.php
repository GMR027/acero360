<?php
/**
 * views/admin/proveedores/entradas/pago.php — Marcar o desmarcar una entrada
 * como pagada.
 *
 * Ruta protegida por el middleware "soloAdmin" y accesible sólo por POST.
 *
 * Es una acción de estado (no una eliminación), así que el botón verde del
 * "Balance general" no pide confirmación: es reversible con el mismo clic y
 * no destruye nada. Sí se exige POST, porque un GET jamás debe cambiar datos.
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$id      = (int) ($_POST['id'] ?? 0);
$pagado  = !empty($_POST['pagado']);
$entrada = \App\ProveedorEntradas::buscarID($id);

/** Destino de la vuelta: sólo rutas internas (empiezan por "/" y sin "//"). */
$retorno = (string) ($_POST['retorno'] ?? '');
$esInterna = $retorno !== '' && $retorno[0] === '/' && strpos($retorno, '//') !== 1;

if (!$entrada) {
    redirigir(
        $esInterna ? $retorno : '/admin/proveedores/entradas',
        'error',
        'La entrada seleccionada ya no existe.'
    );
}

$destino = $esInterna
    ? $retorno
    : '/admin/proveedores/detalle?id=' . (int) $entrada->proveedor_id;

if (\App\ProveedorEntradas::alternarPagado((int) $entrada->id, $pagado)) {
    redirigir(
        $destino,
        'exito',
        $pagado
            ? 'Entrada marcada como PAGADA.'
            : 'Entrada devuelta a PENDIENTE por pagar.'
    );
}

$errores = \App\ProveedorEntradas::getErrores();
redirigir($destino, 'error', $errores[0] ?? 'No fue posible actualizar el estado de pago.');

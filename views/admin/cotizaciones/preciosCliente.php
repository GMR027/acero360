<?php
/**
 * views/admin/cotizaciones/preciosCliente.php — Precios especiales en JSON.
 *
 * Ruta protegida por el middleware "soloAdmin".
 *
 * Cuando el usuario cambia el cliente dentro del cotizador, el navegador
 * pide por aquí los precios acordados con ese cliente y el descuento de su
 * ficha, y actualiza los renglones cargados. No devuelve HTML: sólo datos,
 * de modo que la vista pueda reconstruir las filas con createElement().
 */

soloAdmin();

header('Content-Type: application/json; charset=utf-8');

$clienteId = (int) ($_GET['cliente'] ?? 0);

$precios  = \App\PreciosCliente::todosPorCliente($clienteId);
$descuento = 0.0;

$cliente = $clienteId > 0 ? \App\Clientes::buscarID($clienteId) : null;
if ($cliente) {
    $descuento = (float) $cliente->descuento_pct;
}

echo json_encode(
    [
        'cliente'   => $clienteId,
        'precios'   => $precios,
        'descuento' => $descuento,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
exit;

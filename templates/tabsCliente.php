<?php
/**
 * templates/tabsCliente.php — Pestañas de la ficha de un cliente.
 *
 * Variables:
 *   $clienteId — id del cliente.
 *   $tab       — 'ficha' | 'compras' | 'proyectos'.
 */
$clienteId = (int) ($clienteId ?? 0);
$tab = (string) ($tab ?? 'ficha');

$secciones = [
    'ficha'     => ['/admin/clientes/detalle?id=' . $clienteId, 'Ficha', 'badge'],
    'compras'   => ['/admin/clientes/compras?id=' . $clienteId, 'Compras', 'shopping_cart'],
    'proyectos' => ['/admin/proyectos?cliente=' . $clienteId, 'Proyectos', 'assignment'],
];
$etiqueta = 'Secciones del cliente';
require __DIR__ . '/navModulo.php';

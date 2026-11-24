<?php
/**
 * templates/tabsCompras.php — «Compras y proveedores»: todo el abastecimiento es un solo módulo.
 * Variable: $tab ('resumen' | 'pedidos' | 'recepciones' | 'proveedores' | 'catalogo' | 'facturas' | 'inteligente' | 'complemento' | 'pagos' | 'categorias').
 */
$secciones = [
    'resumen'     => ['/admin/compras', 'Resumen', 'dashboard'],
    'pedidos'     => ['/admin/compras/pedidos', 'Pedidos', 'shopping_cart', 'Compras y pedidos'],
    'recepciones' => ['/admin/compras/recepciones', 'Recepciones', 'inventory_2'],
    'proveedores' => ['/admin/proveedores', 'Proveedores', 'local_shipping'],
    'catalogo'    => ['/admin/compras/catalogo', 'Catálogo', 'category_search'],
    'facturas'    => ['/admin/compras/facturas', 'Facturas', 'receipt_long'],
    'inteligente' => ['/admin/compras/inteligente', 'Compras inteligentes', 'document_scanner'],
    'complemento' => ['/admin/compras/complemento', 'Complementos de pago', 'receipt'],
    'pagos'       => ['/admin/compras/pagos', 'Pagos', 'payments'],
    'categorias'  => ['/admin/proveedores/categorias', 'Categorías', 'category'],
];
$etiqueta = 'Compras y proveedores';
require __DIR__ . '/navModulo.php';

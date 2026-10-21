<?php
/**
 * templates/tabsCompras.php — «Compras y proveedores»: todo el abastecimiento es un solo módulo.
 * Variable: $tab ('resumen' | 'pedidos' | 'recepciones' | 'proveedores' | 'catalogo' | 'facturas' | 'pagos' | 'categorias').
 */
$secciones = [
    'resumen'     => ['/admin/compras', 'Resumen', 'dashboard'],
    'pedidos'     => ['/admin/compras/pedidos', 'Compras y pedidos', 'shopping_cart'],
    'recepciones' => ['/admin/compras/recepciones', 'Recepciones', 'inventory_2'],
    'proveedores' => ['/admin/proveedores', 'Proveedores', 'local_shipping'],
    'catalogo'    => ['/admin/compras/catalogo', 'Catálogo', 'category_search'],
    'facturas'    => ['/admin/compras/facturas', 'Facturas', 'receipt_long'],
    'pagos'       => ['/admin/compras/pagos', 'Pagos', 'payments'],
    'categorias'  => ['/admin/proveedores/categorias', 'Categorías', 'category'],
];
?>
<nav class="chip-grupo entregas-tabs ajustes-tabs" aria-label="Compras y proveedores">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>

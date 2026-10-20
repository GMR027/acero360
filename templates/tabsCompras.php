<?php
/**
 * templates/tabsCompras.php — «Compras y proveedores»: las órdenes de compra y el directorio de proveedores
 * son un solo módulo. Variable: $tab ('ordenes' | 'proveedores').
 */
$secciones = [
    'ordenes'     => ['/admin/compras', 'Órdenes de compra', 'shopping_cart'],
    'proveedores' => ['/admin/proveedores', 'Proveedores', 'local_shipping'],
];
?>
<nav class="chip-grupo entregas-tabs" aria-label="Compras y proveedores">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>

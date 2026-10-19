<?php
/**
 * templates/tabsEntregas.php — Pestañas de la pantalla de entrega.
 * Variable: $tab ('preparacion' | 'clientes')
 */
$tabs = [
    'preparacion' => ['/admin/entregas', 'Preparación de pedidos', 'inventory'],
    'clientes'    => ['/admin/entregas/clientes', 'Entregas por cliente', 'person_pin_circle'],
];
?>
<nav class="chip-grupo entregas-tabs" aria-label="Vistas de la pantalla de entrega">
    <?php foreach ($tabs as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span>
            <span><?= $texto ?></span>
        </a>
    <?php endforeach; ?>
</nav>

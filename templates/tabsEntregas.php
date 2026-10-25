<?php
/**
 * templates/tabsEntregas.php — Pestañas de la pantalla de entrega.
 * Variable: $tab ('preparacion' | 'clientes' | 'seguimiento')
 */
$tabs = [
    'preparacion' => ['/admin/entregas', 'Preparación de pedidos', 'inventory'],
    'clientes'    => ['/admin/entregas/clientes', 'Entregas por cliente', 'person_pin_circle'],
    'seguimiento' => ['/admin/entregas-seguimiento', 'Seguimiento de entregas', 'fact_check'],
];
?>
<nav class="chip-grupo entregas-tabs ajustes-tabs" aria-label="Vistas de la pantalla de entrega">
    <?php foreach ($tabs as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span>
            <span><?= $texto ?></span>
        </a>
    <?php endforeach; ?>
</nav>

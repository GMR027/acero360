<?php
/**
 * templates/tabsEntregas.php — Pestañas de la sección «Operación Comercial».
 *
 * Las tres pantallas del flujo (preparación, por cliente y seguimiento) más
 * el listado de ventas son vistas hermanas de UNA sola sección: la barra se
 * imprime en todas ellas para que el usuario no tenga que salir al menú.
 *
 * Variable: $tab ('preparacion' | 'clientes' | 'seguimiento' | 'ventas')
 */
$tabs = [
    'preparacion' => ['/admin/entregas', 'Preparación de pedidos', 'inventory'],
    'clientes'    => ['/admin/entregas/clientes', 'Entregas por cliente', 'person_pin_circle'],
    'seguimiento' => ['/admin/entregas-seguimiento', 'Seguimiento de entregas', 'fact_check'],
    'ventas'      => ['/admin/ventas', 'Ventas', 'assignment_return'],
];
?>
<nav class="chip-grupo entregas-tabs ajustes-tabs" aria-label="Vistas de operación comercial">
    <?php foreach ($tabs as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span>
            <span><?= $texto ?></span>
        </a>
    <?php endforeach; ?>
</nav>

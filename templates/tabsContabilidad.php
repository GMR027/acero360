<?php
/**
 * templates/tabsContabilidad.php — Secciones del módulo de contabilidad, en lenguaje llano.
 * Lo avanzado (documentos, cierre del mes, periodos cerrados) vive en «Más opciones».
 * Variables: $tab, $periodo (anio/mes/empresa) para conservar el periodo al navegar.
 */
$tab = in_array($tab, ['documentos', 'cierre', 'periodos'], true) ? 'mas' : $tab;
$consulta = http_build_query(array_filter(
    ['anio' => $periodo['anio'] ?? null, 'mes' => $periodo['mes'] ?? null, 'empresa' => $periodo['empresa'] ?? null],
    static fn ($v) => $v !== null && $v !== 0
));
$sufijo = $consulta !== '' ? '?' . $consulta : '';
$secciones = [
    'resumen'  => ['/admin/contabilidad', 'Resumen del mes', 'dashboard'],
    'ingresos' => ['/admin/contabilidad/ingresos', 'Ventas y cobros', 'payments'],
    'gastos'   => ['/admin/contabilidad/gastos', 'Gastos', 'receipt'],
    'sin'      => ['/admin/contabilidad/sin-facturar', 'Pendientes de facturar', 'receipt_long'],
    'mas'      => ['/admin/contabilidad/mas', 'Más opciones', 'more_horiz'],
];
?>
<nav class="chip-grupo entregas-tabs ajustes-tabs" aria-label="Secciones de contabilidad">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta . ($clave === 'mas' ? '' : $sufijo) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span>
            <span><?= $texto ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php
/**
 * templates/tabsContabilidad.php — Secciones del módulo de contabilidad.
 * Variables: $tab, $periodo (anio/mes/empresa) para conservar el periodo al navegar.
 */
$consulta = http_build_query(array_filter(
    ['anio' => $periodo['anio'] ?? null, 'mes' => $periodo['mes'] ?? null, 'empresa' => $periodo['empresa'] ?? null],
    static fn ($v) => $v !== null && $v !== 0
));
$sufijo = $consulta !== '' ? '?' . $consulta : '';
$secciones = [
    'resumen'     => ['/admin/contabilidad', 'Resumen mensual', 'dashboard'],
    'ingresos'    => ['/admin/contabilidad/ingresos', 'Ingresos por ventas', 'trending_up'],
    'gastos'      => ['/admin/contabilidad/gastos', 'Egresos y gastos', 'trending_down'],
    'documentos'  => ['/admin/contabilidad/documentos', 'Comprobantes y documentos', 'folder_open'],
    'sin'         => ['/admin/contabilidad/sin-facturar', 'Ventas sin facturar', 'receipt_long'],
    'periodos'    => ['/admin/contabilidad/periodos', 'Periodos cerrados', 'lock'],
];
?>
<nav class="chip-grupo entregas-tabs" aria-label="Secciones de contabilidad">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= $ruta . ($clave === 'periodos' ? '' : $sufijo) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span>
            <span><?= $texto ?></span>
        </a>
    <?php endforeach; ?>
</nav>

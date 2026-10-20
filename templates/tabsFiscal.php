<?php
/**
 * templates/tabsFiscal.php — Secciones del módulo fiscal. Variables: $tab, $periodo (anio/mes) opcional.
 */
$consulta = isset($periodo) ? '?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]) : '';
$secciones = [
    'resumen'       => ['/admin/fiscal' . $consulta, 'Impuestos', 'request_quote'],
    'movimientos'   => ['/admin/fiscal/movimientos' . $consulta, 'Ventas y gastos', 'table_view'],
    'configuracion' => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune'],
    'avanzado'      => ['/admin/fiscal/detalle' . $consulta, 'Cálculo detallado', 'calculate'],
];
$avanzadas = [
    'panel'     => ['/admin/fiscal/detalle' . $consulta, 'Cálculo del mes'],
    'registros' => ['/admin/fiscal/registros' . (isset($periodo) ? '?anio=' . $periodo['anio'] : ''), 'Pagos al SAT y ajustes'],
    'catalogo'  => ['/admin/fiscal/catalogo', 'Reglas del SAT'],
];
$enAvanzado = isset($avanzadas[$tab]);
?>
<nav class="chip-grupo entregas-tabs" aria-label="Secciones fiscales">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave || ($clave === 'avanzado' && $enAvanzado); ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>
<?php if ($enAvanzado) : ?>
    <nav class="chip-grupo entregas-tabs" aria-label="Opciones avanzadas">
        <?php foreach ($avanzadas as $clave => [$ruta, $texto]) : $activo = $tab === $clave; ?>
            <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>><span><?= $texto ?></span></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php
/**
 * templates/tabsFiscal.php — Secciones del módulo fiscal. Variables: $tab, $periodo (anio/mes) opcional.
 */
$consulta = isset($periodo) ? '?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]) : '';
$secciones = [
    'resumen'       => ['/admin/fiscal' . $consulta, 'Impuestos', 'request_quote'],
    'preliminar'    => ['/admin/fiscal/preliminar' . $consulta, 'Preliminar básico', 'calculate'],
    'movimientos'   => ['/admin/fiscal/movimientos' . $consulta, 'Ventas y gastos', 'table_view'],
    'registros'     => ['/admin/fiscal/registros' . $consulta, 'Retenciones y declaraciones', 'gavel'],
    'configuracion' => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune'],
];
?>
<nav class="chip-grupo entregas-tabs ajustes-tabs" aria-label="Secciones fiscales">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>

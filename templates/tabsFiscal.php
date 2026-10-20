<?php
/**
 * templates/tabsFiscal.php — Secciones del módulo fiscal. Variables: $tab, $periodo (anio/mes) opcional.
 */
$consulta = isset($periodo) ? '?' . http_build_query(['anio' => $periodo['anio'], 'mes' => $periodo['mes']]) : '';
$secciones = [
    'panel'         => ['/admin/fiscal' . $consulta, 'Impuestos del mes', 'calculate'],
    'registros'     => ['/admin/fiscal/registros' . (isset($periodo) ? '?anio=' . $periodo['anio'] : ''), 'Pagos al SAT y ajustes', 'edit_note'],
    'catalogo'      => ['/admin/fiscal/catalogo', 'Reglas del SAT', 'gavel'],
    'configuracion' => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune'],
];
?>
<nav class="chip-grupo entregas-tabs" aria-label="Secciones fiscales">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = $tab === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>

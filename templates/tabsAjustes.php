<?php
/**
 * templates/tabsAjustes.php — Pestañas de Ajustes. Variable: $tab (general | empresas | fiscal | escritorio | acerca).
 */
$secciones = [
    'general'    => ['/admin/ajustes', 'General', 'settings'],
    'empresas'   => ['/admin/empresas', 'Empresas y razón social', 'corporate_fare'],
    'fiscal'     => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune'],
    'escritorio' => ['/admin/escritorio', 'Aplicación de escritorio', 'desktop_windows'],
    'acerca'     => ['/admin/ajustes?seccion=acerca', 'Acerca de', 'info'],
];
?>
<nav class="chip-grupo entregas-tabs" aria-label="Secciones de Ajustes">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = ($tab ?? '') === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1rem"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>

<?php
/**
 * templates/tabsAjustes.php — Pestañas de Ajustes. Variable: $tab (general | usuarios | perfil | empresas | fiscal | escritorio | version | novedades | papelera | ayuda).
 */
$secciones = [
    'general'    => ['/admin/ajustes', 'Configuración general', 'settings'],
    'usuarios'   => ['/admin/usuarios', 'Usuarios y permisos', 'manage_accounts'],
    'perfil'     => ['/admin/perfil', 'Perfil', 'account_circle'],
    'empresas'   => ['/admin/empresas', 'Empresas y razones sociales', 'corporate_fare'],
    'fiscal'     => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune'],
    'escritorio' => ['/admin/escritorio', 'Aplicación de escritorio', 'desktop_windows'],
    'version'    => ['/admin/ajustes?seccion=version', 'Versión y actualizaciones', 'info'],
    'novedades'  => ['/admin/ajustes?seccion=novedades', 'Novedades de la plataforma', 'campaign'],
    'papelera'   => ['/admin/ajustes/papelera', 'Papelera', 'delete'],
    'ayuda'      => ['/admin/ayuda', 'Ayuda y manual', 'help'],
];
?>
<nav class="chip-grupo entregas-tabs ajustes-tabs" aria-label="Secciones de Ajustes">
    <?php foreach ($secciones as $clave => [$ruta, $texto, $icono]) : $activo = ($tab ?? '') === $clave; ?>
        <a class="chip<?= $activo ? ' chip--activo' : '' ?>" href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span><span><?= $texto ?></span></a>
    <?php endforeach; ?>
</nav>

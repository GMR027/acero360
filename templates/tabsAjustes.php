<?php
/**
 * templates/tabsAjustes.php — Navegación lateral de Ajustes.
 *
 * Variable: $tab (general | usuarios | perfil | empresas | fiscal | escritorio |
 * android | almacenamiento | version | novedades | papelera | ayuda).
 *
 * header.php abre el armazón `.ajustes-shell`; este template pinta la franja
 * de resumen, el menú de secciones y abre `.ajustes-contenido`, que cierra
 * footer.php. Así todas las pantallas de configuración comparten la misma
 * estructura de dos columnas.
 */

$secciones = [
    'general'        => ['/admin/ajustes', 'Configuración general', 'settings', 'Tema, paleta y operación', 'General'],
    'usuarios'       => ['/admin/usuarios', 'Usuarios y permisos', 'manage_accounts', 'Roles y accesos del equipo', 'Equipo'],
    'perfil'         => ['/admin/perfil', 'Perfil', 'account_circle', 'Datos personales y contraseña', 'Equipo'],
    'empresas'       => ['/admin/empresas', 'Empresas y razones sociales', 'corporate_fare', 'Sucursales y régimen fiscal', 'Organización'],
    'fiscal'         => ['/admin/fiscal/configuracion', 'Datos fiscales', 'tune', 'RFC, certificados y timbrado', 'Organización'],
    'escritorio'     => ['/admin/escritorio', 'Aplicación de escritorio', 'desktop_windows', 'Sincronización Windows / Mac', 'Aplicaciones'],
    'android'        => ['/admin/android', 'Aplicación Android', 'phone_android', 'Conexión móvil y terminal', 'Aplicaciones'],
    'almacenamiento' => ['/admin/ajustes/almacenamiento', 'Almacenamiento y respaldos', 'hard_drive', 'Espacio y copias de seguridad', 'Sistema'],
    'version'        => ['/admin/ajustes?seccion=version', 'Versión y actualizaciones', 'info', 'Estado del motor transaccional', 'Sistema'],
    'novedades'      => ['/admin/ajustes?seccion=novedades', 'Novedades de la plataforma', 'campaign', 'Cambios recientes y notas', 'Sistema'],
    'papelera'       => ['/admin/ajustes/papelera', 'Papelera', 'delete', 'Recuperación de elementos', 'Sistema'],
    'ayuda'          => ['/admin/ayuda', 'Ayuda y manual', 'help', 'Documentación y soporte', 'Sistema'],
];

$grupos = [];
foreach ($secciones as $clave => $datos) {
    $grupos[$datos[4]][$clave] = $datos;
}

$temaActual = temaPreferido();
$colorActual = colorPreferido();
$stockNegativo = \App\Configuracion::permitirStockNegativo();
$temaEtiquetas = [
    'claro'  => 'Claro',
    'oscuro' => 'Oscuro',
    'auto'   => 'Automático (sistema)',
];
$colorNombre = COLORES_ACENTO[$colorActual][0] ?? 'Azul acero';
?>

<div class="ajustes-resumen" role="group" aria-label="Resumen de preferencias" aria-live="polite">
    <div class="ajustes-resumen__dato">
        <span class="ajustes-resumen__icono" aria-hidden="true">
            <span class="material-symbols-outlined">routine</span>
        </span>
        <span class="ajustes-resumen__texto">
            <span class="ajustes-resumen__etiqueta">Modo de pantalla</span>
            <strong class="ajustes-resumen__valor" data-resumen-tema><?= escaparValores($temaEtiquetas[$temaActual] ?? 'Automático') ?></strong>
        </span>
    </div>
    <div class="ajustes-resumen__dato">
        <span class="ajustes-resumen__icono" aria-hidden="true">
            <span class="material-symbols-outlined">palette</span>
        </span>
        <span class="ajustes-resumen__texto">
            <span class="ajustes-resumen__etiqueta">Color de acento</span>
            <strong class="ajustes-resumen__valor" data-resumen-color><?= escaparValores($colorNombre) ?></strong>
        </span>
    </div>
    <div class="ajustes-resumen__dato">
        <span class="ajustes-resumen__icono" aria-hidden="true">
            <span class="material-symbols-outlined">inventory_2</span>
        </span>
        <span class="ajustes-resumen__texto">
            <span class="ajustes-resumen__etiqueta">Venta sin existencias</span>
            <strong class="ajustes-resumen__valor"><?= $stockNegativo ? 'Permitida' : 'Restringida' ?></strong>
        </span>
    </div>
</div>

<aside class="ajustes-nav" aria-label="Menú de configuración">
    <div class="ajustes-nav__cabecera">
        <span class="ajustes-nav__cabecera-icono" aria-hidden="true">
            <span class="material-symbols-outlined">tune</span>
        </span>
        <div>
            <strong>Menú de configuración</strong>
            <small><?= count($secciones) ?> módulos</small>
        </div>
    </div>

    <nav class="ajustes-nav__lista" aria-label="Secciones de Ajustes">
        <?php foreach ($grupos as $grupo => $enlaces) : ?>
            <div class="ajustes-nav__grupo">
                <span class="ajustes-nav__titulo"><?= escaparValores($grupo) ?></span>
                <?php foreach ($enlaces as $clave => [$ruta, $texto, $icono, $descripcion]) : $activo = ($tab ?? '') === $clave; ?>
                    <a class="ajustes-nav__enlace<?= $activo ? ' ajustes-nav__enlace--activo' : '' ?>"
                       href="<?= escaparValores($ruta) ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
                        <span class="ajustes-nav__icono" aria-hidden="true">
                            <span class="material-symbols-outlined"><?= escaparValores($icono) ?></span>
                        </span>
                        <span class="ajustes-nav__texto">
                            <strong><?= escaparValores($texto) ?></strong>
                            <small><?= escaparValores($descripcion) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>

<div class="ajustes-contenido">
<?php $GLOBALS['ajustesContenidoAbierto'] = true; ?>

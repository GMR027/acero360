<?php
/**
 * templates/bottomnav.php — Barra de navegación inferior para móvil.
 *
 * Patrón tipo Facebook: fija en la parte inferior de la pantalla, sólo
 * iconos y seis destinos. La sección activa se marca con color y fondo,
 * y el último botón despliega el cajón lateral con el resto de módulos
 * (Productos, Empresas y Usuarios).
 *
 * Se muestra únicamente en pantallas menores a 768px.
 */

/** URL actual sin query string ni barra final. */
$rutaNav = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rutaNav = is_string($rutaNav) ? rtrim($rutaNav, '/') : '';
if ($rutaNav === '') {
    $rutaNav = '/admin';
}

/** ¿La ruta actual coincide exactamente o está dentro de $prefijo? */
$coincide = static function (string $ruta, string $exacta, string $prefijo = ''): bool {
    if ($ruta === $exacta) {
        return true;
    }
    return $prefijo !== '' && strpos($ruta, $prefijo) === 0;
};

$activoInicio    = $coincide($rutaNav, '/admin');
$activoNueva     = $rutaNav === '/admin/cotizaciones/crear';
// Histórico cubre el listado y la edición/detalle de una cotización ya creada.
$activoHistorico = !$activoNueva
    && ($rutaNav === '/admin/cotizaciones' || strpos($rutaNav, '/admin/cotizaciones/') === 0);
$activoClientes  = $coincide($rutaNav, '/admin/clientes', '/admin/clientes/');
$activoNotas     = $coincide($rutaNav, '/admin/notas', '/admin/notas/');

// El botón "Más" queda activo cuando la pantalla pertenece al cajón lateral
// (incluye el perfil del usuario, que se abre desde "Usuarios y perfil").
$activoMas = $coincide($rutaNav, '/admin/productos', '/admin/productos/')
    || $coincide($rutaNav, '/admin/empresas', '/admin/empresas/')
    || $coincide($rutaNav, '/admin/escritorio', '/admin/escritorio/')
    || $coincide($rutaNav, '/admin/usuarios', '/admin/usuarios/')
    || $coincide($rutaNav, '/admin/ayuda', '/admin/ayuda/')
    || $rutaNav === '/admin/perfil';

/**
 * Devuelve la clase de estado para un elemento de la barra.
 *
 * @param bool $activo
 */
$claseActivo = static function (bool $activo): string {
    return $activo ? ' bottomnav__item--activo' : '';
};

/** Devuelve el atributo aria-current sólo cuando el elemento está activo. */
$ariaActivo = static function (bool $activo): string {
    return $activo ? ' aria-current="page"' : '';
};
?>
<nav class="bottomnav" aria-label="Navegación principal (móvil)">
    <a class="bottomnav__item<?= $claseActivo($activoInicio) ?>"<?= $ariaActivo($activoInicio) ?>
       href="/admin" aria-label="Inicio" title="Inicio">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">home</span>
    </a>

    <a class="bottomnav__item<?= $claseActivo($activoHistorico) ?>"<?= $ariaActivo($activoHistorico) ?>
       href="/admin/cotizaciones" aria-label="Histórico de cotizaciones" title="Histórico de cotizaciones">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">receipt_long</span>
    </a>

    <a class="bottomnav__item bottomnav__item--destacado<?= $claseActivo($activoNueva) ?>"<?= $ariaActivo($activoNueva) ?>
       href="/admin/cotizaciones/crear" aria-label="Nueva cotización" title="Nueva cotización">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">add_circle</span>
    </a>

    <a class="bottomnav__item<?= $claseActivo($activoClientes) ?>"<?= $ariaActivo($activoClientes) ?>
       href="/admin/clientes" aria-label="Clientes" title="Clientes">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">group</span>
    </a>

    <a class="bottomnav__item<?= $claseActivo($activoNotas) ?>"<?= $ariaActivo($activoNotas) ?>
       href="/admin/notas" aria-label="Notas personales" title="Notas personales">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">sticky_note_2</span>
    </a>

    <button type="button" class="bottomnav__item<?= $claseActivo($activoMas) ?>"<?= $ariaActivo($activoMas) ?>
            id="boton-menu" aria-label="Más secciones" title="Más secciones"
            aria-controls="sidebar" aria-expanded="false">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">menu</span>
    </button>
</nav>

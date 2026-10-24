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

$activoInicio    = $rutaNav === '/admin';
$activoNueva     = $rutaNav === '/admin/cotizaciones/crear' || $rutaNav === '/admin/ventas/crear';
$activoEntregas  = $coincide($rutaNav, '/admin/entregas', '/admin/entregas/');
$activoInventario = $coincide($rutaNav, '/admin/inventario', '/admin/inventario/');
// Configuración fiscal es una pestaña de Ajustes, no de Impuestos.
$activoImpuestos = $rutaNav !== '/admin/fiscal/configuracion'
    && $coincide($rutaNav, '/admin/fiscal', '/admin/fiscal/');

// El botón "Más" queda activo cuando la pantalla pertenece al cajón lateral
// (incluye el perfil del usuario, que se abre desde "Usuarios y perfil").
$activoMas = (!$activoNueva && $coincide($rutaNav, '/admin/cotizaciones', '/admin/cotizaciones/'))
    || $coincide($rutaNav, '/admin/ventas', '/admin/ventas/')
    || $coincide($rutaNav, '/admin/clientes', '/admin/clientes/')
    || $coincide($rutaNav, '/admin/notas', '/admin/notas/')
    || $coincide($rutaNav, '/admin/calendario', '/admin/calendario/')
    || $coincide($rutaNav, '/admin/productos', '/admin/productos/')
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

    <a class="bottomnav__item<?= $claseActivo($activoEntregas) ?>"<?= $ariaActivo($activoEntregas) ?>
       href="/admin/entregas" aria-label="Pantalla de entrega" title="Pantalla de entrega">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">package_2</span>
    </a>

    <!-- Botón central: pregunta si lo nuevo es una venta o una cotización. -->
    <button type="button" class="bottomnav__item bottomnav__item--destacado<?= $claseActivo($activoNueva) ?>"
            id="boton-nuevo" aria-label="Crear nuevo" title="Crear nuevo"
            aria-haspopup="true" aria-expanded="false" aria-controls="hoja-nuevo">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">add_circle</span>
    </button>

    <a class="bottomnav__item<?= $claseActivo($activoInventario) ?>"<?= $ariaActivo($activoInventario) ?>
       href="/admin/inventario" aria-label="Inventario" title="Inventario">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">warehouse</span>
    </a>

    <a class="bottomnav__item<?= $claseActivo($activoImpuestos) ?>"<?= $ariaActivo($activoImpuestos) ?>
       href="/admin/fiscal" aria-label="Impuestos" title="Impuestos">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">request_quote</span>
    </a>

    <button type="button" class="bottomnav__item<?= $claseActivo($activoMas) ?>"<?= $ariaActivo($activoMas) ?>
            id="boton-menu" aria-label="Más secciones" title="Más secciones"
            aria-controls="sidebar" aria-expanded="false">
        <span class="material-symbols-outlined bottomnav__icono" aria-hidden="true">menu</span>
    </button>
</nav>

<div class="hoja-nuevo-fondo" id="fondo-nuevo" hidden></div>
<div class="hoja-nuevo" id="hoja-nuevo" role="menu" aria-label="Crear nuevo" hidden>
    <a class="hoja-nuevo__opcion" role="menuitem" href="/admin/cotizaciones/crear">
        <span class="material-symbols-outlined" aria-hidden="true">note_add</span>
        <span>Nueva cotización</span>
    </a>
    <a class="hoja-nuevo__opcion" role="menuitem" href="/admin/ventas/crear">
        <span class="material-symbols-outlined" aria-hidden="true">point_of_sale</span>
        <span>Nueva venta</span>
    </a>
</div>

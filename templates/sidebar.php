<?php
/**
 * templates/sidebar.php — Navegación lateral del panel de administración.
 *
 * El estado activo se calcula a partir de la URL solicitada, no a partir de
 * una variable que cada vista tenga que recordar pasar: así ninguna pantalla
 * puede quedar con la sección equivocada marcada.
 *
 * Variables opcionales:
 *   $rutaActiva — sobrescribe la URL (sólo para casos excepcionales).
 */

/** URL actual sin query string y sin barra final. */
$rutaSolicitada = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rutaSolicitada = is_string($rutaSolicitada) ? rtrim($rutaSolicitada, '/') : '';
if ($rutaSolicitada === '') {
    $rutaSolicitada = '/admin';
}

$rutaActiva = $rutaActiva ?? '';
if ($rutaActiva === '') {
    $rutaActiva = $rutaSolicitada;
}

/**
 * Módulos del panel.
 *
 * ruta          → URL del enlace
 * subrutas      → true si las pantallas hijas (crear, editar, detalle) también
 *                 deben marcar este enlace como activo
 * excluir       → rutas que pertenecen a otro enlace y deben ignorarse aquí
 * extra         → rutas sueltas que no comparten prefijo con la del enlace
 *                 pero que sí pertenecen a esa sección (p. ej. el perfil)
 * divisor       → true para dibujar una línea divisoria justo antes de este
 *                 enlace (separa Operación de Ventas / Inventario /
 *                 Proveedores)
 * soloEscritorio → enlaces que en móvil tienen acceso propio desde la barra
 *                 inferior (bottomnav). No se repiten en el cajón para que
 *                 cada sección aparezca UNA sola vez en la navegación.
 */
$enlaces = [
    [
        'ruta'          => '/admin/cotizaciones/crear',
        'icono'         => 'note_add',
        'texto'         => 'Nueva Cotización',
        'subrutas'      => false,
        'excluir'       => [],
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/cotizaciones',
        'icono'         => 'receipt_long',
        'texto'         => 'Histórico & Pedidos',
        'subrutas'      => true,
        // La pantalla de alta tiene su propio enlace.
        'excluir'       => ['/admin/cotizaciones/crear'],
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/clientes',
        'icono'         => 'group',
        'texto'         => 'Directorio Clientes',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/productos',
        'icono'         => 'inventory_2',
        'texto'         => 'Catálogo Productos',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/notas',
        'icono'         => 'sticky_note_2',
        'texto'         => 'Notas Personales',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/empresas',
        'icono'         => 'corporate_fare',
        'texto'         => 'Empresas & Razón',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/usuarios',
        'icono'         => 'manage_accounts',
        'texto'         => 'Usuarios & Perfil',
        'subrutas'      => true,
        'excluir'       => [],
        // El perfil propio vive en otra ruta pero es parte de esta sección.
        'extra'         => ['/admin/perfil'],
        'soloEscritorio' => false,
    ],
    // --- Después de la línea divisoria: almacén y operación comercial ---
    // Ninguno de estos tres tiene acceso propio en la barra inferior, por lo
    // que se marcan con 'soloEscritorio => false' para que también aparezcan
    // en el cajón de móvil con su nombre junto al icono.
    [
        'ruta'          => '/admin/ventas',
        'icono'         => 'point_of_sale',
        'texto'         => 'Ventas',
        'subrutas'      => true,
        'excluir'       => [],
        'divisor'       => true,   // ← corta la lista aquí con una línea
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/entregas',
        'icono'         => 'package_2',
        'texto'         => 'Pantalla de entrega',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/contabilidad',
        'icono'         => 'account_balance',
        'texto'         => 'Contabilidad',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/estadisticas',
        'icono'         => 'insights',
        'texto'         => 'Estadísticas',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/fiscal',
        'icono'         => 'request_quote',
        'texto'         => 'Fiscal',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/inventario',
        'icono'         => 'warehouse',
        'texto'         => 'Inventario',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/compras',
        'icono'         => 'shopping_cart',
        'texto'         => 'Órdenes de compra',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/proveedores',
        'icono'         => 'local_shipping',
        'texto'         => 'Proveedores',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
];

/**
 * Comprueba si un enlace corresponde a la ruta actual.
 *
 * @param array{ruta: string, subrutas: bool, excluir: array<int, string>, extra?: array<int, string>} $enlace
 */
$estaActivo = function (array $enlace, string $ruta): bool {
    if (in_array($ruta, $enlace['excluir'], true)) {
        return false;
    }
    // Rutas sueltas declaradas explícitamente (p. ej. /admin/perfil).
    if (in_array($ruta, $enlace['extra'] ?? [], true)) {
        return true;
    }
    if ($ruta === $enlace['ruta']) {
        return true;
    }
    // Sólo coincide en la frontera de segmento: /admin/clientes NO activa /admin/clientesxx.
    return $enlace['subrutas'] && strpos($ruta, $enlace['ruta'] . '/') === 0;
};
?>
<aside class="sidebar" id="sidebar" aria-label="Navegación principal">
    <a class="sidebar__marca" href="/admin">
        <span class="sidebar__logo" aria-hidden="true">C</span>
        <span class="sidebar__nombre">
            <strong>Cotizaciones</strong>
            <span>Multi-Negocio</span>
        </span>
    </a>

    <div class="sidebar__bloque">
        <p class="sidebar__titulo-bloque">Operación</p>
        <nav class="sidebar__nav">
            <?php foreach ($enlaces as $enlace) :
                // Línea divisoria: se dibuja justo antes de su enlace.
                if (!empty($enlace['divisor'])) : ?>
                    <hr class="sidebar__divisor">
                <?php endif;

                $activo = $estaActivo($enlace, $rutaActiva);
                $claseExtra = ($enlace['soloEscritorio'] ?? false) ? ' sidebar__enlace--solo-escritorio' : '';
                ?>
                <a class="sidebar__enlace<?= $claseExtra ?><?= $activo ? ' sidebar__enlace--activo' : '' ?>"
                   href="<?= escaparValores($enlace['ruta']) ?>"
                   <?= $activo ? 'aria-current="page"' : '' ?>>
                    <span class="material-symbols-outlined sidebar__icono" aria-hidden="true"><?= escaparValores($enlace['icono']) ?></span>
                    <span class="sidebar__texto"><?= escaparValores($enlace['texto']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="sidebar__pie">
        Sesión: <strong><?= escaparValores($_SESSION['nombre'] ?? 'Invitado') ?></strong>
    </div>
</aside>

<!-- Fondo desenfocado para cerrar el menú en móvil. -->
<div class="fondo-desenfocado" id="fondo-sidebar" hidden></div>

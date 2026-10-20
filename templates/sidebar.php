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
 * grupo         → título de sección que se dibuja justo antes de este enlace
 *                 (inicia un grupo nuevo)
 * soloEscritorio → enlaces que en móvil tienen acceso propio desde la barra
 *                 inferior (bottomnav). No se repiten en el cajón para que
 *                 cada sección aparezca UNA sola vez en la navegación.
 */
$enlaces = [
    [
        'ruta'          => '/admin',
        'icono'         => 'home',
        'texto'         => 'Panel principal',
        'subrutas'      => false,
        'excluir'       => [],
        'grupo'         => 'Inicio',
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/seguimiento',
        'icono'         => 'notifications',
        'texto'         => 'Centro de seguimiento',
        'subrutas'      => false,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/cotizaciones/crear',
        'icono'         => 'note_add',
        'texto'         => 'Nueva cotización',
        'subrutas'      => false,
        'excluir'       => [],
        'grupo'         => 'Operación comercial',
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/ventas/crear',
        'icono'         => 'point_of_sale',
        'texto'         => 'Nueva venta',
        'subrutas'      => false,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/cotizaciones',
        'icono'         => 'receipt_long',
        'texto'         => 'Histórico de pedidos',
        'subrutas'      => true,
        'excluir'       => ['/admin/cotizaciones/crear'],
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/clientes',
        'icono'         => 'group',
        'texto'         => 'Directorio de clientes',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => true,
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
        'ruta'          => '/admin/ventas',
        'icono'         => 'assignment_return',
        'texto'         => 'Devoluciones y cambios',
        'subrutas'      => true,
        'excluir'       => ['/admin/ventas/crear'],
        // Las devoluciones se registran desde el detalle de cada venta.
        'prefijos'      => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/productos',
        'icono'         => 'inventory_2',
        'texto'         => 'Catálogo de productos',
        'subrutas'      => true,
        'excluir'       => [],
        'grupo'         => 'Productos e inventario',
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
        'texto'         => 'Compras',
        'subrutas'      => true,
        'excluir'       => [],
        'grupo'         => 'Compras y abastecimiento',
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
    [
        'ruta'          => '/admin/contabilidad',
        'icono'         => 'account_balance',
        'texto'         => 'Contabilidad',
        'subrutas'      => true,
        'excluir'       => ['/admin/contabilidad/sin-facturar', '/admin/contabilidad/sin-facturar/exportar'],
        'grupo'         => 'Administración y finanzas',
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/contabilidad/sin-facturar',
        'icono'         => 'receipt_long',
        'texto'         => 'Ventas sin facturar',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/fiscal',
        'icono'         => 'request_quote',
        'texto'         => 'Impuestos',
        'subrutas'      => true,
        'excluir'       => ['/admin/fiscal/configuracion'],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/estadisticas',
        'icono'         => 'insights',
        'texto'         => 'Estadísticas y métricas',
        'subrutas'      => true,
        'excluir'       => [],
        'soloEscritorio' => false,
    ],
    [
        'ruta'          => '/admin/notas',
        'icono'         => 'sticky_note_2',
        'texto'         => 'Notas personales',
        'subrutas'      => true,
        'excluir'       => [],
        'grupo'         => 'Herramientas personales',
        'soloEscritorio' => true,
    ],
    [
        'ruta'          => '/admin/ajustes',
        'icono'         => 'settings',
        'texto'         => 'Ajustes',
        'subrutas'      => false,
        'excluir'       => [],
        'grupo'         => 'Ajustes',
        // Usuarios, perfil, empresas, datos fiscales, app de escritorio y ayuda son pestañas de Ajustes.
        'prefijos'      => ['/admin/usuarios', '/admin/perfil', '/admin/empresas', '/admin/escritorio', '/admin/fiscal/configuracion', '/admin/ayuda'],
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
    foreach ($enlace['prefijos'] ?? [] as $prefijo) {
        if ($ruta === $prefijo || strpos($ruta, $prefijo . '/') === 0) {
            return true;
        }
    }
    // Sólo coincide en la frontera de segmento: /admin/clientes NO activa /admin/clientesxx.
    return $enlace['subrutas'] && strpos($ruta, $enlace['ruta'] . '/') === 0;
};
?>
<aside class="sidebar" id="sidebar" aria-label="Navegación principal">
    <a class="sidebar__marca" href="/admin" title="Acero 360 · Inicio" aria-label="Acero 360, ir al inicio">
        <img class="sidebar__logo" src="/public/brand/acero360-marca.svg" alt="" width="32" height="32">
        <span class="sidebar__nombre">
            <strong>Acero <em>360</em></strong>
            <span>Gestión comercial y contable</span>
        </span>
    </a>

    <div class="sidebar__bloque">
        <nav class="sidebar__nav" aria-label="Secciones">
            <?php foreach ($enlaces as $indice => $enlace) :
                // Título de grupo. En el cajón móvil se oculta si todos sus enlaces tienen acceso propio en la barra inferior.
                if (!empty($enlace['grupo'])) :
                    $todosOcultos = true;
                    for ($k = $indice; $k < count($enlaces) && ($k === $indice || empty($enlaces[$k]['grupo'])); $k++) {
                        $todosOcultos = $todosOcultos && !empty($enlaces[$k]['soloEscritorio']);
                    }
                    ?>
                    <p class="sidebar__titulo-bloque sidebar__titulo-grupo<?= $todosOcultos ? ' sidebar__enlace--solo-escritorio' : '' ?>"><?= escaparValores($enlace['grupo']) ?></p>
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
        <span class="sidebar__version">Acero 360 · v<?= escaparValores(ACERO360_VERSION) ?></span>
    </div>
</aside>

<!-- Fondo desenfocado para cerrar el menú en móvil. -->
<div class="fondo-desenfocado" id="fondo-sidebar" hidden></div>

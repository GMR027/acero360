<?php
/**
 * views/admin/proyectos/crear.php — Alta de un proyecto de entrega.
 */

soloAdmin();

$clientes = \App\Clientes::mostrarTodos(2000, 0, '');
$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static fn ($empresa): bool => (int) $empresa->activa === 1
));
$catalogo = \App\Productos::paraCotizador();

$clientePrecargado = (int) ($_GET['cliente'] ?? 0);
$errores = [];
$partidas = [];
$datos = [
    'cliente_id'       => $clientePrecargado,
    'empresa_id'       => 0,
    'nombre'           => '',
    'descripcion'      => '',
    'fecha_inicio'     => date('Y-m-d'),
    'fecha_compromiso' => '',
    'estado'           => 'activo',
    'notas'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $datos = [
        'cliente_id'       => (int) ($_POST['cliente_id'] ?? 0),
        'empresa_id'       => (int) ($_POST['empresa_id'] ?? 0),
        'nombre'           => trim((string) ($_POST['nombre'] ?? '')),
        'descripcion'      => trim((string) ($_POST['descripcion'] ?? '')),
        'fecha_inicio'     => trim((string) ($_POST['fecha_inicio'] ?? '')),
        'fecha_compromiso' => trim((string) ($_POST['fecha_compromiso'] ?? '')),
        'estado'           => (string) ($_POST['estado'] ?? 'activo'),
        'notas'            => trim((string) ($_POST['notas'] ?? '')),
    ];
    $partidas = \App\ProyectosEntrega::leerPartidasPost($_POST);
    $usuarioId = (int) ($_SESSION['id'] ?? 0);

    if (\App\ProyectosEntrega::crear($datos, $partidas, $usuarioId)) {
        redirigir(
            '/admin/proyectos/detalle?id=' . (int) \App\ProyectosEntrega::$ultimoId,
            'exito',
            'Proyecto creado correctamente.'
        );
    }
    $errores = \App\ProyectosEntrega::getErrores();
    $clientePrecargado = (int) ($datos['cliente_id'] ?? $clientePrecargado);
}

$proyecto = new \App\ProyectosEntrega($datos);

$volver = $clientePrecargado > 0
    ? '/admin/proyectos?cliente=' . $clientePrecargado
    : '/admin/proyectos';

template('header', [
    'titulo'    => 'Nuevo proyecto de entrega',
    'subtitulo' => 'Compromiso de entrega por cliente',
    'rutaActiva'=> '/admin/proyectos',
    'acciones'  => '<a class="btn btn--secundario" href="' . escaparValores($volver) . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Volver</span></a>',
]);
?>

<?php if ($clientePrecargado > 0) : ?>
    <?php template('tabsCliente', ['clienteId' => $clientePrecargado, 'tab' => 'proyectos']); ?>
<?php endif; ?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Captura lo que el cliente comprometió para una obra. Después podrás vincular las ventas
            y el avance se calculará con las entregas reales.
        </p>
    </div>
</section>

<?php template('formularioProyecto', [
    'modo'     => 'crear',
    'proyecto' => $proyecto,
    'partidas' => $partidas,
    'clientes' => $clientes,
    'empresas' => $empresas,
    'catalogo' => $catalogo,
    'errores'  => $errores,
    'accion'   => '/admin/proyectos/crear',
    'volver'   => $volver,
]); ?>

<?php template('footer'); ?>

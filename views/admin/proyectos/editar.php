<?php
/**
 * views/admin/proyectos/editar.php — Edición de un proyecto de entrega.
 */

soloAdmin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$proyecto = \App\ProyectosEntrega::buscarID($id);

if (!$proyecto) {
    paginaNoEncontrada();
}

$clientes = \App\Clientes::mostrarTodos(2000, 0, '');
$empresas = array_values(array_filter(
    \App\Empresas::mostrarTodos(500, 0, ''),
    static fn ($empresa): bool => (int) $empresa->activa === 1
));
// Un proyecto antiguo puede apuntar a un negocio ya inactivo: se conserva en el select.
if ((int) $proyecto->empresa_id > 0 && !array_filter($empresas, static fn ($e): bool => (int) $e->id === (int) $proyecto->empresa_id)) {
    $empresaActual = \App\Empresas::buscarID((int) $proyecto->empresa_id);
    if ($empresaActual) {
        $empresas[] = $empresaActual;
    }
}
$catalogo = \App\Productos::paraCotizador();

$errores = [];
$partidas = \App\ProyectosEntrega::partidas($id);

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

    if (\App\ProyectosEntrega::actualizar($id, $datos, $partidas, $usuarioId)) {
        redirigir('/admin/proyectos/detalle?id=' . $id, 'exito', 'Proyecto actualizado correctamente.');
    }
    $errores = \App\ProyectosEntrega::getErrores();
    $proyecto = new \App\ProyectosEntrega($datos + ['id' => $id, 'folio' => $proyecto->folio]);
}

template('header', [
    'titulo'    => 'Editar ' . $proyecto->folio,
    'subtitulo' => 'Proyecto de entrega',
    'rutaActiva'=> '/admin/proyectos',
    'acciones'  => '<a class="btn btn--secundario" href="/admin/proyectos/detalle?id=' . $id . '">'
        . '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>'
        . '<span>Volver al proyecto</span></a>',
]);
?>

<?php template('tabsCliente', ['clienteId' => (int) $proyecto->cliente_id, 'tab' => 'proyectos']); ?>

<section class="encabezado-pagina">
    <div>
        <h1 class="titulo-2"><?= escaparValores($proyecto->folio) ?></h1>
        <p class="texto-secundario">Edita los datos y las partidas comprometidas.</p>
    </div>
</section>

<?php template('formularioProyecto', [
    'modo'     => 'editar',
    'proyecto' => $proyecto,
    'partidas' => $partidas,
    'clientes' => $clientes,
    'empresas' => $empresas,
    'catalogo' => $catalogo,
    'errores'  => $errores,
    'accion'   => '/admin/proyectos/editar?id=' . $id,
    'volver'   => '/admin/proyectos/detalle?id=' . $id,
]); ?>

<?php template('footer'); ?>

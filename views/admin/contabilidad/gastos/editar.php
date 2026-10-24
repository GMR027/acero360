<?php
/**
 * views/admin/contabilidad/gastos/editar.php — Edición de un gasto vigente de un periodo abierto.
 */

soloAdmin();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$gasto = \App\Gastos::buscarID($id);

if (!$gasto) {
    paginaNoEncontrada();
}
if ($gasto['estado'] !== 'vigente') {
    redirigir('/admin/contabilidad/gastos/detalle?id=' . $id, 'error', 'Un gasto cancelado no se puede editar.');
}
if (\App\Contabilidad::periodoCerrado((int) $gasto['contribuyente_id'], $gasto['fecha'])) {
    redirigir('/admin/contabilidad/gastos/detalle?id=' . $id, 'error', \App\Contabilidad::mensajePeriodoCerrado($gasto['fecha']));
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    if (\App\Gastos::actualizar($id, $_POST, (int) ($_SESSION['id'] ?? 0))) {
        redirigir('/admin/contabilidad/gastos/detalle?id=' . $id, 'exito', 'Gasto actualizado.');
    }
    $errores = \App\Gastos::getErrores();
    $gasto = $_POST + $gasto;
    $gasto['reparto'] = array_map(static fn ($e, $p) => ['empresa_id' => (int) $e, 'porcentaje' => (float) $p],
        array_keys((array) ($_POST['reparto'] ?? [])), array_values((array) ($_POST['reparto'] ?? [])));
}

template('header', ['titulo' => 'Editar gasto', 'subtitulo' => 'Contabilidad', 'rutaActiva' => '/admin/contabilidad']);
?>

<section class="tarjeta">
    <form class="formulario" action="/admin/contabilidad/gastos/editar?id=<?= $id ?>" method="post" novalidate>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php template('formularioGasto', [
            'gasto' => $gasto, 'errores' => $errores, 'proveedores' => \App\Proveedores::paraSelector(),
            'categorias' => \App\Gastos::categorias(), 'empresas' => \App\Empresas::mostrarTodos(100, 0),
            'cancelar' => '/admin/contabilidad/gastos/detalle?id=' . $id,
            'reglasCategoria' => \App\CoincidenciasFactura::REGLAS_CATEGORIA,
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

<?php
/** views/admin/distribuidores/editar.php — Edición de un distribuidor. */

soloAdmin();

$distribuidor = \App\Distribuidores::buscarID((int) ($_GET['id'] ?? 0));
if (!$distribuidor) {
    paginaNoEncontrada();
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $errores = $distribuidor->sincronizar($_POST, $_FILES);
    $errores = array_merge($errores, $distribuidor->validar());
    $guardado = empty($errores) && $distribuidor->actualizar();
    $distribuidor->cerrarFormulario($guardado);
    if ($guardado) {
        redirigir('/admin/distribuidores', 'exito', 'Distribuidor «' . $distribuidor->nombre . '» actualizado.');
    }
    $errores = $errores ?: \App\Distribuidores::getErrores();
}

$empresas   = \App\Empresas::paraSelector();
$municipios = \App\Distribuidores::municipios();

template('header', [
    'titulo'     => 'Editar distribuidor',
    'subtitulo'  => $distribuidor->nombre,
    'rutaActiva' => '/admin/distribuidores',
    'acciones'   => '<a class="btn btn--secundario" href="/admin/distribuidores">Volver</a>',
]);
?>
<section class="tarjeta">
    <form class="formulario" action="/admin/distribuidores/editar?id=<?= (int) $distribuidor->id ?>" method="post" enctype="multipart/form-data" data-validar novalidate>
        <?php require __DIR__ . '/formulario.php'; ?>
    </form>
    <p class="texto-xs texto-secundario mt-md">
        Alta <?= escaparValores(substr($distribuidor->creado_en, 0, 10)) ?>
        · Última modificación <?= escaparValores(substr($distribuidor->actualizado_en, 0, 16)) ?>
    </p>
</section>
<?php template('footer'); ?>

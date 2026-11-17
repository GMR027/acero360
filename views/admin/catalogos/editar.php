<?php
/** views/admin/catalogos/editar.php — Renombrar en un catálogo de texto ($catalogo). */

soloAdmin();

$c        = \App\CatalogoTexto::config($catalogo);
$registro = \App\CatalogoTexto::buscarID($catalogo, (int) ($_GET['id'] ?? 0));
if (!$registro) {
    paginaNoEncontrada();
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $registro->nombre = trim($_POST['nombre'] ?? '');
    $errores = $registro->validar();
    if (empty($errores) && $registro->actualizar()) {
        redirigir($c['ruta'], 'exito', ucfirst($c['singular']) . ' actualizada.');
    }
    $errores = $errores ?: \App\CatalogoTexto::getErrores();
}

$usos = \App\CatalogoTexto::enUso($catalogo, (int) $registro->id);

template('header', [
    'titulo'     => 'Editar ' . $c['singular'],
    'subtitulo'  => $c['titulo'],
    'rutaActiva' => $c['rutaPadre'],
    'acciones'   => '<a class="btn btn--secundario" href="' . $c['ruta'] . '">Volver</a>',
]);
?>
<?php if ($usos > 0) : ?>
<section class="encabezado-pagina">
    <p class="texto-secundario">
        La usan <?= number_format($usos) ?> <?= escaparValores($usos === 1 ? $c['usoSingular'] : $c['usoPlural']) ?>:
        el nuevo nombre se verá en todos.
    </p>
</section>
<?php endif; ?>
<section class="tarjeta">
    <form class="formulario" action="<?= $c['ruta'] ?>/editar?id=<?= (int) $registro->id ?>" method="post" data-validar novalidate>
        <?php require __DIR__ . '/formulario.php'; ?>
    </form>
</section>
<?php template('footer'); ?>

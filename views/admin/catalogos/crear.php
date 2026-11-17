<?php
/** views/admin/catalogos/crear.php — Alta en un catálogo de texto ($catalogo). */

soloAdmin();

$c       = \App\CatalogoTexto::config($catalogo);
$errores = [];
$registro = new \App\CatalogoTexto($catalogo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    $registro->nombre = trim($_POST['nombre'] ?? '');
    $errores = $registro->validar();
    if (empty($errores) && $registro->crear()) {
        redirigir($c['ruta'], 'exito', ucfirst($c['singular']) . ' «' . $registro->nombre . '» creada.');
    }
    $errores = $errores ?: \App\CatalogoTexto::getErrores();
}

template('header', [
    'titulo'     => 'Nueva ' . $c['singular'],
    'subtitulo'  => $c['titulo'],
    'rutaActiva' => $c['rutaPadre'],
    'acciones'   => '<a class="btn btn--secundario" href="' . $c['ruta'] . '">Volver</a>',
]);
?>
<section class="tarjeta">
    <form class="formulario" action="<?= $c['ruta'] ?>/crear" method="post" data-validar novalidate>
        <?php require __DIR__ . '/formulario.php'; ?>
    </form>
</section>
<?php template('footer'); ?>

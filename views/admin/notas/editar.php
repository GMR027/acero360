<?php
/**
 * views/admin/notas/editar.php — Modificación de notas personales.
 *
 * Recibe el id por query string (?id=NN) y el formulario por POST.
 * Sólo el dueño de la nota puede verla o modificarla.
 */

soloAdmin();

$nota = \App\Notas::buscarID((int) ($_GET['id'] ?? 0));

if (!$nota) {
    paginaNoEncontrada(); // La vista 404 ya genera su propia cabecera y pie.
}

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $nota->titulo            = trim($_POST['titulo'] ?? '');
    $nota->cuerpo            = trim($_POST['cuerpo'] ?? '');
    $nota->categoria         = $_POST['categoria'] ?? 'otro';
    $nota->referencia        = trim($_POST['referencia'] ?? '');
    $nota->fecha_recordatorio = trim($_POST['fecha_recordatorio'] ?? '');
    $nota->fijada            = isset($_POST['fijada']) ? 1 : 0;

    $errores = $nota->validar();

    if (empty($errores) && $nota->actualizar()) {
        redirigir('/admin/notas', 'exito', 'Nota actualizada correctamente.');
    }

    $errores = $errores ?: \App\Notas::getErrores();
}

template('header', [
    'titulo'     => 'Editar nota',
    'subtitulo'  => 'Notas personales',
    'rutaActiva' => '/admin/notas',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">Modifica <strong><?= escaparValores($nota->titulo) ?></strong>.</p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/notas">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/notas/editar?id=<?= (int) $nota->id ?>" method="post" data-validar novalidate>
        <?php template('formularioNotas', ['nota' => $nota, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>

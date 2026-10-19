<?php
/**
 * views/admin/notas/crear.php — Alta de notas personales.
 *
 * Ruta protegida por el middleware "soloAdmin".
 * Alta y edición comparten templates/formularioNotas.php.
 */

soloAdmin();

$errores = [];
$esEnvio = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST);

if ($esEnvio) {
    $nota = new \App\Notas([
        // Quien redacta queda registrado, pero la nota es de todos los admin.
        'usuario_id'        => (int) ($_SESSION['id'] ?? 0),
        'titulo'            => trim($_POST['titulo'] ?? ''),
        'cuerpo'            => trim($_POST['cuerpo'] ?? ''),
        'categoria'         => $_POST['categoria'] ?? 'otro',
        'referencia'        => trim($_POST['referencia'] ?? ''),
        'fecha_recordatorio' => trim($_POST['fecha_recordatorio'] ?? ''),
        'fijada'            => isset($_POST['fijada']) ? 1 : 0,
    ]);

    $errores = $nota->validar();

    if (empty($errores) && $nota->crear()) {
        redirigir('/admin/notas', 'exito', 'Nota creada correctamente.');
    }

    $errores = $errores ?: \App\Notas::getErrores();
} else {
    $nota = new \App\Notas();
}

template('header', [
    'titulo'     => 'Nueva nota',
    'subtitulo'  => 'Notas personales',
    'rutaActiva' => '/admin/notas',
]);
?>

<section class="encabezado-pagina">
    <div>
        <!-- <h1 class="titulo-2">Nueva nota</h1> -->
        <p class="texto-secundario">
            Anota un recordatorio, algo por cotizar, un cambio o una revisión.
            Los campos marcados con asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="/admin/notas">Volver</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/notas/crear" method="post" data-validar novalidate>
        <?php template('formularioNotas', ['nota' => $nota, 'errores' => $errores]); ?>
    </form>
</section>

<?php template('footer'); ?>

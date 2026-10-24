<?php
/**
 * views/admin/calendario/editar.php — Modificación de recordatorios y eventos.
 *
 * GET muestra el formulario con el evento cargado; POST guarda los cambios.
 * Ruta protegida por el middleware "soloAdmin".
 */

soloAdmin();

$id     = validarID($_GET['id'] ?? ($_POST['id'] ?? 0), '/admin/calendario');
$evento = \App\EventosCalendario::buscarID($id);

if (!$evento) {
    paginaNoEncontrada();
}

/** Regreso al mes y al día exactos del evento. */
$fechaEvento = new DateTimeImmutable($evento->fecha);
$regreso     = '/admin/calendario?anio=' . (int) $fechaEvento->format('Y')
    . '&mes=' . (int) $fechaEvento->format('n')
    . '&dia=' . (int) $fechaEvento->format('j');

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $evento = new \App\EventosCalendario([
        'id'          => $evento->id,
        'usuario_id'  => $evento->usuario_id,
        'fecha'       => trim($_POST['fecha'] ?? ''),
        'hora'        => trim($_POST['hora'] ?? ''),
        'nombre'      => trim($_POST['nombre'] ?? ''),
        'descripcion' => trim($_POST['descripcion'] ?? ''),
        'empresa_id'  => trim($_POST['empresa_id'] ?? ''),
        'importancia' => $_POST['importancia'] ?? 'medio',
    ]);

    $errores = $evento->validar();

    if (empty($errores) && $evento->actualizar()) {
        // Si cambió de día, la vista se abre donde quedó el evento.
        $fechaNueva = new DateTimeImmutable($evento->fecha);
        redirigir(
            '/admin/calendario?anio=' . (int) $fechaNueva->format('Y')
                . '&mes=' . (int) $fechaNueva->format('n')
                . '&dia=' . (int) $fechaNueva->format('j'),
            'exito',
            'Evento actualizado.'
        );
    }

    $errores = $errores ?: \App\EventosCalendario::getErrores();
}

template('header', [
    'titulo'     => 'Editar evento',
    'subtitulo'  => 'Calendario',
    'rutaActiva' => '/admin/calendario',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Modifica los datos del recordatorio o evento. Los campos marcados
            con asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($regreso) ?>">Volver al calendario</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/calendario/editar" method="post" data-validar novalidate>
        <input type="hidden" name="id" value="<?= (int) $evento->id ?>">
        <?php template('formularioCalendario', [
            'evento'       => $evento,
            'errores'      => $errores,
            'rutaCancelar' => $regreso,
            'idMensaje'    => 'errores-calendario',
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

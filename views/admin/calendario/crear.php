<?php
/**
 * views/admin/calendario/crear.php — Alta de recordatorios y eventos.
 *
 * Sólo acepta POST (el formulario vive en el calendario, al elegir un día).
 * Ruta protegida por el middleware "soloAdmin".
 */

soloAdmin();

$errores = [];

/** Día, mes y año a los que regresar tras guardar (los manda el formulario). */
$anioRegreso = (int) ($_POST['anio'] ?? date('Y'));
$anioRegreso = ($anioRegreso >= 2000 && $anioRegreso <= 2100) ? $anioRegreso : (int) date('Y');

$mesRegreso = isset($_POST['mes']) ? (int) $_POST['mes'] : (int) date('n');
$mesRegreso = ($mesRegreso >= 1 && $mesRegreso <= 12) ? $mesRegreso : (int) date('n');

$diaRegreso = isset($_POST['dia']) ? (int) $_POST['dia'] : 0;
$diaRegreso = ($diaRegreso >= 1 && $diaRegreso <= 31) ? $diaRegreso : 0;

$regreso = '/admin/calendario?anio=' . $anioRegreso . '&mes=' . $mesRegreso
    . ($diaRegreso > 0 ? '&dia=' . $diaRegreso : '');

$evento = new \App\EventosCalendario([
    // Quien captura queda registrado, pero el calendario es de todos los admin.
    'usuario_id'  => (int) ($_SESSION['id'] ?? 0),
    'fecha'       => trim($_POST['fecha'] ?? ''),
    'hora'        => trim($_POST['hora'] ?? ''),
    'nombre'      => trim($_POST['nombre'] ?? ''),
    'descripcion' => trim($_POST['descripcion'] ?? ''),
    'empresa_id'  => trim($_POST['empresa_id'] ?? ''),
    'importancia' => $_POST['importancia'] ?? 'medio',
]);

$errores = $evento->validar();

if (empty($errores) && $evento->crear()) {
    // Se abre el mes y el día donde quedó guardado el evento.
    $fecha = new DateTimeImmutable($evento->fecha);
    redirigir(
        '/admin/calendario?anio=' . (int) $fecha->format('Y')
            . '&mes=' . (int) $fecha->format('n')
            . '&dia=' . (int) $fecha->format('j'),
        'exito',
        'Evento guardado en el calendario.'
    );
}

$errores = $errores ?: \App\EventosCalendario::getErrores();

template('header', [
    'titulo'     => 'Nuevo evento',
    'subtitulo'  => 'Calendario',
    'rutaActiva' => '/admin/calendario',
]);
?>

<section class="encabezado-pagina">
    <div>
        <p class="texto-secundario">
            Revisa los datos del recordatorio o evento y vuelve a guardar.
            Los campos marcados con asterisco son obligatorios.
        </p>
    </div>
    <div class="encabezado-pagina__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($regreso) ?>">Volver al calendario</a>
    </div>
</section>

<section class="tarjeta">
    <form class="formulario" action="/admin/calendario/crear" method="post" data-validar novalidate>
        <input type="hidden" name="anio" value="<?= $anioRegreso ?>">
        <input type="hidden" name="mes" value="<?= $mesRegreso ?>">
        <input type="hidden" name="dia" value="<?= $diaRegreso ?>">
        <?php template('formularioCalendario', [
            'evento'       => $evento,
            'errores'      => $errores,
            'rutaCancelar' => $regreso,
            'idMensaje'    => 'errores-calendario',
        ]); ?>
    </form>
</section>

<?php template('footer'); ?>

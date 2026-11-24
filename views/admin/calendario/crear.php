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
    'titulo'            => 'Nuevo evento',
    'subtitulo'         => 'Calendario',
    'rutaActiva'        => '/admin/calendario',
    'mostrarEncabezado' => false,
]);
?>

<header class="herr-hero herr-hero--compacta">
    <span class="herr-hero__icono" aria-hidden="true">
        <span class="material-symbols-outlined">event</span>
    </span>
    <div class="herr-hero__texto">
        <p class="herr-hero__eyebrow">Calendario</p>
        <h1 class="herr-hero__titulo">Nuevo evento</h1>
        <p class="herr-hero__desc">
            Revisa los datos del recordatorio o evento y vuelve a guardar.
            Los campos marcados con asterisco son obligatorios.
        </p>
    </div>
    <div class="herr-hero__acciones">
        <a class="btn btn--secundario" href="<?= escaparValores($regreso) ?>">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span>Volver al calendario</span>
        </a>
    </div>
</header>

<div class="herr-form-layout">
    <section class="herr-panel">
        <div class="herr-panel__cabecera">
            <span class="herr-panel__icono" aria-hidden="true">
                <span class="material-symbols-outlined">edit_calendar</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Datos del evento</h2>
                <p class="herr-panel__sub">Se añadirá al calendario compartido del equipo.</p>
            </div>
        </div>
        <div class="herr-panel__cuerpo">
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
        </div>
    </section>

    <aside class="herr-aside" aria-label="Consejos para el evento">
        <h2 class="herr-aside__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">lightbulb</span>
            Para tenerlo en cuenta
        </h2>
        <ul class="herr-aside__lista">
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                <span><strong>Hora opcional.</strong> Déjala vacía si el evento ocupa todo el día.</span>
            </li>
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">priority_high</span>
                <span><strong>Importancia.</strong> El nivel alto se destaca en el calendario y en la agenda.</span>
            </li>
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                <span><strong>Negocio.</strong> Asócialo si el evento pertenece a una empresa concreta.</span>
            </li>
        </ul>
        <p class="herr-aside__nota">
            Los marcadores de ventas, gastos, recepciones y facturas se calculan solos
            a partir de los módulos de negocio; no se editan desde aquí.
        </p>
    </aside>
</div>

<?php template('footer'); ?>

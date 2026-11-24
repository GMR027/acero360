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

$importancia = \App\EventosCalendario::datosImportancia($evento->importancia);
$negocios = \App\Empresas::paraSelector();
$nombreNegocio = '';
foreach ($negocios as $negocio) {
    if ((int) $negocio['id'] === (int) $evento->empresa_id) {
        $nombreNegocio = (string) $negocio['nombre'];
        break;
    }
}

template('header', [
    'titulo'            => 'Editar evento',
    'subtitulo'         => 'Calendario',
    'rutaActiva'        => '/admin/calendario',
    'mostrarEncabezado' => false,
]);
?>

<header class="herr-hero herr-hero--compacta">
    <span class="herr-hero__icono" aria-hidden="true">
        <span class="material-symbols-outlined">edit_calendar</span>
    </span>
    <div class="herr-hero__texto">
        <p class="herr-hero__eyebrow">Calendario</p>
        <h1 class="herr-hero__titulo">Editar evento</h1>
        <p class="herr-hero__desc">
            Modifica los datos de <strong><?= escaparValores($evento->nombre) ?></strong>.
            Los campos marcados con asterisco son obligatorios.
        </p>
        <div class="herr-hero__meta">
            <span class="badge <?= $importancia['clase'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $importancia['icono'] ?></span>
                <?= escaparValores($importancia['etiqueta']) ?>
            </span>
            <span class="herr-meta">
                <span class="material-symbols-outlined" aria-hidden="true">event</span>
                <?= escaparValores(formatear_fecha($evento->fecha, 'd/m/Y')) ?>
            </span>
            <?php if (trim((string) $evento->hora) !== '') : ?>
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                    <?= escaparValores(substr((string) $evento->hora, 0, 5)) ?>
                </span>
            <?php endif; ?>
        </div>
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
                <p class="herr-panel__sub">Los cambios se verán al volver al calendario.</p>
            </div>
        </div>
        <div class="herr-panel__cuerpo">
            <form class="formulario" action="/admin/calendario/editar" method="post" data-validar novalidate>
                <input type="hidden" name="id" value="<?= (int) $evento->id ?>">
                <?php template('formularioCalendario', [
                    'evento'       => $evento,
                    'errores'      => $errores,
                    'rutaCancelar' => $regreso,
                    'idMensaje'    => 'errores-calendario',
                ]); ?>
            </form>
        </div>
    </section>

    <aside class="herr-aside" aria-label="Resumen del evento">
        <h2 class="herr-aside__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">info</span>
            Resumen
        </h2>
        <div class="herr-aside__meta">
            <p class="herr-aside__dato">
                <span>Fecha</span>
                <strong><?= escaparValores(formatear_fecha($evento->fecha, 'd/m/Y')) ?></strong>
            </p>
            <p class="herr-aside__dato">
                <span>Hora</span>
                <strong><?= trim((string) $evento->hora) !== '' ? escaparValores(substr((string) $evento->hora, 0, 5)) : 'Todo el día' ?></strong>
            </p>
            <p class="herr-aside__dato">
                <span>Importancia</span>
                <strong><?= escaparValores($importancia['etiqueta']) ?></strong>
            </p>
            <p class="herr-aside__dato">
                <span>Negocio</span>
                <strong><?= $nombreNegocio !== '' ? escaparValores($nombreNegocio) : 'Sin negocio' ?></strong>
            </p>
        </div>
        <p class="herr-aside__nota">
            Para quitar el evento del calendario, usa el botón de eliminar de la agenda.
        </p>
    </aside>
</div>

<?php template('footer'); ?>

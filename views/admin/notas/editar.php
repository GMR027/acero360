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

$categoria = \App\Notas::datosCategoria($nota->categoria);

template('header', [
    'titulo'            => 'Editar nota',
    'subtitulo'         => 'Notas personales',
    'rutaActiva'        => '/admin/notas',
    'mostrarEncabezado' => false,
]);
?>

<header class="herr-hero herr-hero--compacta">
    <span class="herr-hero__icono" aria-hidden="true">
        <span class="material-symbols-outlined">edit_note</span>
    </span>
    <div class="herr-hero__texto">
        <p class="herr-hero__eyebrow">Notas personales</p>
        <h1 class="herr-hero__titulo">Editar nota</h1>
        <p class="herr-hero__desc">Modifica <strong><?= escaparValores($nota->titulo) ?></strong>.</p>
        <div class="herr-hero__meta">
            <span class="badge <?= $categoria['clase'] ?>">
                <span class="material-symbols-outlined" aria-hidden="true" style="font-size:0.75rem"><?= $categoria['icono'] ?></span>
                <?= $categoria['etiqueta'] ?>
            </span>
            <span class="herr-meta">
                <span class="material-symbols-outlined" aria-hidden="true">person</span>
                <?= escaparValores(trim((string) $nota->autor) !== '' ? $nota->autor : 'Administrador') ?>
            </span>
            <?php if ($nota->creado_en) : ?>
                <span class="herr-meta">
                    <span class="material-symbols-outlined" aria-hidden="true">history</span>
                    Creada el <?= escaparValores(formatear_fecha($nota->creado_en, 'd/m/Y H:i')) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="herr-hero__acciones">
        <a class="btn btn--secundario" href="/admin/notas">
            <span class="material-symbols-outlined" aria-hidden="true" style="font-size:1.125rem">arrow_back</span>
            <span>Volver al tablero</span>
        </a>
    </div>
</header>

<div class="herr-form-layout">
    <section class="herr-panel">
        <div class="herr-panel__cabecera">
            <span class="herr-panel__icono" aria-hidden="true">
                <span class="material-symbols-outlined">edit</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Datos de la nota</h2>
                <p class="herr-panel__sub">Los cambios se reflejan al instante en el tablero compartido.</p>
            </div>
        </div>
        <div class="herr-panel__cuerpo">
            <form class="formulario" action="/admin/notas/editar?id=<?= (int) $nota->id ?>" method="post" data-validar novalidate>
                <?php template('formularioNotas', ['nota' => $nota, 'errores' => $errores]); ?>
            </form>
        </div>
    </section>

    <aside class="herr-aside" aria-label="Resumen de la nota">
        <h2 class="herr-aside__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">info</span>
            Resumen
        </h2>
        <div class="herr-aside__meta">
            <p class="herr-aside__dato">
                <span>Categoría</span>
                <strong><?= $categoria['etiqueta'] ?></strong>
            </p>
            <p class="herr-aside__dato">
                <span>Estado</span>
                <strong><?= (int) $nota->completada === 1 ? 'Completada' : 'Activa' ?></strong>
            </p>
            <p class="herr-aside__dato">
                <span>Prioridad</span>
                <strong><?= (int) $nota->fijada === 1 ? 'Fijada arriba' : 'Normal' ?></strong>
            </p>
            <p class="herr-aside__dato">
                <span>Autor</span>
                <strong><?= escaparValores(trim((string) $nota->autor) !== '' ? $nota->autor : 'Administrador') ?></strong>
            </p>
            <?php if (trim((string) $nota->fecha_recordatorio) !== '') : ?>
                <p class="herr-aside__dato">
                    <span>Recordatorio</span>
                    <strong><?= escaparValores($nota->fecha_recordatorio) ?></strong>
                </p>
            <?php endif; ?>
        </div>
        <p class="herr-aside__nota">
            Para marcar la nota como hecha, usa el botón de verificación en el tablero.
            El historial de cambios no se conserva.
        </p>
    </aside>
</div>

<?php template('footer'); ?>

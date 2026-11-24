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
    'titulo'            => 'Nueva nota',
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
        <h1 class="herr-hero__titulo">Nueva nota</h1>
        <p class="herr-hero__desc">
            Anota un recordatorio, algo por cotizar, un cambio o una revisión.
            Los campos marcados con asterisco son obligatorios.
        </p>
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
                <span class="material-symbols-outlined">sticky_note_2</span>
            </span>
            <div class="herr-panel__texto">
                <h2 class="herr-panel__titulo">Datos de la nota</h2>
                <p class="herr-panel__sub">Se guardará en la bitácora compartida del equipo.</p>
            </div>
        </div>
        <div class="herr-panel__cuerpo">
            <form class="formulario" action="/admin/notas/crear" method="post" data-validar novalidate>
                <?php template('formularioNotas', ['nota' => $nota, 'errores' => $errores]); ?>
            </form>
        </div>
    </section>

    <aside class="herr-aside" aria-label="Consejos para redactar la nota">
        <h2 class="herr-aside__titulo">
            <span class="material-symbols-outlined" aria-hidden="true">lightbulb</span>
            Antes de guardar
        </h2>
        <ul class="herr-aside__lista">
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">short_text</span>
                <span><strong>Sé concreto.</strong> Un título corto ayuda a encontrar la nota después.</span>
            </li>
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">sell</span>
                <span><strong>Usa la categoría.</strong> Los filtros del tablero se basan en ella.</span>
            </li>
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">link</span>
                <span><strong>Asocia el contexto.</strong> Escribe la referencia como <em>Cliente: nombre</em> para resaltar la etiqueta.</span>
            </li>
            <li class="herr-aside__item">
                <span class="material-symbols-outlined" aria-hidden="true">push_pin</span>
                <span><strong>Fija lo urgente.</strong> Las notas fijadas permanecen arriba del tablero.</span>
            </li>
        </ul>
        <p class="herr-aside__nota">
            La bitácora es compartida: cualquier administrador puede leer y actualizar esta nota.
            El nombre de quien la redacta queda registrado.
        </p>
    </aside>
</div>

<?php template('footer'); ?>

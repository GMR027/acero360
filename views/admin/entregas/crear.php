<?php
/**
 * views/admin/entregas/crear.php — Crea una preparación con las ventas elegidas (POST).
 *
 * Si alguna venta ya está en otra preparación activa, NO se crea nada: se
 * advierte y se pide confirmación explícita («Agregar de todos modos»).
 */

soloAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paginaNoEncontrada();
}

$ventaIds = array_map('intval', (array) ($_POST['ventas'] ?? []));
$forzar   = !empty($_POST['forzar']);
$notas    = (string) ($_POST['notas'] ?? '');

if (\App\Entregas::crearPreparacion($ventaIds, $notas, (int) ($_SESSION['id'] ?? 0), $forzar)) {
    redirigir('/admin/entregas/detalle?id=' . \App\Entregas::$ultimoId, 'exito', 'Preparación creada. El inventario no se modificó.');
}

$conflictos = \App\Entregas::$conflictos;
$errores    = \App\Entregas::getErrores();

if (!$conflictos) {
    redirigir('/admin/entregas', 'error', $errores[0] ?? 'No fue posible crear la preparación.');
}

template('header', ['titulo' => 'Ventas ya en preparación', 'subtitulo' => 'Confirma antes de continuar', 'rutaActiva' => '/admin/entregas']);
?>

<section class="tarjeta">
    <p class="nota nota--advertencia mb-lg" role="alert">
        <span class="material-symbols-outlined nota__icono" aria-hidden="true">warning</span>
        <span><strong>Estas ventas ya forman parte de otra preparación activa.</strong>
            Agregarlas de nuevo puede hacer que se preparen o se entreguen dos veces.</span>
    </p>
    <ul>
        <?php foreach ($conflictos as $c) : ?>
            <li><strong><?= escaparValores($c['venta']) ?></strong> ya está en <?= escaparValores($c['preparaciones']) ?></li>
        <?php endforeach; ?>
    </ul>

    <form class="formulario mt-lg" action="/admin/entregas/crear" method="post">
        <?php foreach ($ventaIds as $id) : ?><input type="hidden" name="ventas[]" value="<?= $id ?>"><?php endforeach; ?>
        <input type="hidden" name="notas" value="<?= escaparValores($notas) ?>">
        <input type="hidden" name="forzar" value="1">
        <div class="formulario__acciones">
            <a class="btn btn--secundario" href="/admin/entregas">Volver y revisar</a>
            <button class="btn btn--peligro" type="submit">Agregar de todos modos</button>
        </div>
    </form>
</section>

<?php template('footer'); ?>

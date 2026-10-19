<?php
/**
 * views/404.php — Página no encontrada.
 * Se muestra cuando el Router no encuentra coincidencia para la URL pedida.
 */

http_response_code(404);

sesionActiva();

$esRutaProtegida = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') === 0;

template('header', [
    'titulo'         => 'Página no encontrada',
    'mostrarSidebar' => $esRutaProtegida && (int) ($_SESSION['rol'] ?? 0) === 1,
]);
?>

<section class="tarjeta">
    <div class="vacio">
        <span class="material-symbols-outlined vacio__icono" aria-hidden="true">search_off</span>
        <p class="vacio__titulo">404 · Página no encontrada</p>
        <p>La dirección que solicitaste no existe o fue movida.</p>

        <div class="fila fila--envuelto" style="justify-content:center; margin-top:1rem">
            <?php if ($esRutaProtegida && (int) ($_SESSION['rol'] ?? 0) === 1) : ?>
                <a class="btn btn--primario" href="/admin">Volver al panel</a>
            <?php else : ?>
                <a class="btn btn--primario" href="/login">Ir al inicio de sesión</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php template('footer'); ?>

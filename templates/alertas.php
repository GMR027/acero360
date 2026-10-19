<?php
/**
 * templates/alertas.php — Contenedor de alertas visuales.
 *
 * Pinta en el servidor las alertas pendientes (flash de sesión) que quedaron
 * al redirigir tras un POST. El botón de cierre y el auto-cierre los añade
 * JavaScript (modulos/alertas.js); si el script no carga, las alertas siguen
 * siendo legibles porque el marcado ya está en el DOM.
 *
 * Nunca se usa alert(), confirm() ni prompt().
 */

$alertas = obtenerAlertas();
?>
<div class="alertas" id="alertas-contenedor" aria-live="polite" aria-atomic="false">
    <?php foreach ($alertas as $alerta) : //lo que hace es iterar sobre cada alerta pendiente y generar su HTML correspondiente
        $tipo  = in_array($alerta['tipo'], ['exito', 'error', 'advertencia', 'info'], true) //verifica si el tipo de alerta es válido
            ? $alerta['tipo']
            : 'info';
        $icono = [
            'exito'       => '✓',
            'error'       => '✕',
            'advertencia' => '!',
            'info'        => 'i',
        ][$tipo];
        ?>
        <div class="alerta alerta--<?= $tipo ?>" role="alert">
            <span class="alerta__icono" aria-hidden="true"><?= $icono ?></span>
            <div class="alerta__contenido">
                <p class="alerta__texto"><?= escaparValores($alerta['texto']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</div>

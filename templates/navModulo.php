<?php
/**
 * templates/navModulo.php — Barra de navegación interna de un módulo.
 * Variables:
 *   $secciones — array clave => [ruta, texto, icono, (título largo opcional)]
 *   $tab       — clave de la sección activa
 *   $etiqueta  — nombre accesible de la barra
 *
 * La última pestaña queda centrada en el teléfono y la barra se desliza.
 */
$etiqueta = $etiqueta ?? 'Secciones';
?>
<nav class="modulo-nav" aria-label="<?= escaparValores($etiqueta) ?>">
    <div class="modulo-nav__pista" data-modulo-nav>
        <?php foreach ($secciones as $clave => $seccion) :
            [$ruta, $texto, $icono] = $seccion;
            $tituloLargo = (string) ($seccion[3] ?? '');
            $activo = $tab === $clave; ?>
            <a class="modulo-nav__tab<?= $activo ? ' modulo-nav__tab--activo' : '' ?>"
               href="<?= escaparValores($ruta) ?>"<?= $activo ? ' aria-current="page"' : '' ?>
               <?= $tituloLargo !== '' ? 'title="' . escaparValores($tituloLargo) . '"' : '' ?>>
                <span class="material-symbols-outlined" aria-hidden="true"><?= $icono ?></span>
                <span><?= escaparValores($texto) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<script>
/* En el teléfono la barra se desliza: deja visible la pestaña de la página
   y la vuelve a centrar si cambia el tamaño de la ventana. */
(function () {
    const pista = document.querySelector('[data-modulo-nav]');
    const activo = pista && pista.querySelector('[aria-current="page"]');
    if (!pista || !activo) { return; }

    function centrar() {
        if (pista.scrollWidth <= pista.clientWidth) { return; }
        pista.scrollLeft = Math.max(0, activo.offsetLeft - (pista.clientWidth - activo.offsetWidth) / 2);
    }

    centrar();
    window.addEventListener('resize', centrar);
})();
</script>

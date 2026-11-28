<?php
/**
 * templates/pdfOverlay.php — Capa de progreso de las exportaciones PDF.
 *
 * La controla src/js/modulos/pdfExport.js. Se muestra mientras el servidor
 * compone el documento y mientras el navegador recibe los bytes; al terminar
 * se oculta y el PDF se abre automáticamente.
 */
?>
<div class="pdf-capa" id="pdf-capa" hidden>
    <div class="pdf-capa__panel" role="dialog" aria-modal="true"
         aria-labelledby="pdf-capa-titulo" aria-describedby="pdf-capa-detalle">
        <span class="pdf-capa__icono material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>
        <h2 class="pdf-capa__titulo" id="pdf-capa-titulo">Generando PDF…</h2>
        <p class="pdf-capa__detalle" id="pdf-capa-detalle">Preparando el documento.</p>

        <div class="pdf-capa__progreso" id="pdf-capa-progreso" role="progressbar"
             aria-label="Avance de la exportación" aria-valuemin="0" aria-valuemax="100">
            <span class="pdf-capa__barra" id="pdf-capa-barra"></span>
        </div>

        <p class="pdf-capa__tiempo" id="pdf-capa-tiempo">0 s</p>

        <button class="btn btn--secundario btn--sm" type="button" id="pdf-capa-cancelar">
            Cancelar
        </button>
    </div>
</div>

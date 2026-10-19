<?php
/**
 * templates/modal.php — Ventana modal única de la aplicación.
 *
 * Todos los diálogos de la web (confirmaciones, vistas rápidas) usan este
 * mismo nodo del DOM para que todas las ventanas modales tengan exactamente
 * el mismo diseño. El contenido lo inyecta src/js/modulos/modal.js con
 * document.createElement + appendChild.
 *
 * No debe incluirse más de una vez por página (footer.php ya lo hace).
 */
?>
<div class="modal" id="modal-principal" role="dialog" aria-modal="true" aria-labelledby="modal-titulo" hidden>
    <div class="modal__panel">
        <header class="modal__cabecera">
            <div class="modal__contexto">
                <span class="modal__sobre-titulo" id="modal-sobre-titulo" hidden></span>
                <h2 class="modal__titulo" id="modal-titulo">Detalle</h2>
            </div>
            <button type="button" class="modal__cerrar" data-cerrar-modal aria-label="Cerrar ventana">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </header>

        <div class="modal__cuerpo" id="modal-cuerpo"></div>

        <footer class="modal__pie" id="modal-pie">
            <button type="button" class="btn btn--secundario" data-cerrar-modal>Cerrar</button>
        </footer>
    </div>
</div>

/**
 * modulos/boveda.js
 * ---------------------------------------------------------------------------
 * Retira del DOM la animación de bienvenida (templates/boveda.php) cuando termina.
 * La animación misma es CSS (_boveda.scss) y ya acaba oculta; esto sólo libera
 * el nodo y, por si un navegador no emite animationend, lo quita a los 5 s.
 */
(function () {
  'use strict';

  function prepararBoveda() {
    const boveda = document.querySelector('[data-boveda]');
    if (!boveda) {
      return;
    }
    const retirar = function () {
      if (boveda.isConnected) {
        boveda.remove();
      }
    };
    boveda.addEventListener('animationend', function (evento) {
      if (evento.target === boveda && evento.animationName === 'boveda-salida') {
        retirar();
      }
    });
    window.setTimeout(retirar, 5000);
  }

  window.prepararBoveda = prepararBoveda;
})();
